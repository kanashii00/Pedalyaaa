// Harness validation: prints for 20s and touches no GPIO and no LEDC at all.
// If this dies too, the board/serial/capture path is the problem, not PWM.
#include <Arduino.h>

void setup() {
    Serial.begin(115200);
    Serial.println("[H1] alive, no GPIO touched");
}

void loop() {
    static int n = 0;
    Serial.printf("[H2] tick %d uptime=%lu ms\n", n, (unsigned long)millis());
    n++;
    if (n >= 40) {
        Serial.println("[H3] 40 ticks done - harness healthy");
        delay(2000);
    }
    delay(500);
}
