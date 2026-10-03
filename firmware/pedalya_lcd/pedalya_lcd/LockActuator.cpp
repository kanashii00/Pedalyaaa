#include "LockActuator.h"

#include "config.h"

bool LockActuator::attach() {
    if (ready_) return true;

    servo_.setPeriodHertz(SERVO_FREQUENCY_HZ);
    const int result = servo_.attach(SERVO_PIN, SERVO_MIN_PULSE_US, SERVO_MAX_PULSE_US);
    ready_ = result >= 0 && servo_.attached();
    return ready_;
}

void LockActuator::attachAfterServerSync() {
    if (ready_) return;

    const bool ok = attach();
    Serial.printf("[SERVO] AUTHENTICATED ATTACH = %s\n", ok ? "SUCCESS" : "FAILED");
}

void LockActuator::writePhysical(int angle, const char* reason) {
    const int safeAngle = constrain(angle, 0, 180);
    Serial.printf("[SERVO] PHYSICAL WRITE -> GPIO%d angle=%d reason=%s\n",
                  SERVO_PIN, safeAngle, reason);
    if (servo_.attached()) servo_.write(safeAngle);
}

void LockActuator::move(int angle, bool locked, const char* message) {
    if (ACTUATOR_CALIBRATION_MODE) {
        Serial.println("[servo] calibration mode");
        return;
    }

    const int safeAngle = constrain(angle, 0, 180);

    Serial.printf("[servo] COMMAND angle=%d locked=%d\n", safeAngle, locked ? 1 : 0);

    writePhysical(safeAngle, message);
    delay(300);

    locked_ = locked;

    Serial.printf("[servo] %s (%d degrees)\n", message, safeAngle);
}

void LockActuator::release() {
    move(SERVO_RELEASE_ANGLE, false, "RELEASED");
}

void LockActuator::lockAtConfiguredAngle() {
    move(SERVO_LOCK_ANGLE, true, "LOCKED");
}

void LockActuator::performLockMovement() {
    const int releaseAngle = constrain(SERVO_RELEASE_ANGLE, 0, 180);
    const int lockAngle = constrain(SERVO_LOCK_ANGLE, 0, 180);
    const int step = releaseAngle <= lockAngle ? 5 : -5;

    Serial.println("[LOCK] RENTAL EXPIRED");
    Serial.printf("[LOCK] BEGIN ROTATION %d -> %d DEGREES\n", releaseAngle, lockAngle);

    for (int angle = releaseAngle;
         step > 0 ? angle <= lockAngle : angle >= lockAngle;
         angle += step) {
        Serial.printf("[LOCK] SERVO %d\n", angle);
        writePhysical(angle, "lock-rotation");
        delay(50);
    }

    // A non-multiple-of-five configured target is always commanded exactly.
    if ((lockAngle - releaseAngle) % step != 0) {
        Serial.printf("[LOCK] SERVO %d\n", lockAngle);
        writePhysical(lockAngle, "lock-rotation-final");
    }
    Serial.printf("[LOCK] LOCK POSITION REACHED: %d DEGREES\n", lockAngle);
    Serial.println("[LOCK] HOLDING LOCK");
}