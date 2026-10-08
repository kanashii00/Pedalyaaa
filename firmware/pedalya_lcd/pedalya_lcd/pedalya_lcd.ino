#include <Arduino.h>
#include <math.h>
#include <Wire.h>
#include "AccidentDetector.h"
#include "BuzzerAlarm.h"
#include "CommandHandler.h"
#include "Diagnostics.h"
#include "Geofence.h"
#include "GpsReceiver.h"
#include "LcdDisplay.h"
#include "LockActuator.h"
#include "PedalyaApi.h"
#include "RentalController.h"
#include "RentalTimer.h"
#include "Sim7600.h"
#include "config.h"
#include <WiFi.h>
/*
 * One part per tab. Each module owns its own hardware and its own decision, and
 * the sketch below only wires them together and runs the loop:
 *
 *   LcdDisplay      16x2 I2C screen
 *   LockActuator    the servo on SERVO_PIN
 *   BuzzerAlarm     every sound, the only writer of BUZZER_PIN
 *   RentalTimer     the overdue clock, grace window and projected penalty
 *   RentalController expiry state machine and the actuator safety rule
 *   CommandHandler  the server command queue
 *   GpsReceiver     checksummed NMEA RMC reader
 *   AccidentDetector the MPU6050 on the shared I2C bus, and its verdict only
 *   Sim7600         the SIM7600CE modem on UART2: a second route to the API,
 *                   and nothing else (the ESP32 keeps every decision)
 *   PedalyaApi      WiFi + cellular HTTP, JSON, clock
 *   Diagnostics     the boot banner
 *   config.h        every pin, path and timing, overridable from secrets.h
 */

static PedalyaApi api;
static LcdDisplay display;
static LockActuator lock;
static BuzzerAlarm buzzer;
static RentalTimer timer;
static RentalController controller(lock, buzzer, timer);
static CommandHandler commands(display, lock, controller, api);
static GpsReceiver gps;
static AccidentDetector accidentDetector;

// The single polled snapshot of the rental. Every module reads it; nothing else
// keeps a second copy.
static RentalState state;

/* ------------------------------------------------------------------------
 *  Accident reporting
 *  --------------------
 *  AccidentDetector owns the MPU6050 and decides when the motion looks like a
 *  crash. Everything below is only about telling somebody: the rider on the
 *  LCD, the operator on the Serial Monitor, and the admin on the backend.
 *
 *  Deliberately absent: any write to SERVO_PIN. A crash is a report, not a
 *  decision. The admin is notified first and chooses whether the bike is
 *  locked, so the rider is never trapped by firmware reacting on its own.
 *
 *  The GPS snapshot is taken at the instant the crash is seen rather than being
 *  read later, so the fix attached to the report is the one that was current
 *  when the bike went down.
 * ------------------------------------------------------------------------ */

/** An accident report waiting to be delivered, or delivered. */
struct PendingAccident {
    bool active = false;
    bool hasFix = false;
    double latitude = 0.0;
    double longitude = 0.0;
    float impactG = 0.0f;
    float rotationDps = 0.0f;
    float tiltDegrees = 0.0f;
    float accelXG = 0.0f;
    float accelYG = 0.0f;
    float accelZG = 0.0f;
    uint32_t attempts = 0;
    uint32_t nextAttemptMs = 0;
};

static PendingAccident pendingAccident;
static uint32_t lastAccidentTraceMs = 0;

/**
 * Latch a detected crash for delivery.
 *
 * The peaks are read before acknowledge() so they survive the release. A second
 * crash arriving while a report is still in flight merges into it rather than
 * being dropped: one admin alert is better than two alerts for one incident,
 * and the worse of the two readings is the one worth keeping.
 */
