#pragma once

#include <Arduino.h>

#include "PedalyaApi.h"

class LcdDisplay {
public:
    void begin();
    void splash();
    void showIdle();
    void showWifiConnecting();
    void showNoServer(const String& reason);
    void showKeyRejected(const String& reason);
    void showRental(const RentalState& state);
    void showExpiryWarning(const RentalState& state);
    void showExpired();

    /**
     * The whole expired sequence, one screen per phase.
     *
     * `secondsSinceExpiry` is measured from the local expiry transition and
     * `penalty` is the projected peso figure; both come from RentalTimer. A 16x2
     * cannot show the heading, the overdue time and the penalty at once, so the
     * immediate expiry confirmation is preserved first, the return grace gets its
     * own screen, and the count-up time and peso then alternate.
     */
    void showExpiredPhase(uint32_t secondsSinceExpiry, float penalty);

    void showMessage(const String& line1, const String& line2);

    /** Shows a message for a fixed window, then hands the screen back. */
    void showMessageFor(uint32_t durationMs, const String& line1, const String& line2);

    /** True while a timed message still owns the screen. */
    bool messageActive() const;

    void setErrorLed(bool on);

private:
    void row(uint8_t index, const char* text);
    void rowf(uint8_t index, const char* format, ...);

    uint32_t messageUntilMs_ = 0;
};
