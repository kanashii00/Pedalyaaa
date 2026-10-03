#include "LcdDisplay.h"

#include "config.h"

#include <LiquidCrystal_I2C.h>
#include <math.h>
#include <stdarg.h>
#include <stdio.h>
#include <string.h>

static LiquidCrystal_I2C lcd(I2C_ADDRESS, LCD_COLUMNS, LCD_ROWS);

static char previousRow[LCD_ROWS][LCD_COLUMNS + 1] = {{0}};

/**
 * Whole hours charged for a given lateness, minimum one.
 *
 * Mirrors the server: the minutes are truncated to whole minutes first and only
 * then rounded up to hours, so 150 minutes late bills 3 hours, not 2.5.
 */
static long long chargedHours(int64_t overdueSeconds) {
    long long minutes = (long long)(overdueSeconds / 60);
    long long hours = (long long)ceil((double)minutes / 60.0);
    return hours < 1 ? 1 : hours;
}

/**
 * Penalty the rider is accruing right now.
 *
 * Reproduces RentalService on the device so the figure moves every second
 * between polls. It is a projection, not an authority: the next poll overwrites
 * it with the server's own number, so a display can never settle on a total the
 * server would not charge.
 */
static float projectPenalty(const RentalState& state, int64_t overdueSeconds) {
    if (overdueSeconds <= 0) {
        return 0.0f;
    }

    if (state.penaltyBasis == "expected_end_time") {
        return (float)chargedHours(overdueSeconds) * (state.ratePerHour > 0 ? state.ratePerHour : 0.0f);
    }

    float value = state.penaltyRuleValue > 0 ? state.penaltyRuleValue : 0.0f;

    if (state.penaltyRule == "fixed") {
        return value;
    }
    if (state.penaltyRule == "per_minute") {
        return (float)(overdueSeconds / 60) * value;
    }

    return (float)chargedHours(overdueSeconds) * value;
}

void LcdDisplay::begin() {
    lcd.init();
#if BACKLIGHT_PIN >= 0
    pinMode(BACKLIGHT_PIN, OUTPUT);
    digitalWrite(BACKLIGHT_PIN, BACKLIGHT_ON ? HIGH : LOW);
#else
    lcd.backlight();
#endif
    lcd.clear();

    for (uint8_t i = 0; i < LCD_ROWS; i++) {
        previousRow[i][0] = '\0';
    }
}

void LcdDisplay::row(uint8_t index, const char* text) {
    char padded[LCD_COLUMNS + 1];
    size_t i = 0;

    for (; i < LCD_COLUMNS && text[i] != '\0'; i++) {
        padded[i] = text[i];
    }
    for (; i < LCD_COLUMNS; i++) {
        padded[i] = ' ';
    }
    padded[LCD_COLUMNS] = '\0';

    // The loop redraws several times a second, and most frames are identical.
    // Skipping unchanged rows keeps the countdown at one I2C write per second
    // instead of one per frame.
    if (index < LCD_ROWS && strcmp(previousRow[index], padded) == 0) {
        return;
    }

    if (index < LCD_ROWS) {
        strncpy(previousRow[index], padded, LCD_COLUMNS + 1);
    }

    lcd.setCursor(0, index);
    lcd.print(padded);
}

void LcdDisplay::rowf(uint8_t index, const char* format, ...) {
    char buffer[40];
    va_list args;
    va_start(args, format);
    vsnprintf(buffer, sizeof(buffer), format, args);
    va_end(args);
    row(index, buffer);
}

void LcdDisplay::splash() {
    row(0, "   PEDALYA");
    row(1, "  starting...");
}

void LcdDisplay::showIdle() {
    row(0, "PEDALYA");
    row(1, "READY TO RIDE");
}

void LcdDisplay::showWifiConnecting() {
    row(0, "PEDALYA");
    row(1, "connecting...");
}

void LcdDisplay::showNoServer(const String& reason) {
    row(0, "NO SERVER");
    row(1, reason.c_str());
}

void LcdDisplay::showKeyRejected(const String& reason) {
    // Deliberately not "NO SERVER": the server answered, it refused this unit.
    // Saying otherwise sends whoever is holding the bike looking at the WiFi.
    row(0, "SERVER REJECTED");
    row(1, "CHECK DEVICE KEY");
    (void)reason;
}

