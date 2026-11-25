# 1 "C:\\Users\\ADMINI~1\\AppData\\Local\\Temp\\tmpejm_vfhc"
#include <Arduino.h>
# 1 "C:/Users/Administrator/pedalya/firmware/pedalya_lcd/pedalya_lcd/pedalya_lcd.ino"
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
#include "EC20FDiagnostic.h"
#include "config.h"
#include <WiFi.h>
# 37 "C:/Users/Administrator/pedalya/firmware/pedalya_lcd/pedalya_lcd/pedalya_lcd.ino"
static PedalyaApi api;
static LcdDisplay display;
static LockActuator lock;
static BuzzerAlarm buzzer;
static RentalTimer timer;
static RentalController controller(lock, buzzer, timer);
static CommandHandler commands(display, lock, controller, api);
static GpsReceiver gps;
static AccidentDetector accidentDetector;



static RentalState state;
# 68 "C:/Users/Administrator/pedalya/firmware/pedalya_lcd/pedalya_lcd/pedalya_lcd.ino"
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
#if ACCIDENT_ENABLED
static uint32_t lastAccidentTraceMs = 0;
#endif
# 96 "C:/Users/Administrator/pedalya/firmware/pedalya_lcd/pedalya_lcd/pedalya_lcd.ino"
static void queueAccidentReport(const AccidentEvent& event);
static void pollAccidentDetection();
static void serviceAccidentReport();
static uint32_t pollIntervalMs();
static void connectWifi();
static void reportTelemetry();
static void paintScreen();
static void serviceWifiHealthTest();
static void serviceCellularHealthTest();
static void runBootHealthProbe();
void setup();
void loop();
#line 96 "C:/Users/Administrator/pedalya/firmware/pedalya_lcd/pedalya_lcd/pedalya_lcd.ino"
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



    if (gps.hasFreshFix()) {
        pendingAccident.hasFix = true;
        pendingAccident.latitude = gps.fix().latitude;
        pendingAccident.longitude = gps.fix().longitude;
    }
}

#if ACCIDENT_ENABLED







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





