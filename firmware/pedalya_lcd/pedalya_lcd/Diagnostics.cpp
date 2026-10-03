#include "Diagnostics.h"

#include "config.h"

#include <stdio.h>
#include <string.h>

namespace Diagnostics {

const char* keyFingerprint() {
    static char buffer[32];
    const size_t length = strlen(DEVICE_API_KEY);
    if (length == 0) {
        snprintf(buffer, sizeof(buffer), "MISSING - put it in secrets.h");
        return buffer;
    }
    const size_t visible = length < 8 ? length : 8;
    snprintf(buffer, sizeof(buffer), "%.*s... (%u chars)", (int)visible, DEVICE_API_KEY,
             (unsigned)length);
    return buffer;
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
}

void printBanner() {
    Serial.printf("[cfg] server  %s://%s:%d%s\n", USE_HTTPS ? "https" : "http", SERVER_HOST,
                  SERVER_PORT, SERVER_PATH);
    Serial.printf("[cfg] commands %s\n", COMMANDS_PATH);
    Serial.printf("[cfg] accident %s\n", ACCIDENT_REPORT_PATH);
    Serial.printf("[cfg] key     %s\n", keyFingerprint());
}

}  // namespace Diagnostics