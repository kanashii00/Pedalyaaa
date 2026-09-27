// Step 1 diagnostic: GPIO18 servo WITHOUT the ESP32Servo library.
// Core-3 native LEDC only. A marker prints after every call so we can see
// exactly which operation stops the CPU.
#include <Arduino.h>

#define SERVO_PIN 18
#define SERVO_FREQ_HZ 50
#define SERVO_MIN_PULSE_US 500
#define SERVO_MAX_PULSE_US 2400

// 50 Hz servo frame period is 20000 us, so a 0..180 deg request maps onto a
// 500..2400 us pulse. These are the values the library would have written.
static uint32_t pulseForAngle(int degrees) {
    if (degrees < 0) degrees = 0;
    if (degrees > 180) degrees = 180;
    return (uint32_t)SERVO_MIN_PULSE_US +
           ((uint32_t)(SERVO_MAX_PULSE_US - SERVO_MIN_PULSE_US) * (uint32_t)degrees) / 180UL;
}

static void servoWrite(int degrees) {
    ledcWriteTone(SERVO_PIN, pulseForAngle(degrees));
}

void setup() {
    Serial.begin(115200);

    Serial.println("[S1] alive");
    Serial.flush();

    Serial.printf("[S2] ledcAttach(pin=%d, freq=%d, res=16)\n", SERVO_PIN, SERVO_FREQ_HZ);
    Serial.flush();
    ledcAttach(SERVO_PIN, SERVO_FREQ_HZ, 16);

    Serial.println("[S3] ledcAttach returned - core-3 API is alive");
    Serial.flush();

    Serial.println("[S4] writing 0 deg (500 us)");
    Serial.flush();
    servoWrite(0);

    Serial.println("[S5] survived write(0) - 3s hold");
    Serial.flush();
    delay(3000);

    Serial.println("[S6] writing 90 deg (1450 us)");
    Serial.flush();
    servoWrite(90);

    Serial.println("[S7] survived write(90) - 3s hold");
    Serial.flush();
    delay(3000);

    Serial.println("[S8] writing 180 deg (2400 us)");
    Serial.flush();
    servoWrite(180);

    Serial.println("[S9] survived write(180) - 3s hold");
    Serial.flush();
    delay(3000);
    Serial.println("[S10] setup complete, entering loop");
    Serial.flush();
}

void loop() {
    Serial.println("[LOOP] 90");
    servoWrite(90);
    delay(1500);
    Serial.println("[LOOP] 0");
    servoWrite(0);
    delay(1500);
    Serial.flush();
}
