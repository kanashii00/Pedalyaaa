#pragma once

#include <Arduino.h>

#include "config.h"

inline uint32_t buzzerCountdownGapMs(uint32_t graceSecondsLeft) {
    const uint32_t fullGrace = RETURN_GRACE_SECONDS > 0 ? RETURN_GRACE_SECONDS : 1;
    if (graceSecondsLeft <= 1 || fullGrace <= 1) return RETURN_COUNTDOWN_GAP_END_MS;
    if (graceSecondsLeft >= fullGrace) return RETURN_COUNTDOWN_GAP_START_MS;

    const uint32_t span = RETURN_COUNTDOWN_GAP_START_MS - RETURN_COUNTDOWN_GAP_END_MS;
    return RETURN_COUNTDOWN_GAP_END_MS +
           (span * (graceSecondsLeft - 1)) / (fullGrace - 1);
}

class BuzzerCountdown {
public:
    bool active(uint32_t now, uint32_t graceSecondsLeft) {
        if (graceSecondsLeft == 0) {
            reset();
            return false;
        }

        if (!started_) {
            started_ = true;
            toneOn_ = true;
            phaseStartedMs_ = now;
            graceSecondsLeft_ = graceSecondsLeft;
            return true;
        }

        graceSecondsLeft_ = graceSecondsLeft;
        const uint32_t phaseDuration = toneOn_
            ? RETURN_COUNTDOWN_TONE_MS
            : buzzerCountdownGapMs(graceSecondsLeft);

        if ((uint32_t)(now - phaseStartedMs_) >= phaseDuration) {
            toneOn_ = !toneOn_;
            phaseStartedMs_ = now;
        }

        return toneOn_;
    }

    uint32_t nextEdgeInMs(uint32_t now) const {
        if (!started_) return 0;

        const uint32_t duration = toneOn_
            ? RETURN_COUNTDOWN_TONE_MS
            : buzzerCountdownGapMs(graceSecondsLeft_);
        const uint32_t elapsed = (uint32_t)(now - phaseStartedMs_);
        return elapsed >= duration ? 0 : duration - elapsed;
    }

    void reset() {
        started_ = false;
        toneOn_ = false;
        phaseStartedMs_ = 0;
        graceSecondsLeft_ = 0;
    }

private:
    bool started_ = false;
    bool toneOn_ = false;
    uint32_t phaseStartedMs_ = 0;
    uint32_t graceSecondsLeft_ = 0;
};