void LcdDisplay::showMessage(const String& line1, const String& line2) {
    row(0, line1.c_str());
    row(1, line2.c_str());
}

void LcdDisplay::showMessageFor(uint32_t durationMs, const String& line1, const String& line2) {
    messageUntilMs_ = millis() + durationMs;
    showMessage(line1, line2);
}

bool LcdDisplay::messageActive() const {
    // Unsigned arithmetic on purpose: it stays correct across the millis() wrap.
    return messageUntilMs_ > 0 && (int32_t)(millis() - messageUntilMs_) < 0;
}

void LcdDisplay::showExpiryWarning(const RentalState& state) {
    int64_t remaining = state.remainingSeconds();
    row(0, "RENTAL ENDING");
    rowf(1, "LEFT %02lld:%02lld", (long long)(remaining / 60) % 100,
         (long long)(remaining % 60));
}

void LcdDisplay::showExpired() {
    row(0, "RENTAL EXPIRED");
    row(1, "LOCK ENGAGED");
}

void LcdDisplay::showExpiredPhase(uint32_t secondsSinceExpiry, float penalty) {
    if (secondsSinceExpiry < 3) {
        showExpired();
        return;
    }

    if (secondsSinceExpiry <= RETURN_GRACE_SECONDS) {
        // Counts down to zero, so it never alternates: the whole point of this
        // screen is one number the rider can watch fall. The bound is inclusive
        // because the timer also treats exactly RETURN_GRACE_SECONDS as still
        // free, so the display changes to OVERDUE on the exact second the first
        // peso becomes payable.
        const uint32_t graceLeft = RETURN_GRACE_SECONDS - secondsSinceExpiry;
        row(0, "RETURN BICYCLE");
        rowf(1, "RETURN IN %02lu:%02lu", (unsigned long)(graceLeft / 60),
             (unsigned long)(graceLeft % 60));
        return;
    }

    row(0, "RETURN BICYCLE");

    if (((secondsSinceExpiry / 2) % 2) == 0) {
        // Counted from the end of the grace, not from expiry, so the screen does
        // not jump from "RETURN IN 00:00" straight to "OVERDUE 05:01" on the
        // first penalised second. The rider is one second late, not five minutes.
        const uint32_t lateSeconds = secondsSinceExpiry - RETURN_GRACE_SECONDS;
        rowf(1, "OVERDUE %02lu:%02lu", (unsigned long)(lateSeconds / 60),
             (unsigned long)(lateSeconds % 60));
        return;
    }

    rowf(1, "PENALTY P%.0f", penalty);
}

void LcdDisplay::showRental(const RentalState& state) {
    int64_t elapsed = state.elapsedSeconds();
    int64_t remaining = state.remainingSeconds();
    int64_t overdue = state.overdueSeconds();

    rowf(0, "RIDE %02lld:%02lld:%02lld",
         (long long)(elapsed / 3600) % 100,
         (long long)((elapsed / 60) % 60),
         (long long)(elapsed % 60));

    if (overdue > 0) {
        rowf(1, "LATE PHP%.2f", projectPenalty(state, overdue));
        return;
    }

    if (state.deadlineTs <= 0) {
        row(1, "NO DEADLINE");
        return;
    }

    rowf(1, "LEFT %02lld:%02lld:%02lld",
         (long long)(remaining / 3600) % 100,
         (long long)((remaining / 60) % 60),
         (long long)(remaining % 60));
}

/**
 * Solid error indicator, driven explicitly rather than toggled.
 *
 * Toggling on each call left the LED latched on forever after a single failed
 * poll, so a rider could be left with a lit LED and a healthy countdown.
 */
void LcdDisplay::setErrorLed(bool on) {
#if STATUS_LED_ON_ERROR
    if (STATUS_LED_PIN < 0) {
        return;
    }

    pinMode(STATUS_LED_PIN, OUTPUT);
    digitalWrite(STATUS_LED_PIN, (on == STATUS_LED_ACTIVE_HIGH) ? HIGH : LOW);
#else
    (void)on;
#endif
}
