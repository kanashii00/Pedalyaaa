#include "Diagnostics.h"

#include "config.h"

#include <stdio.h>
#include <string.h>

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
    Serial.printf("[cfg] server  %s://%s:%d%s\n", USE_HTTPS ? "https" : "http", SERVER_HOST,
                  SERVER_PORT, SERVER_PATH);
    Serial.printf("[cfg] commands %s\n", COMMANDS_PATH);
    Serial.printf("[cfg] accident %s\n", ACCIDENT_REPORT_PATH);
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

}  // namespace Diagnostics