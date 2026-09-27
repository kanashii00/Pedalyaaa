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

void LcdDisplay::showMessage(const String& line1, const String& line2) {
    row(0, line1.c_str());
    row(1, line2.c_str());
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
