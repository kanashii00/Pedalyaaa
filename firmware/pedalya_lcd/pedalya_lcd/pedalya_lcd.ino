#include <Arduino.h>
#include <math.h>
#include <Wire.h>
#include <string.h>
#include "AccidentDetector.h"
#include "BuzzerAlarm.h"
#include "CommandHandler.h"
#include "Diagnostics.h"
#include "Geofence.h"
#include "GpsReceiver.h"
#include "HallSpeedSensor.h"
#include "LcdDisplay.h"
#include "LockActuator.h"
#include "PedalyaApi.h"
#include "RentalController.h"
#include "RentalTimer.h"
#include "Sim7600.h"
#include "EC20FDiagnostic.h"
#include "config.h"
#include <WiFi.h>
#include <esp_system.h>
/*
 * One part per tab. Each module owns its own hardware and its own decision, and
 * the sketch below only wires them together and runs the loop:
 *
 *   LcdDisplay      16x2 I2C screen
 *   LockActuator    the servo on SERVO_PIN
 *   BuzzerAlarm     every sound, the only writer of BUZZER_PIN
 *   RentalTimer     the overdue clock and grace window for the return alarm
 *   RentalController expiry state machine and the actuator safety rule
 *   CommandHandler  the server command queue
 *   GpsReceiver     checksummed NMEA RMC reader
 *   HallSpeedSensor interrupt-driven wheel rotation and speed
 *   AccidentDetector the MPU6050 on the shared I2C bus, and its verdict only
 *   Sim7600/EC20F   Quectel EC20F modem on UART2: cellular route to API
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
static HallSpeedSensor hallSpeed;
static AccidentDetector accidentDetector;

// The single polled snapshot of the rental. Every module reads it; nothing else
// keeps a second copy.
static RentalState state;
static void setNetworkWait(bool waiting);

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
    AccidentReportContext context;
    uint32_t attempts = 0;
    uint32_t nextAttemptMs = 0;
};

static PendingAccident pendingAccident;
static SensorHistory sensorHistory;
static uint32_t lastSensorHistoryMs = 0;
static bool sensorHistoryStarted = false;
struct CountdownDisplaySnapshot {
    RentalState rental;
    bool locked = false;
    bool accidentPending = false;
    bool accidentHasFix = false;
};

static CountdownDisplaySnapshot countdownDisplaySnapshot;
static SemaphoreHandle_t countdownSnapshotMutex = nullptr;
static volatile bool networkWaitInProgress = false;
static bool countdownDisplayTaskStarted = false;
#if ACCIDENT_ENABLED
static uint32_t lastAccidentTraceMs = 0;
#endif

static void setNetworkWait(bool waiting) {
    if (!countdownDisplayTaskStarted || countdownSnapshotMutex == nullptr) return;

    if (waiting && xSemaphoreTake(countdownSnapshotMutex, pdMS_TO_TICKS(50)) == pdTRUE) {
        countdownDisplaySnapshot.rental = state;
        countdownDisplaySnapshot.locked = lock.locked();
        countdownDisplaySnapshot.accidentPending = pendingAccident.active;
        countdownDisplaySnapshot.accidentHasFix = pendingAccident.context.hasGpsFix;
        xSemaphoreGive(countdownSnapshotMutex);
    }

    networkWaitInProgress = waiting;
}

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
        pendingAccident.context.event.peakMagnitudeG =
            max(pendingAccident.context.event.peakMagnitudeG, event.peakMagnitudeG);
        pendingAccident.context.event.peakRotationDps =
            max(pendingAccident.context.event.peakRotationDps, event.peakRotationDps);
        pendingAccident.context.event.peakTiltDegrees =
            max(pendingAccident.context.event.peakTiltDegrees, event.peakTiltDegrees);
        Serial.println("[accident] second event merged into the report in flight");
        return;
    }

    pendingAccident = {};
    pendingAccident.active = true;
    pendingAccident.context.event = event;
    pendingAccident.context.eventAtMs = event.detectedAtMs;
    pendingAccident.context.hallSpeed = hallSpeed.reading();
    pendingAccident.context.historyCount = sensorHistory.copyRecent(
        pendingAccident.context.history, SENSOR_HISTORY_CAPACITY);
    for (int i = (int)pendingAccident.context.historyCount - 1; i >= 0; --i) {
        const HallSpeedReading& previous =
            pendingAccident.context.history[i].hall;
        if (previous.speedAvailable) {
            pendingAccident.context.hallSpeedBefore = previous;
            pendingAccident.context.hasHallSpeedBefore = true;
            break;
        }
    }
    snprintf(pendingAccident.context.eventId, sizeof(pendingAccident.context.eventId),
             "%08lx%08lx%08lx%08lx",
             (unsigned long)esp_random(), (unsigned long)esp_random(),
             (unsigned long)esp_random(), (unsigned long)esp_random());
    api.formatTimestampAtUptime(
        pendingAccident.context.eventAtMs,
        pendingAccident.context.eventTimestamp,
        sizeof(pendingAccident.context.eventTimestamp));

    // Keep stale fixes as explicitly stale context, never as the event location.
    pendingAccident.context.hasGpsFix = gps.hasFreshFix();
    pendingAccident.context.gpsFixStatus = pendingAccident.context.hasGpsFix
        ? "fresh"
        : "unavailable";
    if (gps.hasFreshFix()) {
        pendingAccident.context.gpsFix = gps.fix();
        pendingAccident.context.hasLastGpsFix = true;
    } else if (gps.fix().receivedAtMs != 0) {
        pendingAccident.context.gpsFix = gps.fix();
        pendingAccident.context.hasLastGpsFix = true;
        pendingAccident.context.gpsFixStatus = "stale";
    }
}

#if ACCIDENT_ENABLED
static void recordSensorHistory() {
    const uint32_t now = millis();
    if (sensorHistoryStarted
        && now - lastSensorHistoryMs < ACCIDENT_SAMPLE_INTERVAL_MS) {
        return;
    }

    SensorHistorySample sample;
    sample.capturedAtMs = now;
    sample.hall = hallSpeed.reading();
    sample.gpsFresh = gps.hasFreshFix();
    if (sample.gpsFresh) sample.gps = gps.fix();
    sample.mpuFresh = accidentDetector.hasValidSample();
    if (sample.mpuFresh) sample.mpu = accidentDetector.reading();
    sensorHistory.record(sample);
    lastSensorHistoryMs = now;
    sensorHistoryStarted = true;
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
#endif

/**
 * Sample the IMU and, if it fires, announce the crash on the LCD and the Serial
 * Monitor and queue it for the backend.
 */
