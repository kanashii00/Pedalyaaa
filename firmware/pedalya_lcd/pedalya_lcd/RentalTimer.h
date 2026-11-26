#pragma once

#include <Arduino.h>

/**
 * The on-device overdue clock.
 *
 * Tracks the grace countdown used by the return alarm. Rental penalties are
 * calculated by the server and are never projected by this timer.
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

    /** Recomputes elapsed seconds for the grace alarm. */
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

private:
    bool active_ = false;
    uint32_t startMs_ = 0;
    uint32_t seconds_ = 0;
};