#pragma once

#include <Arduino.h>

#include "BuzzerCountdown.h"

/**
 * Every sound the bicycle can make, and the only code that touches BUZZER_PIN.
 *
 * Three shapes, in the order a rider meets them once a rental times out:
 *   1. EXPIRED_FLATLINE_MS of unbroken tone: the "your time is up" alarm.
 *   2. The free return window: countdown beeps that crowd together as the grace
 *      runs out, and stop entirely once the bicycle is back at the station.
 *   3. Once the grace lapses, the flatline returns and holds for good.
 *
 * The countdown is skipped and the flatline held whenever the link to the server
 * is down. An alarm that is mostly silent during a network outage is exactly the
 * case where it matters most, so no pattern is ever played on stale state.
 */
class BuzzerAlarm {
public:
    /** Configures the pin and leaves the buzzer physically silent. */
    void begin();

    /**
     * Picks the shape for the current state.
     *
     * `graceSecondsLeft` and `sinceExpiryMs` come from RentalTimer, `serverReachable`
     * decides whether a pattern may be played at all.
     */
    void drive(bool expired, uint32_t graceSecondsLeft, bool serverReachable,
               uint32_t sinceExpiryMs);

    /** Stops any pattern and forces the pin off. */
    void silence();

    /** True while the countdown beeps own the pin. */
    bool patternRunning() const { return patternRunning_; }

    /** Milliseconds until the next beep edge, for the main loop to sleep on. */
    uint32_t nextEdgeInMs() const { return countdown_.nextEdgeInMs(millis()); }

    bool on() const { return on_; }

private:
    /** The one and only place BUZZER_PIN is written. */
    void setOn(bool on);

    BuzzerCountdown countdown_;
    bool on_ = false;
    bool patternRunning_ = false;
};