static void queueAccidentReport(const AccidentEvent& event) {
    if (pendingAccident.active) {
        pendingAccident.impactG = max(pendingAccident.impactG, event.peakMagnitudeG);
        pendingAccident.rotationDps = max(pendingAccident.rotationDps, event.peakRotationDps);
        pendingAccident.tiltDegrees = max(pendingAccident.tiltDegrees, event.peakTiltDegrees);
        Serial.println("[accident] second event merged into the report in flight");
        return;
    }

    pendingAccident = {};
    pendingAccident.active = true;
    pendingAccident.impactG = event.peakMagnitudeG;
    pendingAccident.rotationDps = event.peakRotationDps;
    pendingAccident.tiltDegrees = event.peakTiltDegrees;
    pendingAccident.accelXG = event.accelXG;
    pendingAccident.accelYG = event.accelYG;
    pendingAccident.accelZG = event.accelZG;

    // Read the fix now. GPS_FIX_STALE_MS has already retired any fix that
    // stopped being updated, so hasFreshFix() means this position is current.
    if (gps.hasFreshFix()) {
        pendingAccident.hasFix = true;
        pendingAccident.latitude = gps.fix().latitude;
        pendingAccident.longitude = gps.fix().longitude;
    }
}

/**
 * Why the detector will or will not report a crash, in a few words.
 *
 * Shown on the periodic trace because a detector serving out its quiet period is
 * indistinguishable from a dead sensor if it says nothing, and the first
 * question anyone asks is whether the thing is switched on.
 */
static const char* accidentArmNote() {
    switch (accidentDetector.armState()) {
        case AccidentArmState::SensorMissing:
            return "  [SENSOR MISSING]";
        case AccidentArmState::Armed:
            return "  [armed]";
        case AccidentArmState::Cooldown: {
            static char note[28];
            snprintf(note, sizeof(note), "  [cooldown %lus, not armed]",
                     (unsigned long)(accidentDetector.rearmRemainingMs() / 1000));
            return note;
        }
        case AccidentArmState::WaitingForCalm:
            return "  [cooldown done - set it down still to re-arm]";
        case AccidentArmState::Settling: {
            static char note[28];
            snprintf(note, sizeof(note), "  [settling %lus]",
                     (unsigned long)(accidentDetector.rearmRemainingMs() / 1000));
            return note;
        }
    }
    return "";
}

/**
 * Sample the IMU and, if it fires, announce the crash on the LCD and the Serial
 * Monitor and queue it for the backend.
 */
static void pollAccidentDetection() {
    accidentDetector.poll();

#if ACCIDENT_SERIAL_TRACE
    const uint32_t now = millis();
    if (now - lastAccidentTraceMs >= ACCIDENT_TRACE_INTERVAL_MS) {
        lastAccidentTraceMs = now;
        const AccidentReading& sample = accidentDetector.reading();
        Serial.printf("[mpu] a=%.2f,%.2f,%.2f g  |a|=%.2f g  w=%.0f dps  tilt=%.0f deg%s%s\n",
                      (double)sample.accelXG, (double)sample.accelYG, (double)sample.accelZG,
                      (double)sample.magnitudeG, (double)sample.gyroMagnitudeDps,
                      (double)sample.tiltDegrees,
                      accidentDetector.present() ? "" : "  [sensor missing]",
                      accidentArmNote());
    }
#endif

    if (!accidentDetector.triggered()) return;

    const AccidentEvent event = accidentDetector.event();
    accidentDetector.acknowledge();

    Serial.println("POSSIBLE ACCIDENT");
    Serial.printf("[accident] peak %.2f g, %.0f dps, %.0f deg from upright\n",
                  (double)event.peakMagnitudeG, (double)event.peakRotationDps,
                  (double)event.peakTiltDegrees);
    if (!gps.hasFreshFix()) {
        Serial.println("[accident] no valid GPS fix; the report will carry no location");
    }

    // Takes the screen over until the window closes; paintScreen() yields to it.
    display.showMessageFor(ACCIDENT_LCD_HOLD_MS, "POSSIBLE ACCIDENT", "SENDING TO ADMIN");
    queueAccidentReport(event);
}

/**
 * Deliver a queued accident report, retrying until the backend accepts it.
 *
 * Offline time is not counted as an attempt, so a bike that crashes with no
 * signal still reports once it reconnects rather than being written off.
 *
 * A rejected key is the one failure that is never retried. Nothing about waiting
 * makes an unprovisioned bicycle authentic, so retrying it would only spend the
 * retry budget and lose the crash. That report is abandoned with the reason
 * spelled out, because it needs a human to reissue a key.
 */
