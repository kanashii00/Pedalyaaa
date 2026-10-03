#pragma once

#include <Arduino.h>

/**
 * The on-device overdue clock.
 *
 * Starts the moment a rental expires and counts upward. The first
 * RETURN_GRACE_SECONDS are free; each started PENALTY_INTERVAL_SECONDS after
 * that adds PENALTY_PER_INTERVAL. It only ever projects a figure for the display
 * and the alarm: the next poll replaces it with the server's own numbers.
 */
class RentalTimer {
public:
    bool active() const { return active_; }

    /**
     * Begins the countdown from a server-reported lateness.
     *
     * The offset matters on a unit that boots mid-rental: without it the grace
     * window would restart from zero on every power cycle.
     */
    void start(uint32_t serverOverdueSeconds);

    void reset();

    /** Recomputes the elapsed seconds and the projected penalty. */
    void update();

    /** Seconds since the local expiry transition. */
    uint32_t seconds() const { return seconds_; }

    /**
     * Free seconds left before penalties apply, 0 once the window has lapsed.
     *
     * At exactly RETURN_GRACE_SECONDS the window is spent, so the alarm stops
     * there. The first peso only becomes payable on the next second.
     */
    uint32_t graceSecondsLeft() const;

    /** Milliseconds since the transition, 0 while inactive. */
    uint32_t elapsedMs() const;

    float penalty() const { return penalty_; }

private:
    bool active_ = false;
    uint32_t startMs_ = 0;
    uint32_t seconds_ = 0;
    float penalty_ = 0.0f;
};