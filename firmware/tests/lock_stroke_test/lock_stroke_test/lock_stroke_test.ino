#include <Arduino.h>
#include <ESP32Servo.h>

/*
 * Servo only. Flash this to rehearse the exact stroke the firmware performs when
 * a rental expires, at the same angles, so the mechanical linkage can be checked
 * before it is trusted on a bicycle.
 *
 * Lift the wheel and keep the normal mechanical brake usable. A servo that is
 * still attached to a wheel on the ground will fight you.
 *
 * The angles below are the firmware defaults. Set ACTUATOR_CALIBRATION_MODE and
 * the two angle values in pedalya_lcd/secrets.h to match your linkage, then put
 * the same numbers here.
 */

#define SERVO_PIN 18
#define SERVO_RELEASE_ANGLE 0
#define SERVO_LOCK_ANGLE 80
#define SERVO_FREQUENCY_HZ 50
#define SERVO_MIN_PULSE_US 500
#define SERVO_MAX_PULSE_US 2400

#define STEP_DEGREES 5
#define STEP_DELAY_MS 50
#define HOLD_BEFORE_MS 3000
#define HOLD_LOCKED_MS 5000

static Servo lockServo;
static bool ready = false;

void writeAngle(int angle, const char *reason) {
    const int safe = constrain(angle, 0, 180);
    Serial.printf("[lock] %3d deg  %s\n", safe, reason);
    if (ready) lockServo.write(safe);
}

/** The sweep an expiry performs: release angle to lock angle, then hold. */
void expiryStroke() {
    Serial.println("[lock] BEGIN ROTATION");

    const int step = SERVO_RELEASE_ANGLE <= SERVO_LOCK_ANGLE ? STEP_DEGREES : -STEP_DEGREES;
    for (int angle = SERVO_RELEASE_ANGLE;
         step > 0 ? angle <= SERVO_LOCK_ANGLE : angle >= SERVO_LOCK_ANGLE;
         angle += step) {
        writeAngle(angle, "rotating");
        delay(STEP_DELAY_MS);
    }

    // A target that is not a multiple of the step is still commanded exactly.
    if ((SERVO_LOCK_ANGLE - SERVO_RELEASE_ANGLE) % step != 0) {
        writeAngle(SERVO_LOCK_ANGLE, "final");
    }

    Serial.println("[lock] HOLDING LOCK");
    delay(HOLD_LOCKED_MS);
}

void setup() {
    Serial.begin(115200);
    delay(200);

    lockServo.setPeriodHertz(SERVO_FREQUENCY_HZ);
    const int result = lockServo.attach(SERVO_PIN, SERVO_MIN_PULSE_US, SERVO_MAX_PULSE_US);
    ready = result >= 0 && lockServo.attached();

    Serial.printf("[lock] GPIO%d attach = %s, release=%d lock=%d\n", SERVO_PIN,
                  ready ? "OK" : "FAILED", SERVO_RELEASE_ANGLE, SERVO_LOCK_ANGLE);
    Serial.println("[lock] wheel lifted, mechanical brake usable");

    writeAngle(SERVO_RELEASE_ANGLE, "released");
    delay(HOLD_BEFORE_MS);
}

void loop() {
    expiryStroke();
    writeAngle(SERVO_RELEASE_ANGLE, "released again");
    delay(HOLD_BEFORE_MS);
}