// Flood test: no GPIO, no LEDC, no WiFi. Print as fast as the UART accepts.
// Distinguishes "dies at fixed elapsed time" (power / subsystem init)
// from "dies at fixed byte count" (USB-serial link / buffer).
#include <Arduino.h>

void setup() {
    Serial.begin(115200);
    Serial.println("[F1] flood start, no GPIO, no LEDC");
    Serial.flush();
}

void loop() {
    static uint32_t n = 0;
    Serial.printf("[F] seq=%lu uptime=%lu heap=%lu\n",
                  (unsigned long)n++,
                  (unsigned long)millis(),
                  (unsigned long)ESP.getFreeHeap());
    // deliberately no delay: flood until something breaks
}
