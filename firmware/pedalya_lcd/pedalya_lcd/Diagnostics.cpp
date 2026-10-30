#include "Diagnostics.h"

#include "config.h"

#include <stdio.h>
#include <string.h>
#include <esp_system.h>
#include <esp_core_dump.h>

namespace Diagnostics {

bool apiKeyConfigured() {
    return strlen(DEVICE_API_KEY) > 0;
}

bool fleetKeyConfigured() {
    return strlen(PEDALYA_API_KEY) > 0;
}

void printActuatorConfig() {
    Serial.printf("[SERVO] PIN = %d\n", SERVO_PIN);
    Serial.printf("[SERVO] RELEASE = %d\n", SERVO_RELEASE_ANGLE);
    Serial.printf("[SERVO] LOCK = %d\n", SERVO_LOCK_ANGLE);
    Serial.printf("[cfg] servo GPIO%d release=%d lock=%d at %d Hz; buzzer GPIO%d\n", SERVO_PIN,
                  SERVO_RELEASE_ANGLE, SERVO_LOCK_ANGLE, SERVO_FREQUENCY_HZ, BUZZER_PIN);
    Serial.printf("[cfg] expiry: silent until 0, then servo LOCK + flat alarm %lu ms\n",
                  (unsigned long)EXPIRED_FLATLINE_MS);
    Serial.printf("[cfg] return grace %lu s, then %lu ms beeps from %lu ms gaps down to "
                  "%lu ms; flat alarm returns once it lapses\n",
                  (unsigned long)RETURN_GRACE_SECONDS,
                  (unsigned long)RETURN_COUNTDOWN_TONE_MS,
                  (unsigned long)RETURN_COUNTDOWN_GAP_START_MS,
                  (unsigned long)RETURN_COUNTDOWN_GAP_END_MS);
    Serial.printf("[cfg] warning window %d s, warning buzzer disabled\n",
                  EXPIRY_WARNING_SECONDS);
    Serial.printf("[cfg] gradual lock: last %d s, servo %d -> %d in step with the countdown\n",
                  GRADUAL_LOCK_SECONDS, SERVO_RELEASE_ANGLE, SERVO_LOCK_ANGLE);
    Serial.printf("[cfg] geofence %.7f,%.7f r=%.0f m (+%.0f m counts as NEAR)\n",
                  GEOFENCE_CENTER_LAT, GEOFENCE_CENTER_LNG, GEOFENCE_RADIUS_M,
                  GEOFENCE_NEAR_MARGIN_M);
}

void printBanner() {
    // Display only: the request URL itself is built per-endpoint in
    // PedalyaApi (https carries no :port, http carries :SERVER_PORT).
    // The default port is omitted here so the log mirrors that shape.
    const bool defaultPort = (USE_HTTPS && SERVER_PORT == 443) ||
                             (!USE_HTTPS && SERVER_PORT == 80);
    if (defaultPort) {
        Serial.printf("[cfg] server  %s://%s%s\n", USE_HTTPS ? "https" : "http", SERVER_HOST,
                      SERVER_PATH);
    } else {
        Serial.printf("[cfg] server  %s://%s:%d%s\n", USE_HTTPS ? "https" : "http", SERVER_HOST,
                      SERVER_PORT, SERVER_PATH);
    }
    Serial.printf("[cfg] commands %s\n", COMMANDS_PATH);
    Serial.printf("[cfg] health %s (public, no key)\n", HEALTH_PATH);
    Serial.printf("[cfg] accident %s\n", ACCIDENT_REPORT_PATH);
#if CONNECTIVITY_MODE == CONNECTIVITY_MODE_WIFI_HEALTH_TEST
    Serial.println("[cfg] connectivity: temporary Wi-Fi health test only");
    Serial.printf("[cfg] health target: https://%s:%d%s\n", WIFI_TEST_SERVER_HOST,
                  WIFI_TEST_SERVER_PORT, WIFI_TEST_HEALTH_PATH);
    Serial.println("[cfg] health-test mode sends no rental, command, telemetry, GPS or accident requests");
#elif SIM_ONLY_MODE
    Serial.println("[cfg] connectivity: EC20F/SIM-only deployment");
    Serial.println("[cfg] net SIM-only: Wi-Fi radio stays off");
#else
    Serial.println("[cfg] connectivity: Wi-Fi primary with EC20F/SIM fallback");
    Serial.println("[cfg] net Wi-Fi connected; EC20F used only when Wi-Fi is unavailable");
#endif
    Serial.printf("[api] API key configured: %s\n", apiKeyConfigured() ? "YES" : "NO");
    Serial.printf("[api] fleet key configured: %s (heartbeat, accident-report)\n",
                  fleetKeyConfigured() ? "YES" : "NO");
    Serial.println("[api] API key header configured: YES (X-API-Key)");
    Serial.printf("[cfg] gps     UART1 (GPIO%d, GPIO%d)\n", GPS_RX_PIN, GPS_TX_PIN);
    if (SIM_ENABLED) {
        const bool placeholder = strcmp(SIM_APN, "YOUR_APN_HERE") == 0;
        Serial.printf("[cfg] EC20F UART2 (GPIO%d, GPIO%d) APN: %s\n", SIM_RX_PIN, SIM_TX_PIN,
                      placeholder ? "<not set>" : SIM_APN);
    } else {
        Serial.println("[cfg] EC20F disabled (SIM_ENABLED 0)");
    }
}

void printBootDiagnostics() {
    esp_reset_reason_t reason = esp_reset_reason();
    const char* reasonStr = "UNKNOWN";
    switch (reason) {
        case ESP_RST_POWERON:   reasonStr = "POWERON (normal cold boot)"; break;
        case ESP_RST_EXT:       reasonStr = "EXT (external pin reset)"; break;
        case ESP_RST_SW:        reasonStr = "SW (software reset)"; break;
        case ESP_RST_PANIC:     reasonStr = "PANIC (software exception / crash)"; break;
        case ESP_RST_INT_WDT:   reasonStr = "INT_WDT (interrupt watchdog reset)"; break;
        case ESP_RST_TASK_WDT:  reasonStr = "TASK_WDT (task watchdog reset)"; break;
        case ESP_RST_WDT:       reasonStr = "WDT (other watchdog reset)"; break;
        case ESP_RST_DEEPSLEEP: reasonStr = "DEEPSLEEP"; break;
        case ESP_RST_BROWNOUT:  reasonStr = "BROWNOUT (unstable/low power supply)"; break;
        case ESP_RST_SDIO:      reasonStr = "SDIO"; break;
        default: break;
    }
    Serial.printf("[boot] reset reason: %d - %s\n", (int)reason, reasonStr);

    esp_err_t dumpCheck = esp_core_dump_image_check();
    if (dumpCheck == ESP_OK) {
        size_t addr = 0, size = 0;
        esp_core_dump_image_get(&addr, &size);
        Serial.printf("[boot] core dump: VALID crash image in flash at 0x%X (%u bytes)\n", (unsigned)addr, (unsigned)size);
    } else if (dumpCheck == ESP_ERR_NOT_FOUND) {
        Serial.println("[boot] core dump: clean (no crash dump in flash)");
    } else {
        Serial.printf("[boot] core dump: checksum/data invalid (code 0x%X). Clearing stale/corrupted partition...\n", (unsigned)dumpCheck);
        esp_err_t eraseErr = esp_core_dump_image_erase();
        Serial.printf("[boot] core dump partition erase: %s\n", eraseErr == ESP_OK ? "DONE" : "FAILED");
    }
}

}  // namespace Diagnostics