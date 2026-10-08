#include "LockActuator.h"

#include "config.h"

bool LockActuator::attach() {
    if (ready_) return true;

    servo_.setPeriodHertz(SERVO_FREQUENCY_HZ);
    const int result = servo_.attach(SERVO_PIN, SERVO_MIN_PULSE_US, SERVO_MAX_PULSE_US);
    ready_ = result >= 0 && servo_.attached();
    if (ready_) {
        // The horn's real position is unknown after an attach, so the guard is
        // cleared: the next target must reach the pin even if it matches the
        // angle requested before the servo was powered.
        lastCommandedAngle_ = kUncommandedAngle;
    }
    return ready_;
}

void LockActuator::attachAfterServerSync() {
    if (ready_) return;

    const bool ok = attach();
    Serial.printf("[SERVO] AUTHENTICATED ATTACH = %s\n", ok ? "SUCCESS" : "FAILED");
}

bool LockActuator::writePhysical(int angle, const char* reason) {
    const int safeAngle = constrain(angle, 0, 180);

    // State-change guard. The servo keeps generating its PWM between calls, so
    // holding the same angle needs no new command: rewriting it would only
    // repeat the log line on every enforcement pass while the bicycle sits
    // idle. Calibration mode bypasses the guard so a bench command always
    // reaches the pin, and attach() clears it so the first write after boot or
    // after a re-attach is never swallowed.
    if (!ACTUATOR_CALIBRATION_MODE && safeAngle == lastCommandedAngle_) {
        return false;
    }

    lastCommandedAngle_ = safeAngle;

    Serial.printf("[SERVO] PHYSICAL WRITE -> GPIO%d angle=%d reason=%s\n",
                  SERVO_PIN, safeAngle, reason);
    if (servo_.attached()) servo_.write(safeAngle);
    return true;
}

void LockActuator::move(int angle, bool locked, const char* message) {
    if (ACTUATOR_CALIBRATION_MODE) {
        Serial.println("[servo] calibration mode");
        return;
    }

    const int safeAngle = constrain(angle, 0, 180);

    Serial.printf("[servo] COMMAND angle=%d locked=%d\n", safeAngle, locked ? 1 : 0);

    const bool changed = writePhysical(safeAngle, message);

    locked_ = locked;

    if (!changed) {
        Serial.printf("[servo] NO CHANGE - already held at %d degrees (%s)\n",
                      safeAngle, message);
        return;
    }

    delay(300);

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
    const int low = releaseAngle < lockAngle ? releaseAngle : lockAngle;
    const int high = releaseAngle < lockAngle ? lockAngle : releaseAngle;

    // Start the stroke where the servo already is when that position lies on
    // the path: a rental that was graduated down to LOCK (or forced there
    // while outside the geofence) must not be swept back out to RELEASE on its
    // way to expiry. An unknown or off-path position starts at RELEASE, which
    // is the ordinary case for a rental that rode out the whole window.
    int angle = releaseAngle;
    if (lastCommandedAngle_ >= low && lastCommandedAngle_ <= high) {
        angle = lastCommandedAngle_;
    }

    Serial.println("[LOCK] RENTAL EXPIRED");
    Serial.printf("[LOCK] BEGIN ROTATION %d -> %d DEGREES\n", angle, lockAngle);

    for (; step > 0 ? angle <= lockAngle : angle >= lockAngle; angle += step) {
        // Each stroke step is a distinct angle, so the state-change guard only
        // swallows a step the servo already holds (for example the sweep's
        // first step when it starts exactly where the servo already is).
        if (writePhysical(angle, "lock-rotation")) {
            Serial.printf("[LOCK] SERVO %d\n", angle);
        }
        delay(50);
    }

    // The lock angle is always commanded exactly, including when the stroke
    // started short of RELEASE and stepped off a multiple of five.
    if (writePhysical(lockAngle, "lock-rotation-final")) {
        Serial.printf("[LOCK] SERVO %d\n", lockAngle);
    }
    Serial.printf("[LOCK] LOCK POSITION REACHED: %d DEGREES\n", lockAngle);
    Serial.println("[LOCK] HOLDING LOCK");
}