#include <ESP32Servo.h>

// DIAGNOSTIC ONLY - not the Pedalya firmware.
// Same GPIO18 servo test, but prints a marker after every single call so we can
// see precisely which operation stops the CPU.

Servo lockServo;

#define SERVO_PIN 18
#define SERVO_MIN_PULSE_US 500
#define SERVO_MAX_PULSE_US 2400

void setup() {
    Serial.begin(115200);

    Serial.println("[P1] alive before setPeriodHertz");
    lockServo.setPeriodHertz(50);
    Serial.println("[P2] setPeriodHertz OK");

    int result = lockServo.attach(SERVO_PIN, SERVO_MIN_PULSE_US, SERVO_MAX_PULSE_US);
    Serial.printf("[P3] attach returned %d, attached()=%d\n", result, (int)lockServo.attached());
    Serial.flush();

    delay(1000);
    Serial.println("[P4] survived the 1s delay after attach");
    Serial.flush();

    Serial.println("[P5] calling write(0)");
    Serial.flush();
    lockServo.write(0);
    Serial.println("[P6] write(0) returned");
    Serial.flush();

    delay(3000);
    Serial.println("[P7] survived 3s at 0 degrees");
    Serial.flush();

    Serial.println("[P8] calling write(90)");
    Serial.flush();
    lockServo.write(90);
    Serial.println("[P9] write(90) returned");
    Serial.flush();

    delay(3000);
    Serial.println("[P10] survived 3s at 90 degrees");
    Serial.flush();
}

void loop() {
    Serial.println("[LOOP] 90");
    lockServo.write(90);
    delay(1500);
    Serial.println("[LOOP] 0");
    lockServo.write(0);
    delay(1500);
    Serial.flush();
}