static void pollAccidentDetection() {
#if !ACCIDENT_ENABLED
    // No MPU6050 fitted on this hardware build (ACCIDENT_ENABLED 0): nothing
    // to sample and nothing to log. Queued-report delivery in
    // serviceAccidentReport() below is untouched.
    return;
#endif
    recordSensorHistory();
    accidentDetector.poll();

#if ACCIDENT_SERIAL_TRACE && ACCIDENT_ENABLED
    const uint32_t now = millis();
    if (now - lastAccidentTraceMs >= ACCIDENT_TRACE_INTERVAL_MS) {
        lastAccidentTraceMs = now;
        const AccidentReading& sample = accidentDetector.reading();
        const bool valid = accidentDetector.hasValidSample();
        Serial.printf("[mpu] a=%.2f,%.2f,%.2f g  |a|=%.2f g  w=%.0f dps  tilt=%.0f deg%s%s\n",
                      (double)sample.accelXG, (double)sample.accelYG, (double)sample.accelZG,
                      (double)sample.magnitudeG, (double)sample.gyroMagnitudeDps,
                      (double)sample.tiltDegrees,
                      valid ? "" : "  [stale/invalid reading]",
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

    queueAccidentReport(event);
    paintScreen();
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
        pendingAccident.active = false;
        return;
    }

    pendingAccident.attempts++;
    pendingAccident.nextAttemptMs = now + ACCIDENT_REPORT_RETRY_MS;

    String error;
    setNetworkWait(true);
    const bool reported = api.reportAccident(pendingAccident.context, error);
    setNetworkWait(false);
    if (reported) {
        Serial.printf("[accident] admin notified (attempt %lu)%s\n",
                      (unsigned long)pendingAccident.attempts,
                      pendingAccident.context.hasGpsFix ? " with GPS location" : " without GPS location");
        display.showMessageFor(3000, "! ACCIDENT ALERT",
                               pendingAccident.context.hasGpsFix ? "ADMIN NOTIF +GPS" : "ADMIN NOTIFIED  ");
        pendingAccident.active = false;
        paintScreen();
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
#if CONNECTIVITY_MODE == CONNECTIVITY_MODE_WIFI_HEALTH_TEST || !SIM_ONLY_MODE
static uint32_t lastWifiRetry = 0;
#endif
static uint32_t lastSimRetry = 0;
static uint32_t lastHeartbeatMs = 0;
static uint32_t lastGpsReportMs = 0;
static bool everSynced = false;
static bool polledOnce = false;
static bool serverReachable = false;
// Health-gated LCD latch. True only after the EC20F completes the read-only
// /api/health GET with HTTP 200; cleared whenever the link drops or a check
// fails. Startup, modem power-on and cellular registration alone never set
// it, so they can only ever show PEDALYA CONNECTING.
static bool serverVerified = false;
static bool heartbeatAttempted = false;
static String lastError;

static uint32_t pollIntervalMs() { return (uint32_t)state.pollAfterSeconds * 1000UL; }

static bool rentalSnapshotCurrent(const RentalState& rental) {
    if (rental.syncedAtMs == 0) return false;
    if (rental.nextPenaltyDeadlineTs > 0 &&
        rental.serverNow() >= rental.nextPenaltyDeadlineTs) {
        return false;
    }
    const uint32_t maxAgeMs = (uint32_t)rental.pollAfterSeconds * 1000UL + REQUEST_TIMEOUT_MS;
    return (uint32_t)(millis() - rental.syncedAtMs) <= maxAgeMs;
}

#if CONNECTIVITY_MODE == CONNECTIVITY_MODE_WIFI_HEALTH_TEST || !SIM_ONLY_MODE
static void connectWifi() {
    api.begin();
    lastWifiRetry = millis();
}
#endif

/**
 * Heartbeat and GPS upload.
 *
 * Only attempted while the API is reachable. A retry interval is kept even after
 * a failed POST so a bad endpoint cannot stall the control loop with repeated
 * HTTP timeouts.
 */
static void reportTelemetry() {
    static bool hallTelemetryAttempted = false;
    static uint32_t nextHallTelemetryMs = 0;
    static const char* lastHallTelemetryStatus = nullptr;
    const uint32_t now = millis();
    String error;
    const uint32_t pollInterval = pollIntervalMs();
    const HallSpeedReading hallReading = hallSpeed.reading();
    const uint32_t heartbeatInterval = min(
        pollInterval, (uint32_t)HEARTBEAT_INTERVAL_MS);

    if (!heartbeatAttempted || now - lastHeartbeatMs >= heartbeatInterval) {
        heartbeatAttempted = true;
        lastHeartbeatMs = now;
        setNetworkWait(true);
        const bool sent = api.sendHeartbeat(
            lock.locked(), lock.ready(), now / 1000UL, hallReading, error);
        setNetworkWait(false);
        if (!sent) {
            Serial.printf("[telemetry] heartbeat failed: %s\n", error.c_str());
        }
    }

    if (gps.hasFreshFix()) {
        api.cacheGpsFix(gps.fix());
    }

    const bool gpsReportDue = gps.hasFreshFix()
        && now - lastGpsReportMs >= GPS_REPORT_INTERVAL_MS;
    const bool hallMoving =
        strcmp(hallReading.status, "active") == 0
        || (strcmp(hallReading.status, "waiting") == 0
            && hallReading.hasPulse
            && hallReading.lastPulseAgeMs <= HALL_STOP_TIMEOUT_MS);
    const uint32_t hallIntervalMs = hallMoving
        ? HALL_TELEMETRY_INTERVAL_MS
        : HALL_IDLE_TELEMETRY_INTERVAL_MS;
    const bool hallStatusChanged = lastHallTelemetryStatus == nullptr
        || strcmp(hallReading.status, lastHallTelemetryStatus) != 0;
    const bool hallReportDue = !hallTelemetryAttempted
        || hallStatusChanged
        || (int32_t)(now - nextHallTelemetryMs) >= 0;

    if (gpsReportDue) {
        lastGpsReportMs = now;
        hallTelemetryAttempted = true;
        setNetworkWait(true);
        const bool sent = api.sendGps(
            gps.fix().latitude, gps.fix().longitude, gps.fix().speedKph,
            gps.fix().heading, hallReading, error);
        setNetworkWait(false);
        lastHallTelemetryStatus = hallReading.status;
        nextHallTelemetryMs = now + (sent
            ? hallIntervalMs
            : HALL_TELEMETRY_RETRY_INTERVAL_MS);
        if (!sent) {
            Serial.printf("[telemetry] GPS upload failed: %s\n", error.c_str());
        } else {
            Serial.printf("[telemetry] GPS/Hall upload accepted (HTTP %d)\n", api.lastStatus());
        }
    } else if (hallReportDue) {
        hallTelemetryAttempted = true;
        setNetworkWait(true);
        const bool sent = api.sendHallSpeed(hallReading, error);
        setNetworkWait(false);
        lastHallTelemetryStatus = hallReading.status;
        nextHallTelemetryMs = now + (sent
            ? hallIntervalMs
            : HALL_TELEMETRY_RETRY_INTERVAL_MS);
        if (!sent) {
            Serial.printf("[telemetry] Hall upload failed: %s\n", error.c_str());
        } else {
            Serial.printf("[telemetry] Hall upload accepted (HTTP %d; %s; pulses=%lu)\n",
                          api.lastStatus(), hallReading.status,
                          (unsigned long)hallReading.pulseCount);
        }
    }
}

/**
 * Truthful 16x2 LCD status state machine.
 *
 * Shows distinct states: WIFI CONNECTING -> WIFI CONNECTED -> SERVER CHECK
 * -> SERVER ONLINE / SERVER OFFLINE -> rental countdown / expiry.
 *
 * Does NOT claim SERVER ONLINE just because Wi-Fi or DNS works. Requires
 * a successful /api/health HTTP 200 (serverVerified). Row writes are
 * deduplicated in LcdDisplay::row. The separate countdown task uses a copied
 * rental snapshot while this loop waits on HTTP.
 */
static void paintScreen() {
    if (display.messageActive()) {
        return;
    }

    // 1. Safety Event (Accident) Screen
    // Only shown when an actual sensor event is detected and pending delivery.
    // If a rental is active, alternate 2s alert / 2s rental so countdown is never lost.
    if (pendingAccident.active) {
        const uint32_t now = millis();
        if (state.hasRental) {
            if (((now / 2000UL) % 2) == 0) {
                display.showAccidentAlert(false, pendingAccident.context.hasGpsFix);
                return;
            }
            // Alternate pass falls through to rental screen
        } else {
            display.showAccidentAlert(false, pendingAccident.context.hasGpsFix);
            return;
        }
    }

    // 2. Active Rental Screen (takes priority over idle screens)
    if (state.hasRental) {
        if (state.expired() || controller.rentalIsExpired(state)) {
            if (state.returnCountdownDeadlineTs > 0) {
                display.showExpiredDeadline(
                    state.returnCountdownRemainingSeconds(),
                    state.returnCountdownOverdueSeconds(),
                    state.penaltyAccrued,
                    lock.locked(),
                    rentalSnapshotCurrent(state)
                );
            } else {
                display.showExpiredPhase(
                    timer.seconds(), state.penaltyAccrued, lock.locked(),
                    rentalSnapshotCurrent(state)
                );
            }
        } else {
            display.showRental(state, lock.locked());
        }
        return;
    }

    // 3. Network & Connectivity state
    const bool wifiUp = WiFi.status() == WL_CONNECTED;
    const bool modReady = SIM_ENABLED && simIsReady();
    // Cellular is actively routing traffic only when Wi-Fi is down and API is connected via SIM
    const bool cellActive = (!wifiUp && modReady && api.connected());
    const char* serial = (state.bicycleSerial.length() > 0) ? state.bicycleSerial.c_str() : "PDY-001";

    if (serverVerified) {
        // Authoritative Ready Screen: truthful bicycle ID, connection indicators, lock state, real battery
        display.showReady(serial, wifiUp, modReady, cellActive, serverVerified,
                          lock.locked(), state.bicycleBatteryLevel, state.bicycleStatus.c_str());
        return;
    }

    // Server not verified yet (starting up, checking, or network failure)
    if (!wifiUp) {
        if (!simPresent()) {
            display.showStatus(SystemStatus::ModemInit, serial);
            return;
        }
        if (!simIsRegistered()) {
            display.showStatus(SystemStatus::WaitingForService, serial);
            return;
        }
        if (!modReady) {
            display.showStatus(SystemStatus::CellularConnecting, serial);
            return;
        }
        if (api.lastStatus() > 0 && api.lastStatus() != 200) {
            display.showStatus(SystemStatus::ApiOffline, serial);
            return;
        }
        display.showStatus(SystemStatus::ServerCheck, serial);
        return;
    }

    if (wifiUp) {
        if (api.lastStatus() > 0 && api.lastStatus() != 200) {
            display.showStatus(SystemStatus::ServerOffline, serial);
            return;
        }
        display.showStatus(SystemStatus::ServerCheck, serial);
        return;
    }

    display.showStatus(SystemStatus::WifiConnecting, serial);
}

static void countdownDisplayTask(void*) {
    for (;;) {
        if (networkWaitInProgress && countdownSnapshotMutex != nullptr &&
            xSemaphoreTake(countdownSnapshotMutex, pdMS_TO_TICKS(20)) == pdTRUE) {
            const CountdownDisplaySnapshot snapshot = countdownDisplaySnapshot;
            xSemaphoreGive(countdownSnapshotMutex);

            if (snapshot.rental.hasRental && !display.messageActive()) {
                if (!rentalSnapshotCurrent(snapshot.rental)) {
                    display.showConnectionError("DATA STALE", "RECONNECTING...");
                } else if (snapshot.accidentPending && ((millis() / 2000UL) % 2) == 0) {
                    display.showAccidentAlert(false, snapshot.accidentHasFix);
                } else if (snapshot.rental.expired()) {
                    if (snapshot.rental.returnCountdownDeadlineTs > 0) {
                        display.showExpiredDeadline(
                            snapshot.rental.returnCountdownRemainingSeconds(),
                            snapshot.rental.returnCountdownOverdueSeconds(),
                            snapshot.rental.penaltyAccrued,
                            snapshot.locked,
                            rentalSnapshotCurrent(snapshot.rental)
                        );
                    } else {
                        display.showExpiredPhase(
                            snapshot.rental.serverOverdueSeconds,
                            snapshot.rental.penaltyAccrued,
                            snapshot.locked,
                            rentalSnapshotCurrent(snapshot.rental)
                        );
                    }
                } else {
                    display.showRental(snapshot.rental, snapshot.locked);
                }
            }
        }

        vTaskDelay(pdMS_TO_TICKS(200));
    }
}

static void beginCountdownDisplayTask() {
    if (!display.concurrentRenderReady()) {
        Serial.println("[lcd] concurrent countdown refresh unavailable; display remains loop-driven");
        return;
    }

    countdownSnapshotMutex = xSemaphoreCreateMutex();
    if (countdownSnapshotMutex == nullptr) {
        Serial.println("[lcd] failed to create countdown snapshot mutex");
        return;
    }

    const BaseType_t created = xTaskCreatePinnedToCore(
        countdownDisplayTask,
        "rental-lcd",
        4096,
        nullptr,
        1,
        nullptr,
        0
    );
    if (created == pdPASS) {
        countdownDisplayTaskStarted = true;
        Serial.println("[lcd] independent 200 ms countdown refresh enabled");
    } else {
        Serial.println("[lcd] failed to start independent countdown refresh task");
        vSemaphoreDelete(countdownSnapshotMutex);
        countdownSnapshotMutex = nullptr;
    }
}

#if CONNECTIVITY_MODE == CONNECTIVITY_MODE_WIFI_HEALTH_TEST
static bool wifiTestStatusKnown = false;
static bool wifiTestWasConnected = false;
static bool wifiTestHealthAttempted = false;
static uint32_t lastWifiTestHealthMs = 0;

static void serviceWifiHealthTest() {
    const uint32_t now = millis();
    const bool wifiConnected = WiFi.status() == WL_CONNECTED;

    if (!wifiTestStatusKnown || wifiConnected != wifiTestWasConnected) {
        wifiTestStatusKnown = true;
        wifiTestWasConnected = wifiConnected;
        Serial.printf("[wifi-test] Wi-Fi connection status: %s\n",
                      wifiConnected ? "CONNECTED" : "DISCONNECTED");
        if (!wifiConnected) {
            Serial.println("[wifi-test] health HTTP status: not attempted; "
                           "server response success: NOT TESTED");
            wifiTestHealthAttempted = false;
        } else {
            wifiTestHealthAttempted = false;
        }
    }

    if (!wifiConnected) {
        serverVerified = false;
        paintScreen();
        if (lastWifiRetry == 0 || now - lastWifiRetry >= WIFI_RETRY_INTERVAL_MS) {
            connectWifi();
        }
        return;
    }

    if (!wifiTestHealthAttempted ||
        now - lastWifiTestHealthMs >= WIFI_RETRY_INTERVAL_MS) {
        wifiTestHealthAttempted = true;
        lastWifiTestHealthMs = now;

        String detail;
        const bool responseOk = api.checkHealth(detail);
        const int status = api.lastStatus();
        const bool responseSuccess = responseOk && status >= 200 && status < 300;
        serverVerified = responseSuccess;
        Serial.printf("[wifi-test] Wi-Fi connection status: CONNECTED; "
                      "health HTTP status: %d; server response success: %s (%s)\n",
                      status, responseSuccess ? "YES" : "NO", detail.c_str());
        paintScreen();
    }
}
#endif

#if CONNECTIVITY_MODE == CONNECTIVITY_MODE_EC20F_SIM
static bool cellTestStatusKnown = false;
static bool cellTestHealthAttempted = false;
static uint32_t lastCellTestHealthMs = 0;

static void serviceCellularHealthTest() {
    const uint32_t now = millis();

    // Stage 1: Modem AT communication
    if (!simPresent()) {
        serverVerified = false;
        display.showStatus(SystemStatus::ModemInit);
        if (!cellTestStatusKnown) {
            Serial.println("[sim-test] Cellular connection status: MODEM_ABSENT");
            Serial.println("[sim-test] health HTTP status: not attempted; server response success: NOT TESTED");
            cellTestStatusKnown = true;
        }
        if (lastSimRetry == 0 || now - lastSimRetry >= SIM_RETRY_INTERVAL_MS) {
            lastSimRetry = now;
            simInit();
        }
        return;
    }

    // Stage 2: SIM and Network registration
    if (!simIsRegistered()) {
        serverVerified = false;
        display.showStatus(SystemStatus::WaitingForService);
        if (!cellTestStatusKnown) {
            Serial.println("[sim-test] Cellular connection status: SEARCHING_SERVICE");
            Serial.println("[sim-test] health HTTP status: not attempted; server response success: NOT TESTED");
            cellTestStatusKnown = true;
        }
        if (lastSimRetry == 0 || now - lastSimRetry >= SIM_RETRY_INTERVAL_MS) {
            lastSimRetry = now;
            simConnectInternet(SIM_APN);
        }
        return;
    }

    // Stage 3: Packet data attachment / PDP context
    if (!simIsReady()) {
        serverVerified = false;
        display.showStatus(SystemStatus::CellularConnecting);
        if (!cellTestStatusKnown) {
            Serial.println("[sim-test] Cellular connection status: ATTACHING_DATA");
            Serial.println("[sim-test] health HTTP status: not attempted; server response success: NOT TESTED");
            cellTestStatusKnown = true;
        }
        if (lastSimRetry == 0 || now - lastSimRetry >= SIM_RETRY_INTERVAL_MS) {
            lastSimRetry = now;
            simConnectInternet(SIM_APN);
        }
        return;
    }

    // Stage 4 & 5: HTTPS request to /api/health over modem-native stack
    if (!cellTestHealthAttempted || now - lastCellTestHealthMs >= SIM_RETRY_INTERVAL_MS) {
        cellTestHealthAttempted = true;
        lastCellTestHealthMs = now;
        display.showStatus(SystemStatus::ServerCheck);

        String detail;
        const bool responseOk = api.checkHealth(detail);
        const int status = api.lastStatus();
        const bool responseSuccess = responseOk && status >= 200 && status < 300;
        serverVerified = responseSuccess;

        Serial.printf("[sim-test] Cellular connection status: CONNECTED; "
                      "health HTTP status: %d; server response success: %s (%s)\n",
                      status, responseSuccess ? "YES" : "NO", detail.c_str());

        if (responseSuccess) {
            display.showStatus(SystemStatus::CellularOnline);
        } else {
            display.showStatus(SystemStatus::ApiOffline);
        }
    }
}
#endif

/**
 * One-shot read-only boot probe of the configured EC20F route.
 *
 * Runs once at the end of setup(). It brings up the same cellular bearer the
 * loop would (bounded by the SIM_* timeouts), then performs a single
 * unauthenticated GET to HEALTH_PATH via PedalyaApi::checkHealth(). No
 * rental, lock, GPS-update or accident-report traffic is sent here. The LCD
 * latch is set strictly on HTTP 200: anything else leaves PEDALYA CONNECTING
 * on the screen. The verdict also goes to Serial; a missing device or
 * network simply logs its stage and continues.
 */
static void runBootHealthProbe() {
    const bool wifiUp = WiFi.status() == WL_CONNECTED;

    // When Wi-Fi is already associated, skip the blocking cellular bearer
    // setup: a silent EC20F costs up to SIM_NET_TIMEOUT_MS (8 s) and blocks
    // the health GET that only needs Wi-Fi. The modem is attempted only when
    // Wi-Fi is not available (i.e. it would be the only route to the server).
    if (!wifiUp && SIM_ENABLED && !simIsReady()) {
        Serial.println("[boot] Wi-Fi not up; attempting cellular bearer for health probe");
        simConnectInternet(SIM_APN);
    } else if (wifiUp && SIM_ENABLED && !simIsReady()) {
        Serial.println("[boot] Wi-Fi connected; skipping blocking cellular attach");
    }

    String verdict;
    const bool ok = api.checkHealth(verdict);
    serverVerified = (ok && api.lastStatus() == 200);
    paintScreen();
    Serial.printf("[boot] health probe %s (%s)\n", serverVerified ? "OK" : "FAILED",
                  verdict.c_str());
}

void setup() {
    Serial.begin(115200);
    delay(200);
    Serial.println("\n[boot] pedalya lcd display + actuators");
    Diagnostics::printBootDiagnostics();

    // EC20F UART diagnostic (compile-time opt-in, EC20F_UART_DIAGNOSTIC=1).
    // Runs before simInit() so Serial2 is fresh. No-op in production builds.
    runEC20FDiagnostic();

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
    display.showStartup("STARTING...", "PDY-001");
    Diagnostics::printBanner();

    gps.begin();
    hallSpeed.begin();
    Serial.printf("[hall] input GPIO=%d, pull-up enabled, timeout=%lu ms\n",
                  HALL_SENSOR_PIN, (unsigned long)HALL_STOP_TIMEOUT_MS);
#if SIM_ONLY_MODE || CONNECTIVITY_MODE == CONNECTIVITY_MODE_EC20F_SIM
    // SIM-only build: power off the Wi-Fi radio so it never broadcasts, associates,
    // or draws RF power.
    WiFi.mode(WIFI_OFF);
    Serial.println("[net] SIM-only mode: Wi-Fi radio powered off");
    simInit();
    if (simPresent()) simRunDiagnostics();
#elif CONNECTIVITY_MODE == CONNECTIVITY_MODE_WIFI_HEALTH_TEST
    Serial.println("[net] Wi-Fi health-test mode: EC20F and business requests are disabled");
    connectWifi();
#else
    // Start association before the bounded AT readiness probe. Wi-Fi runs in
    // the ESP32 networking task while the EC20F finishes its boot banner, so
    // a weak or absent modem cannot postpone Wi-Fi's first association.
    connectWifi();
    simInit();
    if (simPresent()) simRunDiagnostics();
#endif

    // The IMU shares the I²C bus with the LCD, which has already claimed it in
    // display.begin(). Naming the pins here keeps the intent explicit; Wire is
    // idempotent for the same pair.
    Wire.begin(I2C_SDA_PIN, I2C_SCL_PIN);
    // One-time bus census, so a silent sensor reads as hardware state rather
    // than a software guess: expect 0x27 (LCD). No MPU6050 is fitted on this
    // hardware build (ACCIDENT_ENABLED 0), so 0x68/0x69 are absent by design.
    // Bounded (~120 quick probes) and runs once; the loop never scans.
    Serial.print("[i2c] scan: ");
    uint8_t i2cFound = 0;
    for (uint8_t addr = 0x08; addr <= 0x77; addr++) {
        Wire.beginTransmission(addr);
        if (Wire.endTransmission(true) == 0) {
            Serial.printf("0x%02X ", addr);
            i2cFound++;
        }
    }
    if (i2cFound == 0) Serial.print("(no devices)");
    Serial.println();
#if ACCIDENT_ENABLED
    if (accidentDetector.begin()) {
        Serial.printf("[accident] MPU6050 answering at 0x%02X\n",
                      accidentDetector.address());
        Serial.printf("[accident] monitoring: impact >= %.1f g or %.1f g/sample, rotation >= %.0f dps, "
                      "fall tilt >= %.0f deg for %lu ms (support tilt >= %.0f deg)\n",
                      (double)ACCIDENT_IMPACT_G, (double)ACCIDENT_JERK_G,
                      (double)ACCIDENT_ROTATION_DPS, (double)ACCIDENT_FALL_TILT_DEGREES,
                      (unsigned long)ACCIDENT_FALL_TILT_HOLD_MS,
                      (double)ACCIDENT_TILT_DEGREES);
    } else {
        // Not fatal: everything else keeps working, and the detector keeps
        // re-probing so a module fixed later is picked up without a reset.
        Serial.printf("[accident] no MPU6050 at 0x%02X; accident detection is off for now\n",
                      (unsigned)MPU6050_I2C_ADDRESS);
    }
#else
    // No MPU6050 fitted on this hardware build (ACCIDENT_ENABLED 0): skip the
    // probe entirely so startup never waits on a sensor that cannot answer.
    // MPU presence is not required for startup or server connectivity.
    Serial.println("[accident] MPU6050 not fitted; accident sampling disabled");
#endif

    // Normal mode one-shot boot probe. If Wi-Fi is enabled, allow up to 3s for association.
#if CONNECTIVITY_MODE != CONNECTIVITY_MODE_WIFI_HEALTH_TEST
#if !SIM_ONLY_MODE
    uint32_t waitStart = millis();
    while (!api.connected() && (millis() - waitStart < 3000)) {
        delay(100);
    }
#endif
    runBootHealthProbe();
#endif
    beginCountdownDisplayTask();
}

void loop() {
    static uint32_t lastHallDiagnosticMs = 0;
    const uint32_t hallDiagnosticNow = millis();
    if (hallDiagnosticNow - lastHallDiagnosticMs >= 1000UL) {
        lastHallDiagnosticMs = hallDiagnosticNow;
        const HallSpeedReading reading = hallSpeed.reading();
        Serial.printf("[hall] GPIO=%u pulses=%lu status=%s\n",
                      HALL_SENSOR_PIN, (unsigned long)reading.pulseCount, reading.status);
    }

#if CONNECTIVITY_MODE == CONNECTIVITY_MODE_WIFI_HEALTH_TEST
    gps.update();
    pollAccidentDetection();
    serviceWifiHealthTest();
    delay(100);
#elif CONNECTIVITY_MODE == CONNECTIVITY_MODE_EC20F_SIM
    gps.update();
    pollAccidentDetection();
    serviceCellularHealthTest();
    delay(100);
#else
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
#if !SIM_ONLY_MODE
        if (now - lastWifiRetry > WIFI_RETRY_INTERVAL_MS) connectWifi();
#endif

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
            setNetworkWait(true);
            simConnectInternet(SIM_APN);
            setNetworkWait(false);
        }

        // No verified server link: the LCD returns to PEDALYA CONNECTING.
        // Startup, modem power and cellular registration alone never count
        // as a connection; only a health-200 sets the latch.
        serverVerified = false;
        paintScreen();
        delay(250);
        return;
    }

    if (!everSynced) {
        paintScreen();
#if !SIM_ONLY_MODE
        if (WiFi.status() == WL_CONNECTED) {
            Serial.print("[ip] ");
            Serial.println(WiFi.localIP());
        }
#endif
        everSynced = true;
    }

    // 1. Poll / update rental state.
    bool polledThisPass = false;
    const uint32_t interval = serverReachable ? pollIntervalMs()
                                               : (uint32_t)RETRY_SECONDS * 1000UL;
    static float loggedWheelCircumferenceM = -1.0f;
    if (!polledOnce || now - lastPollMs >= interval) {
        lastPollMs = now;
        polledOnce = true;
        polledThisPass = true;

        String error;
        setNetworkWait(true);
        serverReachable = api.poll(state, error);
        setNetworkWait(false);
        if (!serverReachable) {
            lastError = error;
            // A failed poll changes nothing: the actuators keep their last state.
            Serial.printf("[loop] poll failed: %s (actuator state preserved)\n", error.c_str());
            display.setErrorLed(true);
        } else {
            hallSpeed.setWheelCircumferenceM(state.wheelCircumferenceM);
            if (state.wheelCircumferenceM != loggedWheelCircumferenceM) {
                loggedWheelCircumferenceM = state.wheelCircumferenceM;
                if (hallSpeed.configured()) {
                    Serial.printf("[hall] circumference configured: %.3f m\n",
                                  state.wheelCircumferenceM);
                } else {
                    Serial.println("[hall] circumference missing or invalid; speed unconfigured");
                }
            }
            display.setErrorLed(false);
        }
    }

    if (!serverReachable) {
        // Still enforce expiry safety on the last known state, so a network blip
        // cannot unlock a timed-out wheel or silence its alarm. The LCD updates
        // to SERVER OFFLINE or WIFI CONNECTING via paintScreen() (SystemStatus).
        // Only a fresh health-200 may show SERVER ONLINE.
        controller.maintainDuringNetworkFailure(state);
        serverVerified = false;
        paintScreen();
        delay(500);
        return;
    }

    // Re-verify at poll cadence while the link is up but STARTED is not yet
    // earned (boot failure, or a connection lost earlier). Strictly
    // health-gated: only an HTTP 200 from the read-only /api/health GET sets
    // the latch. No rental, lock, GPS or accident traffic decides this.
    if (!serverVerified && polledThisPass) {
        String healthVerdict;
        setNetworkWait(true);
        const bool healthOk = api.checkHealth(healthVerdict);
        setNetworkWait(false);
        serverVerified = (healthOk && api.lastStatus() == 200);
        Serial.printf("[loop] health re-check %s (%s)\n",
                      serverVerified ? "OK" : "FAILED", healthVerdict.c_str());
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
    if (polledThisPass) {
        setNetworkWait(true);
        commands.pollAndExecute(state);
        setNetworkWait(false);
    }

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
#endif
}
