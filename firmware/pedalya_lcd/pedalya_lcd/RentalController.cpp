#include "RentalController.h"

#include "config.h"

const char* actuatorStateName(ActuatorState value) {
    switch (value) {
        case ActuatorState::Idle:    return "Idle";
        case ActuatorState::Active:  return "Active";
        case ActuatorState::Warning: return "Warning";
        case ActuatorState::AwaitingReturn: return "AwaitingReturn";
        case ActuatorState::Expired: return "Expired";
        case ActuatorState::Unknown: break;
    }
    return "Unknown";
}

RentalController::RentalController(LockActuator& lock, BuzzerAlarm& buzzer, RentalTimer& timer)
    : lock_(lock), buzzer_(buzzer), timer_(timer) {}

/**
 * rental.expired() is the authority. The latch only ever becomes true after
 * expired() has already returned true, so it can never turn a running rental
 * into an expired one. It only stops a later poll that still reports the rental
 * as active from walking an already-locked wheel back to Active.
 */
bool RentalController::rentalIsExpired(const RentalState& rental) const {
    return expiryLatched_ || rental.expired();
}

void RentalController::enterState(ActuatorState next) {
    // UI state only. Rental safety never depends on this transition: the physical
    // servo and buzzer commands live exclusively in enforce(), which runs every
    // normal loop.
    if (state_ == next) return;
    state_ = next;
    switch (next) {
        case ActuatorState::Idle:
            Serial.println("[actuator] IDLE - bicycle available, rental inactive");
            break;
        case ActuatorState::Active:
            Serial.println("[actuator] ACTIVE - rental running, buzzer off");
            break;
        case ActuatorState::Warning:
            Serial.printf("[actuator] WARNING - %d seconds or less, still silent\n",
                          EXPIRY_WARNING_SECONDS);
            break;
        case ActuatorState::Expired:
            Serial.println("[actuator] EXPIRED - rental timed out");
            break;
        case ActuatorState::Unknown:
            Serial.println("[actuator] UNKNOWN - buzzer forced off");
            break;
        case ActuatorState::AwaitingReturn:
            break;
    }
}

void RentalController::logRemaining(int64_t remaining) {
    if (remainingLogged_ && remaining == lastLoggedRemaining_) return;
    remainingLogged_ = true;
    lastLoggedRemaining_ = remaining;
    Serial.printf("[timer] remaining=%lld sec\n", (long long)remaining);
}

void RentalController::update(const RentalState& rental, bool serverReachable) {
    if (!serverReachable || ACTUATOR_CALIBRATION_MODE) return;

    if (!rental.hasRental || rental.status == "returned" || rental.status == "cancelled") {
        // The rental is over, which is the only thing that clears the latch.
        expiryLatched_ = false;
        remainingLogged_ = false;
        timer_.reset();
        enterState(ActuatorState::Idle);
        return;
    }

    if (rental.status == "awaiting_return") {
        timer_.reset();
        enterState(ActuatorState::AwaitingReturn);
        return;
    }

    const int64_t remaining = rental.remainingSeconds();

    // Expiry is tested before anything else, and latched, so Expired always wins
    // over Active and Warning. rental.expired() is the authority: it is false for
    // a rental that simply has no deadline yet.
    if (expiryLatched_ || rental.expired()) {
        if (!expiryLatched_) {
            expiryLatched_ = true;
            timer_.start(rental.serverOverdueSeconds);
        }
        timer_.update();
        if (rental.deadlineTs > 0) {
            logRemaining(remaining);
        }
        enterState(ActuatorState::Expired);
        return;
    }

    const bool warning = rental.status == "active" && EXPIRY_WARNING_SECONDS > 0 &&
                         remaining > 0 && remaining <= EXPIRY_WARNING_SECONDS;

    if (warning) {
        if (rental.deadlineTs > 0) {
            logRemaining(remaining);
        }
        enterState(ActuatorState::Warning);
        return;
    }

    if (rental.deadlineTs > 0) {
        logRemaining(remaining);
    }
    enterState(ActuatorState::Active);
}

void RentalController::enforce(const RentalState& rental) {
    if (ACTUATOR_CALIBRATION_MODE) {
        buzzer_.silence();
        return;
    }

    // A completed rental always wins over a previously latched expiry.
    if (!rental.hasRental || rental.status == "returned" || rental.status == "cancelled") {
        const char* reason = !rental.hasRental ? "idle" :
                             (rental.status == "returned" ? "returned" : "cancelled");
        const bool shouldLock = rental.bicycleLockStatus != "unlocked";
        lock_.writePhysical(shouldLock ? SERVO_LOCK_ANGLE : SERVO_RELEASE_ANGLE, reason);
        lock_.setLocked(shouldLock);
        lockMovementCompleted_ = false;
        buzzer_.silence();
        Serial.printf("[SAFETY] %s -> SERVO.write(%d)\n", shouldLock ? "LOCKED" : "UNLOCKED",
                      shouldLock ? SERVO_LOCK_ANGLE : SERVO_RELEASE_ANGLE);
        return;
    }

    if (rental.status == "awaiting_return") {
        lock_.writePhysical(SERVO_LOCK_ANGLE, "return-pending");
        lock_.setLocked(true);
        lockMovementCompleted_ = false;
        buzzer_.silence();
        return;
    }

    // The current API state is the authority for normal actuator decisions. The
    // expiry latch is used only by the network-loss fail-safe.
    if (rental.expired()) {
        timer_.update();
        if (!lockMovementCompleted_) {
            lock_.performLockMovement();
            lockMovementCompleted_ = true;
        }
        lock_.writePhysical(SERVO_LOCK_ANGLE, "expired");
        lock_.setLocked(true);
        buzzer_.drive(true, timer_.graceSecondsLeft(), true, timer_.elapsedMs());
        Serial.printf("[SAFETY] EXPIRED -> SERVO.write(%d)\n", SERVO_LOCK_ANGLE);
        return;
    }

    // Both a running rental and its final warning window remain rideable.
    lock_.writePhysical(SERVO_RELEASE_ANGLE, "active");
    lock_.setLocked(false);
    lockMovementCompleted_ = false;
    buzzer_.silence();
    Serial.println("[SAFETY] ACTIVE -> SERVO.write(0)");
}

void RentalController::maintainDuringNetworkFailure(const RentalState& rental) {
    if (ACTUATOR_CALIBRATION_MODE) {
        buzzer_.silence();
        return;
    }

    if (!rentalIsExpired(rental)) return;

    lock_.writePhysical(SERVO_LOCK_ANGLE, "network-expired");
    lock_.setLocked(true);
    // serverReachable is false here, so this is a flatline, never a pattern.
    buzzer_.drive(true, timer_.graceSecondsLeft(), false, timer_.elapsedMs());
}