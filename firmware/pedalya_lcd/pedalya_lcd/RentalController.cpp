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

void RentalController::enterState(ActuatorState next, const String& bikeStatus) {
    // UI state only. Rental safety never depends on this transition: the physical
    // servo and buzzer commands live exclusively in enforce(), which runs every
    // normal loop.
    if (state_ == next && lastBicycleStatus_ == bikeStatus) return;
    state_ = next;
    lastBicycleStatus_ = bikeStatus;
    switch (next) {
        case ActuatorState::Idle:
            if (bikeStatus == "maintenance") {
                Serial.println("[actuator] IDLE - bicycle in maintenance (locked), rental inactive");
            } else {
                Serial.println("[actuator] IDLE - bicycle available, rental inactive");
            }
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
        enterState(ActuatorState::Idle, rental.bicycleStatus);
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

void RentalController::enforce(const RentalState& rental, BoundaryStatus boundary) {
    if (ACTUATOR_CALIBRATION_MODE) {
        buzzer_.silence();
        return;
    }

    // A completed rental always wins over a previously latched expiry.
    if (!rental.hasRental || rental.status == "returned" || rental.status == "cancelled") {
        const char* reason = !rental.hasRental ? (rental.bicycleStatus == "maintenance" ? "maintenance" : "idle") :
                             (rental.status == "returned" ? "returned" : "cancelled");
        const bool shouldLock = rental.bicycleLockStatus != "unlocked";
        const bool changed = lock_.writePhysical(
            shouldLock ? SERVO_LOCK_ANGLE : SERVO_RELEASE_ANGLE, reason);
        lock_.setLocked(shouldLock);
        lockMovementCompleted_ = false;
        buzzer_.silence();
        // Enforce runs every pass, so the servo line and its log are emitted
        // only when the target state actually flips - an idle bicycle does not
        // rewrite the same locked angle over and over.
        if (changed) {
            Serial.printf("[SAFETY] %s -> SERVO.write(%d)\n", shouldLock ? "LOCKED" : "UNLOCKED",
                          shouldLock ? SERVO_LOCK_ANGLE : SERVO_RELEASE_ANGLE);
        }
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
        const bool changed = lock_.writePhysical(SERVO_LOCK_ANGLE, "expired");
        lock_.setLocked(true);
        buzzer_.drive(true, timer_.graceSecondsLeft(), true, timer_.elapsedMs());
        if (changed) {
            Serial.printf("[SAFETY] EXPIRED -> SERVO.write(%d)\n", SERVO_LOCK_ANGLE);
        }
        return;
    }

    // A running rental and its final warning window stay rideable, but the last
    // GRADUAL_LOCK_SECONDS are where the lock is applied. While the bicycle is
    // still at the riding zone the servo walks from RELEASE toward LOCK in step
    // with the countdown, so the wheel closes on the deadline instead of
    // snapping shut; away from the zone there is nothing gentle left to
    // protect, so the wheel is locked outright. With more time than the window,
    // or with no deadline to count down to, the servo is released as always.
    //
    // Every command below goes through writePhysical's state-change guard: the
    // pin is only re-commanded when the target angle actually changes, so a
    // settled angle costs no PWM write and prints no log line.
    const int64_t remaining = rental.remainingSeconds();
    int targetAngle = SERVO_RELEASE_ANGLE;
    const char* reason = "active";
    bool inLockWindow = false;
    bool forced = false;

    if (rental.deadlineTs > 0 && remaining <= GRADUAL_LOCK_SECONDS) {
        inLockWindow = true;
        if (remaining <= 0) {
            // Unreachable while the rental is genuinely active (expired() wins
            // above), but a deadline in the past must never release the wheel.
            targetAngle = SERVO_LOCK_ANGLE;
            reason = "deadline-reached";
            forced = true;
        } else if (boundary == BoundaryStatus::Outside) {
            targetAngle = SERVO_LOCK_ANGLE;
            reason = "outside-boundary";
            forced = true;
        } else {
            // Linear ramp: RELEASE at the top of the window, LOCK at zero.
            const int64_t span = (int64_t)SERVO_RELEASE_ANGLE - (int64_t)SERVO_LOCK_ANGLE;
            targetAngle = (int)((int64_t)SERVO_LOCK_ANGLE +
                                span * remaining / GRADUAL_LOCK_SECONDS);
            reason = "gradual-lock";
        }
    }

    const bool changed = lock_.writePhysical(targetAngle, reason);
    lock_.setLocked(targetAngle == SERVO_LOCK_ANGLE);
    lockMovementCompleted_ = false;
    buzzer_.silence();
    if (changed) {
        if (inLockWindow) {
            Serial.printf("[SAFETY] %s remaining=%llds boundary=%s angle=%d -> SERVO.write(%d)\n",
                          forced ? "FORCED LOCK" : "GRADUAL LOCK",
                          (long long)remaining, boundaryStatusName(boundary),
                          targetAngle, targetAngle);
        } else {
            Serial.printf("[SAFETY] ACTIVE -> SERVO.write(%d)\n", targetAngle);
        }
    }
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