#pragma once

#include <Arduino.h>

#include "BuzzerAlarm.h"
#include "Geofence.h"
#include "LockActuator.h"
#include "PedalyaApi.h"
#include "RentalTimer.h"

enum class ActuatorState : uint8_t { Unknown, Idle, Active, Warning, AwaitingReturn, Expired };

const char* actuatorStateName(ActuatorState value);

/**
 * Expiry, decided in exactly one place.
 *
 * Every consumer asks this object: the servo, the alarm and the LCD screen are
 * all downstream of the same transition, so they cannot disagree about whether
 * a rental is over.
 */
class RentalController {
public:
    RentalController(LockActuator& lock, BuzzerAlarm& buzzer, RentalTimer& timer);

    /**
     * Classifies the polled rental and drives the log lines.
     *
     * A failed poll never infers a state: the caller skips this entirely so the
     * actuators keep their last physical state and a network blip cannot unlock a
     * wheel or silence an expired alarm.
     */
    void update(const RentalState& rental, bool serverReachable);

    /**
     * Physically synchronise both actuators with the latest known rental state.
     *
     * Expired  -> servo LOCKED, buzzer sounding in the shape the alarm picks.
     * Active, more than GRADUAL_LOCK_SECONDS left -> servo RELEASED.
     * Active, GRADUAL_LOCK_SECONDS or less left -> servo walks from RELEASE
     *     toward LOCK in step with the countdown while `boundary` is Inside or
     *     Near the geofence, and is forced straight to LOCK when it is Outside.
     * Not expired -> buzzer OFF.
     *
     * `boundary` is the caller's classification of the current GPS fix against
     * the configured geofence (see evaluateBoundary); it only ever affects the
     * final window, never the rental, timer or alarm logic.
     *
     * No early return is permitted before the actuator commands: the main loop
     * calls this after the poll, after the server commands, and once more after
     * those commands, so neither a queued command nor a missed update can leave
     * a timed-out wheel unlocked or silent. The pin itself is re-commanded only
     * when the target angle changes (LockActuator::writePhysical holds that
     * guard), since the servo keeps its PWM running between calls; a reboot or
     * a servo re-attach clears the guard, so the first pass after either always
     * reaches the hardware again.
     */
    void enforce(const RentalState& rental, BoundaryStatus boundary);

    /**
     * The same safety rule during a network outage.
     *
     * The last servo position is preserved, but a locally known expiry is still
     * reasserted, so losing the server cannot defeat a lock or an alarm.
     */
    void maintainDuringNetworkFailure(const RentalState& rental);

    bool rentalIsExpired(const RentalState& rental) const;

    ActuatorState state() const { return state_; }
    const RentalTimer& timer() const { return timer_; }

private:
    void enterState(ActuatorState next);
    void logRemaining(int64_t remaining);

    LockActuator& lock_;
    BuzzerAlarm& buzzer_;
    RentalTimer& timer_;

    ActuatorState state_ = ActuatorState::Unknown;
    // Once a rental has expired it stays expired for this device until the rental
    // itself ends. Without this a later poll that still reports the rental as
    // active would walk the actuators back to Warning/Active and unlock a locked
    // wheel.
    bool expiryLatched_ = false;
    // Cleared whenever the bicycle is rideable again. This allows exactly one
    // deliberate rotation on each fresh expiry, while the safety loop continues
    // issuing the final holding command afterwards.
    bool lockMovementCompleted_ = false;

    // Only the "remaining" line is logged, and only when the value actually
    // changes, so the countdown reads as one line per second instead of five.
    bool remainingLogged_ = false;
    int64_t lastLoggedRemaining_ = 0;
};