static void serviceAccidentReport() {
    if (!pendingAccident.active) return;

    const uint32_t now = millis();
    if ((int32_t)(now - pendingAccident.nextAttemptMs) < 0) return;

    if (!api.connected()) {
        // Waiting on the WiFi/SIM reconnect paths in loop(); not a failed attempt.
        pendingAccident.nextAttemptMs = now + ACCIDENT_REPORT_RETRY_MS;
        return;
    }

    if (pendingAccident.attempts >= ACCIDENT_REPORT_MAX_ATTEMPTS) {
        Serial.printf("[accident] giving up after %lu attempts; the admin was never reached\n",
                      (unsigned long)pendingAccident.attempts);
        display.showMessageFor(ACCIDENT_LCD_HOLD_MS, "ACCIDENT DETECTED", "ALERT NOT SENT");
        pendingAccident.active = false;
        return;
    }

    pendingAccident.attempts++;
    pendingAccident.nextAttemptMs = now + ACCIDENT_REPORT_RETRY_MS;

    String error;
    if (api.reportAccident(pendingAccident.latitude, pendingAccident.longitude,
                           pendingAccident.hasFix, pendingAccident.impactG,
                           pendingAccident.rotationDps, pendingAccident.tiltDegrees,
                           pendingAccident.accelXG, pendingAccident.accelYG,
                           pendingAccident.accelZG, error)) {
        Serial.printf("[accident] admin notified (attempt %lu)%s\n",
                      (unsigned long)pendingAccident.attempts,
                      pendingAccident.hasFix ? " with GPS location" : " without GPS location");
        display.showMessageFor(ACCIDENT_LCD_HOLD_MS, "ACCIDENT SENT", "ADMIN NOTIFIED");
        pendingAccident.active = false;
        return;
    }

    // 401 and 403 are answers, not faults: the server understood this device
    // and refused it, and it will refuse it identically on every later try.
    const int status = api.lastStatus();
    if (status == 401 || status == 403) {
        Serial.println("[accident] NOT SENT: this device is not authenticated.");
        Serial.printf("[accident] %s from %s. The crash was detected and logged here,\n",
                      status == 401 ? "401 Unauthorized" : "403 Forbidden",
                      ACCIDENT_REPORT_PATH);
        Serial.println("[accident] but no admin was alerted and the server has no record of it.");
        Serial.println("[accident] This is a provisioning fault, not a network one, so the");
        Serial.println("[accident] report was abandoned instead of retried. To fix it:");
        Serial.println("[accident]   1. provision a key for the bicycle in that path:");
        Serial.printf("[accident]      php artisan devices:provision-key %u\n",
                      (unsigned)ACCIDENT_BICYCLE_ID);
        Serial.println("[accident]   2. put the printed key in pedalya_lcd/secrets.h as");
        Serial.println("[accident]      DEVICE_API_KEY and reflash the bike.");
        Serial.println("[accident]   3. the report is only lost for this crash; the next one");
        Serial.println("[accident]      will be delivered once the key matches.");
        display.showMessageFor(ACCIDENT_LCD_HOLD_MS, "ACCIDENT NOT SENT", "CHECK DEVICE KEY");
        pendingAccident.active = false;
        return;
    }

    Serial.printf("[accident] report attempt %lu failed: %s; retrying in %lu ms\n",
                  (unsigned long)pendingAccident.attempts, error.c_str(),
                  (unsigned long)ACCIDENT_REPORT_RETRY_MS);
}

static const uint32_t RETRY_SECONDS = 5;
// Longest the main loop will sleep while the return countdown is playing, so a
// beep gap is never allowed to delay the servo re-assertion or the next poll.
static const uint32_t BUZZER_LOOP_MAX_SLEEP_MS = 500;

static uint32_t lastPollMs = 0;
static uint32_t lastWifiRetry = 0;
static uint32_t lastSimRetry = 0;
static uint32_t lastHeartbeatMs = 0;
static uint32_t lastGpsReportMs = 0;
static bool everSynced = false;
static bool wifiAnnounced = false;
static bool polledOnce = false;
static bool serverReachable = false;
static bool heartbeatAttempted = false;
static String lastError;

static uint32_t pollIntervalMs() { return (uint32_t)state.pollAfterSeconds * 1000UL; }

static void connectWifi() {
    api.begin();
    wifiAnnounced = false;
    lastWifiRetry = millis();
}

/**
 * Heartbeat and GPS upload.
 *
 * Only attempted while the API is reachable. A retry interval is kept even after
 * a failed POST so a bad endpoint cannot stall the control loop with repeated
 * HTTP timeouts.
 */