static void pollAccidentDetection() {
#if !ACCIDENT_ENABLED



    return;
#endif
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
# 212 "C:/Users/Administrator/pedalya/firmware/pedalya_lcd/pedalya_lcd/pedalya_lcd.ino"
static void serviceAccidentReport() {
    if (!pendingAccident.active) return;

    const uint32_t now = millis();
    if ((int32_t)(now - pendingAccident.nextAttemptMs) < 0) return;

    if (!api.connected()) {

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
    if (api.reportAccident(pendingAccident.latitude, pendingAccident.longitude,
                           pendingAccident.hasFix, pendingAccident.impactG,
                           pendingAccident.rotationDps, pendingAccident.tiltDegrees,
                           pendingAccident.accelXG, pendingAccident.accelYG,
                           pendingAccident.accelZG, error)) {
        Serial.printf("[accident] admin notified (attempt %lu)%s\n",
                      (unsigned long)pendingAccident.attempts,
                      pendingAccident.hasFix ? " with GPS location" : " without GPS location");
        display.showMessageFor(3000, "! ACCIDENT ALERT", pendingAccident.hasFix ? "ADMIN NOTIF +GPS" : "ADMIN NOTIFIED  ");
        pendingAccident.active = false;
        paintScreen();
        return;
    }



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




static bool serverVerified = false;
static bool heartbeatAttempted = false;
static String lastError;

static uint32_t pollIntervalMs() { return (uint32_t)state.pollAfterSeconds * 1000UL; }

#if CONNECTIVITY_MODE == CONNECTIVITY_MODE_WIFI_HEALTH_TEST || !SIM_ONLY_MODE
static void connectWifi() {
    api.begin();
    lastWifiRetry = millis();
}
#endif
# 315 "C:/Users/Administrator/pedalya/firmware/pedalya_lcd/pedalya_lcd/pedalya_lcd.ino"
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

    if (gps.hasFreshFix()) {
        api.cacheGpsFix(gps.fix());
    }

    if (gps.hasFreshFix() && now - lastGpsReportMs >= GPS_REPORT_INTERVAL_MS) {
        lastGpsReportMs = now;
        const GpsFix& fix = gps.fix();
        if (!api.sendGps(fix.latitude, fix.longitude, fix.speedKph, fix.heading, error)) {
            Serial.printf("[telemetry] GPS upload failed: %s\n", error.c_str());
        }
    }
}
# 351 "C:/Users/Administrator/pedalya/firmware/pedalya_lcd/pedalya_lcd/pedalya_lcd.ino"
static void paintScreen() {
    if (display.messageActive()) {
        return;
    }




    if (pendingAccident.active) {
        const uint32_t now = millis();
        if (state.hasRental) {
            if (((now / 2000UL) % 2) == 0) {
                display.showAccidentAlert(false, pendingAccident.hasFix);
                return;
            }

        } else {
            display.showAccidentAlert(false, pendingAccident.hasFix);
            return;
        }
    }


    if (state.hasRental) {
        if (state.expired() || controller.rentalIsExpired(state)) {
            display.showExpiredPhase(timer.seconds(), timer.penalty(), lock.locked());
        } else {
            display.showRental(state, lock.locked());
        }
        return;
    }


    const bool wifiUp = WiFi.status() == WL_CONNECTED;
    const bool modReady = SIM_ENABLED && simIsReady();

    const bool cellActive = (!wifiUp && modReady && api.connected());
    const char* serial = (state.bicycleSerial.length() > 0) ? state.bicycleSerial.c_str() : "PDY-001";

    if (serverVerified) {

        display.showReady(serial, wifiUp, modReady, cellActive, serverVerified,
                          lock.locked(), state.bicycleBatteryLevel, state.bicycleStatus.c_str());
        return;
    }


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
# 574 "C:/Users/Administrator/pedalya/firmware/pedalya_lcd/pedalya_lcd/pedalya_lcd.ino"
static void runBootHealthProbe() {
    const bool wifiUp = WiFi.status() == WL_CONNECTED;





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
#if SIM_ONLY_MODE || CONNECTIVITY_MODE == CONNECTIVITY_MODE_EC20F_SIM


    WiFi.mode(WIFI_OFF);
    Serial.println("[net] SIM-only mode: Wi-Fi radio powered off");
    simInit();
    if (simPresent()) simRunDiagnostics();
#elif CONNECTIVITY_MODE == CONNECTIVITY_MODE_WIFI_HEALTH_TEST
    Serial.println("[net] Wi-Fi health-test mode: EC20F and business requests are disabled");
    connectWifi();
#else



    connectWifi();
    simInit();
    if (simPresent()) simRunDiagnostics();
#endif




    Wire.begin(I2C_SDA_PIN, I2C_SCL_PIN);




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
                      "tilt >= %.0f deg\n",
                      (double)ACCIDENT_IMPACT_G, (double)ACCIDENT_JERK_G,
                      (double)ACCIDENT_ROTATION_DPS, (double)ACCIDENT_TILT_DEGREES);
    } else {


        Serial.printf("[accident] no MPU6050 at 0x%02X; accident detection is off for now\n",
                      (unsigned)MPU6050_I2C_ADDRESS);
    }
#else



    Serial.println("[accident] MPU6050 not fitted; accident sampling disabled");
#endif


#if CONNECTIVITY_MODE != CONNECTIVITY_MODE_WIFI_HEALTH_TEST
#if !SIM_ONLY_MODE
    uint32_t waitStart = millis();
    while (!api.connected() && (millis() - waitStart < 3000)) {
        delay(100);
    }
#endif
    runBootHealthProbe();
#endif
}

void loop() {
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




    pollAccidentDetection();
    serviceAccidentReport();

    const uint32_t now = millis();

    if (!api.connected()) {


        controller.maintainDuringNetworkFailure(state);
#if !SIM_ONLY_MODE
        if (now - lastWifiRetry > WIFI_RETRY_INTERVAL_MS) connectWifi();
#endif
# 737 "C:/Users/Administrator/pedalya/firmware/pedalya_lcd/pedalya_lcd/pedalya_lcd.ino"
        if (SIM_ENABLED && !simIsReady() && (lastSimRetry == 0 ||
            now - lastSimRetry > SIM_RETRY_INTERVAL_MS)) {
            lastSimRetry = now;
            simConnectInternet(SIM_APN);
        }




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

            Serial.printf("[loop] poll failed: %s (actuator state preserved)\n", error.c_str());
            display.setErrorLed(true);
        } else {
            display.setErrorLed(false);
        }
    }

    if (!serverReachable) {




        controller.maintainDuringNetworkFailure(state);
        serverVerified = false;
        paintScreen();
        delay(500);
        return;
    }





    if (!serverVerified && polledThisPass) {
        String healthVerdict;
        const bool healthOk = api.checkHealth(healthVerdict);
        serverVerified = (healthOk && api.lastStatus() == 200);
        Serial.printf("[loop] health re-check %s (%s)\n",
                      serverVerified ? "OK" : "FAILED", healthVerdict.c_str());
    }



    if (polledThisPass) lock.attachAfterServerSync();






    const BoundaryStatus boundary =
        evaluateBoundary(gps.hasFreshFix(), gps.fix().latitude, gps.fix().longitude);
    controller.update(state, serverReachable);
    controller.enforce(state, boundary);


    if (polledThisPass) commands.pollAndExecute(state);



    controller.enforce(state, boundary);

    reportTelemetry();
    paintScreen();






    if (buzzer.patternRunning()) {
        uint32_t sleepMs = buzzer.nextEdgeInMs();

        if (sleepMs == 0) sleepMs = 1;
        delay(sleepMs < BUZZER_LOOP_MAX_SLEEP_MS ? sleepMs : BUZZER_LOOP_MAX_SLEEP_MS);
    } else {
        delay(200);
    }
#endif
}