static void reportTelemetry() {
    const uint32_t now = millis();
    String error;

    if (!heartbeatAttempted || now - lastHeartbeatMs >= pollIntervalMs()) {
        heartbeatAttempted = true;
        lastHeartbeatMs = now;
        if (!api.sendHeartbeat(lock.locked(), lock.ready(), now / 1000UL, error)) {
            Serial.printf("[telemetry] heartbeat failed: %s\n", error.c_str());
        }
    }

    if (gps.hasFreshFix() && now - lastGpsReportMs >= GPS_REPORT_INTERVAL_MS) {
        lastGpsReportMs = now;
        const GpsFix& fix = gps.fix();
        if (!api.sendGps(fix.latitude, fix.longitude, fix.speedKph, fix.heading, error)) {
            Serial.printf("[telemetry] GPS upload failed: %s\n", error.c_str());
        }
    }
}

static void paintScreen() {
    // A message the server sent owns the screen until its window closes.
    if (display.messageActive()) return;

    const ActuatorState current = controller.state();
    if (current == ActuatorState::Expired) {
        display.showExpiredPhase(timer.seconds(), timer.penalty());
    } else if (current == ActuatorState::Warning) {
        display.showExpiryWarning(state);
    } else if (current == ActuatorState::AwaitingReturn) {
        display.showMessage("BIKE LOCKED", "RETURN PENDING");
    } else if (state.hasRental) {
        display.showRental(state);
    } else {
        display.showIdle();
    }
}

void setup() {
    Serial.begin(115200);
    delay(200);
    Serial.println("\n[boot] pedalya lcd display + actuators");

    if (STATUS_LED_PIN >= 0) {
        pinMode(STATUS_LED_PIN, OUTPUT);
        digitalWrite(STATUS_LED_PIN, STATUS_LED_ACTIVE_HIGH ? LOW : HIGH);
    }

    buzzer.begin();
    Diagnostics::printActuatorConfig();

    if (ACTUATOR_CALIBRATION_MODE) {
        lock.attach();
        lock.writePhysical(CALIBRATION_SERVO_ANGLE, "calibration-boot");
        Serial.printf("[SERVO] GPIO=%d RELEASE=%d LOCK=%d CALIBRATION=%d\n", SERVO_PIN,
                      SERVO_RELEASE_ANGLE, SERVO_LOCK_ANGLE, ACTUATOR_CALIBRATION_MODE);
        Serial.printf("[servo] CALIBRATION MODE (%d degrees)\n", CALIBRATION_SERVO_ANGLE);
        Serial.println("[servo] wheel lifted; keep the mechanical brake usable.");
    }

    display.begin();
    display.splash();
    Diagnostics::printBanner();

    gps.begin();
    simInit();
    connectWifi();

    // The IMU shares the I²C bus with the LCD, which has already claimed it in
    // display.begin(). Naming the pins here keeps the intent explicit; Wire is
    // idempotent for the same pair.
    Wire.begin(I2C_SDA_PIN, I2C_SCL_PIN);
    if (accidentDetector.begin()) {
        Serial.printf("[accident] monitoring: impact >= %.1f g or %.1f g/sample, rotation >= %.0f dps, "
                      "tilt >= %.0f deg\n",
                      (double)ACCIDENT_IMPACT_G, (double)ACCIDENT_JERK_G,
                      (double)ACCIDENT_ROTATION_DPS, (double)ACCIDENT_TILT_DEGREES);
    } else {
        // Not fatal: everything else keeps working, and the detector keeps
        // re-probing so a module fixed later is picked up without a reset.
        Serial.printf("[accident] no MPU6050 at 0x%02X; accident detection is off for now\n",
                      (unsigned)MPU6050_I2C_ADDRESS);
    }
}

void loop() {
    gps.update();

    // Accident detection runs ahead of every early return below, including the
    // offline one. A crash has to be noticed and queued whether or not the
    // server is reachable, and the queued report is retried from here too.
    pollAccidentDetection();
    serviceAccidentReport();

    const uint32_t now = millis();

    if (!api.connected()) {
        // A dead link must not relax the actuators: whatever was last enforced
        // stays enforced until the server is reachable again.
        controller.maintainDuringNetworkFailure(state);
        if (now - lastWifiRetry > WIFI_RETRY_INTERVAL_MS) connectWifi();

        // Bring up the cellular route in parallel with the Wi-Fi retry: the
        // SIM link is a second way to reach the same server, so neither wait
        // blocks the other. lastSimRetry == 0 (the first offline pass) attaches
        // immediately, so a bike that boots out of Wi-Fi range does not sit on
        // "connecting..." for a whole retry interval. Bounded by SIM_APN
        // absence (fail fast), a missing modem (one AT probe of
        // SIM_CMD_TIMEOUT_MS), or a slow attach; the worst-case blocking
        // exchange here matches the existing HTTP timeouts.
        if (SIM_ENABLED && !simIsReady() && (lastSimRetry == 0 ||
            now - lastSimRetry > SIM_RETRY_INTERVAL_MS)) {
            lastSimRetry = now;
            simConnectInternet(SIM_APN);
        }

        if (!wifiAnnounced) {
            display.showWifiConnecting();
            wifiAnnounced = true;
        }
        delay(250);
        return;
    }

    if (!everSynced) {
        display.showWifiConnecting();
        if (WiFi.status() == WL_CONNECTED) {
            Serial.print("[ip] ");
            Serial.println(WiFi.localIP());
        }
        everSynced = true;
    }

    // 1. Poll / update rental state.
    bool polledThisPass = false;
    const uint32_t interval = serverReachable ? pollIntervalMs()
                                               : (uint32_t)RETRY_SECONDS * 1000UL;
    if (!polledOnce || now - lastPollMs >= interval) {
        lastPollMs = now;
        polledOnce = true;
        polledThisPass = true;

        String error;
        serverReachable = api.poll(state, error);
        if (!serverReachable) {
            lastError = error;
            // A failed poll changes nothing: the actuators keep their last state.
            Serial.printf("[loop] poll failed: %s (actuator state preserved)\n", error.c_str());
            display.setErrorLed(true);
        } else {
            display.setErrorLed(false);
        }
    }

    if (!serverReachable) {
        // Still enforce expiry safety on the last known state, so a network blip
        // cannot unlock a timed-out wheel or silence its alarm.
        controller.maintainDuringNetworkFailure(state);
        // A rejected key is a different fault from an unreachable server, and
        // conflating them points the wrong person at the wrong problem.
        const int status = api.lastStatus();
        if (status == 401 || status == 403) {
            display.showKeyRejected(lastError);
        } else {
            display.showNoServer(lastError);
        }
        delay(500);
        return;
    }

    // 2. Attach only after a successful authenticated poll, so booting without a
    // server cannot move the physical lock.
    if (polledThisPass) lock.attachAfterServerSync();

    // 3. Apply actuator safety from the freshly polled state. The geofence is
    //    re-evaluated from the live fix on every pass: only the rental's final
    //    window uses it, and it must reflect where the bicycle is now rather
    //    than where it was at the last poll. Without a fresh fix the status is
    //    UNKNOWN, which keeps the gradual lock rather than forcing it.
    const BoundaryStatus boundary =
        evaluateBoundary(gps.hasFreshFix(), gps.fix().latitude, gps.fix().longitude);
    controller.update(state, serverReachable);
    controller.enforce(state, boundary);

    // 4. Process server commands.
    if (polledThisPass) commands.pollAndExecute(state);

    // 5. Apply actuator safety AGAIN, so no command can leave a timed-out wheel
    //    unlocked or silent, whatever it asked for.
    controller.enforce(state, boundary);

    reportTelemetry();
    paintScreen();

    // The countdown beeps are timed in milliseconds. Sleep to the next beep edge
    // so the pattern stays exact, but never longer than this: actuator safety in
    // enforce() and the rental poll both re-evaluate on every pass, and neither
    // may be starved for a whole beep gap. Everything else in the loop is
    // second-scale, so this only matters while the beeps play.
    if (buzzer.patternRunning()) {
        uint32_t sleepMs = buzzer.nextEdgeInMs();
        // Never delay(0): a misconfigured zero gap must not become a hot loop.
        if (sleepMs == 0) sleepMs = 1;
        delay(sleepMs < BUZZER_LOOP_MAX_SLEEP_MS ? sleepMs : BUZZER_LOOP_MAX_SLEEP_MS);
    } else {
        delay(200);
    }
}