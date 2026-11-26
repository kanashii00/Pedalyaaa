#include "LcdDisplay.h"

#include "config.h"

#include <LiquidCrystal_I2C.h>
#include <stdarg.h>
#include <stdio.h>
#include <string.h>

static LiquidCrystal_I2C lcd(I2C_ADDRESS, LCD_COLUMNS, LCD_ROWS);

static char previousRow[LCD_ROWS][LCD_COLUMNS + 1] = {{0}};

LcdDisplay::DisplayGuard::DisplayGuard(LcdDisplay& display) : display_(display) {
    display_.lockDisplay();
}

LcdDisplay::DisplayGuard::~DisplayGuard() {
    display_.unlockDisplay();
}

void LcdDisplay::lockDisplay() {
    if (displayMutex_ != nullptr) {
        xSemaphoreTakeRecursive(displayMutex_, portMAX_DELAY);
    }
}

void LcdDisplay::unlockDisplay() {
    if (displayMutex_ != nullptr) {
        xSemaphoreGiveRecursive(displayMutex_);
    }
}

void LcdDisplay::begin() {
    displayMutex_ = xSemaphoreCreateRecursiveMutex();
    if (displayMutex_ == nullptr) {
        Serial.println("[lcd] failed to create display mutex");
    }
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
    showStartup("INITIALIZING...", "PDY-001");
}

void LcdDisplay::showStartup(const char* step, const char* bicycleId) {
    if (messageActive()) return;
    const char* id = (bicycleId && bicycleId[0] != '\0') ? bicycleId : "PDY-001";
    char line0[17];
    snprintf(line0, sizeof(line0), "PEDALYA  %-7s", id);
    row(0, line0);
    row(1, step ? step : "STARTING...     ");
}

void LcdDisplay::showReady(const char* bicycleId, bool wifiUp, bool simReady, bool simActive,
                           bool serverVerified, bool locked, int batteryLevel, const char* bikeStatus) {
    if (messageActive()) return;

    // Row 0: Bicycle ID + Connectivity Indicators
    // Format: "PDY-001 W+ M. S+" (16 chars) or "PDY-001  4G:OK S+"
    const char* id = (bicycleId && bicycleId[0] != '\0') ? bicycleId : "PDY-001";
    char line0[17];
    if (simActive) {
        snprintf(line0, sizeof(line0), "%-7s  4G:OK %s", id, serverVerified ? "S+" : "S-");
    } else {
        const char* wStr = wifiUp ? "W+" : "W-";
        const char* mStr = simReady ? "M." : "  ";
        const char* sStr = serverVerified ? "S+" : "S-";
        snprintf(line0, sizeof(line0), "%-7s %s %s %s", id, wStr, mStr, sStr);
    }
    row(0, line0);

    // Row 1: Availability + Lock State + Real Battery
    // When bicycle is in maintenance:
    // Alternate every 2 seconds between "MAINTENANCE  LCK" and "NOT AVAILABLE   " (or "NOT AVAIL B:100%")
    const bool isMaintenance = (bikeStatus && strcmp(bikeStatus, "maintenance") == 0);
    char line1[17];

    if (isMaintenance) {
        uint32_t phase = (millis() / 2000UL) % 2;
        if (phase == 0) {
            snprintf(line1, sizeof(line1), "MAINTENANCE  LCK");
        } else {
            if (batteryLevel >= 0 && batteryLevel <= 100) {
                if (batteryLevel == 100) {
                    snprintf(line1, sizeof(line1), "NOT AVAIL B:100%%");
                } else {
                    snprintf(line1, sizeof(line1), "NOT AVAIL  B:%2d%%", batteryLevel);
                }
            } else {
                snprintf(line1, sizeof(line1), "NOT AVAILABLE   ");
            }
        }
    } else {
        const char* avail = "READY";
        const char* lck = locked ? "LCK" : "UNL";

        if (batteryLevel >= 0 && batteryLevel <= 100) {
            if (batteryLevel == 100) {
                snprintf(line1, sizeof(line1), "%-5s %s B:100%%", avail, lck);
            } else {
                snprintf(line1, sizeof(line1), "%-5s %s  B:%2d%%", avail, lck, batteryLevel);
            }
        } else {
            snprintf(line1, sizeof(line1), "%-5s   %s", avail, locked ? "  LOCKED" : "UNLOCKED");
        }
    }
    row(1, line1);
}

void LcdDisplay::showRental(const RentalState& state, bool locked) {
    DisplayGuard guard(*this);
    if (messageActive()) return;

    int64_t remaining = state.remainingSeconds();
    int64_t overdue = state.overdueSeconds();

    if (state.status == "awaiting_return" && remaining > 0) {
        row(0, locked ? "RETURN  LOCKED  " : "RETURN  UNLOCKED");
        long long h = (long long)(remaining / 3600);
        long long m = (long long)((remaining % 3600) / 60);
        long long s = (long long)(remaining % 60);
        rowf(1, "LEFT    %02lld:%02lld:%02lld", h % 100, m, s);
        return;
    }

    // Row 0: Rental status + Lock state (e.g. "RENTAL    UNLCKD" or "EXPIRING  UNLCKD")
    const char* lckStr = locked ? "LOCKED" : "UNLCKD";
    char r0[17];
    if (state.status == "active" && remaining > 0 && remaining <= EXPIRY_WARNING_SECONDS) {
        snprintf(r0, sizeof(r0), "EXPIRING  %-6s", lckStr);
    } else {
        snprintf(r0, sizeof(r0), "RENTAL    %-6s", lckStr);
    }
    row(0, r0);

    // Row 1: Remaining time in HH:MM:SS or overdue status
    if (overdue > 0) {
        rowf(1, "LATE    PHP%.2f", state.penaltyAccrued);
        return;
    }

    if (state.deadlineTs <= 0) {
        row(1, "OPEN ENDED RIDE ");
        return;
    }

    if (remaining < 0) remaining = 0;
    long long h = (long long)(remaining / 3600);
    long long m = (long long)((remaining % 3600) / 60);
    long long s = (long long)(remaining % 60);
    rowf(1, "LEFT    %02lld:%02lld:%02lld", h % 100, m, s);
}

void LcdDisplay::showExpiredPhase(uint32_t secondsSinceExpiry, float serverPenalty, bool locked,
                                  bool penaltyCurrent) {
    DisplayGuard guard(*this);
    if (messageActive()) return;

    if (secondsSinceExpiry < RETURN_GRACE_SECONDS) {
        row(0, locked ? "RETURN  LOCKED  " : "RETURN  UNLOCKED");

        const uint32_t graceLeft = RETURN_GRACE_SECONDS - secondsSinceExpiry;
        unsigned long m = (unsigned long)(graceLeft / 60);
        unsigned long s = (unsigned long)(graceLeft % 60);
        rowf(1, "GRACE   %02lu:%02lu   ", m, s);
        return;
    }

    const uint32_t lateSeconds = secondsSinceExpiry - RETURN_GRACE_SECONDS;
    if (((lateSeconds / 2) % 2) == 0) {
        row(0, locked ? "OVERDUE LOCKED  " : "OVERDUE UNLOCKED");
    } else {
        if (penaltyCurrent) {
            rowf(0, "FEE PHP%.2f", serverPenalty);
        } else {
            row(0, "FEE SYNCING...  ");
        }
    }

    unsigned long h = (unsigned long)(lateSeconds / 3600);
    unsigned long m = (unsigned long)((lateSeconds % 3600) / 60);
    unsigned long s = (unsigned long)(lateSeconds % 60);
    rowf(1, "LATE    %02lu:%02lu:%02lu", h % 100, m, s);
}

void LcdDisplay::showExpiredDeadline(int64_t graceSecondsLeft, int64_t overdueSeconds,
                                     float serverPenalty, bool locked, bool penaltyCurrent) {
    DisplayGuard guard(*this);
    if (messageActive()) return;

    if (graceSecondsLeft > 0) {
        row(0, locked ? "RETURN  LOCKED  " : "RETURN  UNLOCKED");

        const unsigned long m = (unsigned long)(graceSecondsLeft / 60);
        const unsigned long s = (unsigned long)(graceSecondsLeft % 60);
        rowf(1, "GRACE   %02lu:%02lu   ", m, s);
        return;
    }

    const uint32_t lateSeconds = overdueSeconds > 0
        ? (uint32_t)overdueSeconds
        : 0;
    if (((lateSeconds / 2) % 2) == 0) {
        row(0, locked ? "OVERDUE LOCKED  " : "OVERDUE UNLOCKED");
    } else {
        if (penaltyCurrent) {
            rowf(0, "FEE PHP%.2f", serverPenalty);
        } else {
            row(0, "FEE SYNCING...  ");
        }
    }

    const unsigned long h = (unsigned long)(lateSeconds / 3600);
    const unsigned long m = (unsigned long)((lateSeconds % 3600) / 60);
    const unsigned long s = (unsigned long)(lateSeconds % 60);
    rowf(1, "LATE    %02lu:%02lu:%02lu", h % 100, m, s);
}

void LcdDisplay::showConnectionError(const char* line1, const char* line2) {
    if (messageActive()) return;
    row(0, line1 ? line1 : "CONNECTION ERROR");
    row(1, line2 ? line2 : "RECONNECTING... ");
}

void LcdDisplay::showAccidentAlert(bool reported, bool hasGps) {
    DisplayGuard guard(*this);
    row(0, "! ACCIDENT ALERT");
    if (reported) {
        row(1, hasGps ? "ADMIN NOTIF +GPS" : "ADMIN NOTIFIED  ");
    } else {
        row(1, "ALERTING ADMIN..");
    }
}

void LcdDisplay::showStatus(SystemStatus status, const char* bicycleId) {
    if (messageActive()) return;
    const char* id = (bicycleId && bicycleId[0] != '\0') ? bicycleId : "PDY-001";
    char line0[17];
    snprintf(line0, sizeof(line0), "PEDALYA  %-7s", id);
    row(0, line0);

    switch (status) {
        case SystemStatus::Starting:
            row(1, "STARTING...     ");
            break;
        case SystemStatus::WifiConnecting:
            row(1, "WIFI CONNECTING ");
            break;
        case SystemStatus::WifiConnected:
            row(1, "WIFI CONNECTED  ");
            break;
        case SystemStatus::ServerCheck:
            row(1, "SERVER CHECK... ");
            break;
        case SystemStatus::ServerOnline:
            row(1, "READY TO RIDE   ");
            break;
        case SystemStatus::ServerOffline:
            row(1, "SERVER OFFLINE  ");
            break;
        case SystemStatus::ModemConnecting:
            row(1, "MODEM CONNECTING");
            break;
        case SystemStatus::ModemOnline:
            row(1, "MODEM ONLINE    ");
            break;
        case SystemStatus::ModemOffline:
            row(1, "MODEM OFFLINE   ");
            break;
        case SystemStatus::AuthFailed:
            row(1, "AUTH FAILED     ");
            break;
        case SystemStatus::NoNetwork:
            row(1, "NO NETWORK LINK ");
            break;
        case SystemStatus::ModemInit:
            row(1, "MODEM INIT      ");
            break;
        case SystemStatus::WaitingForService:
            row(1, "WAITING SERVICE ");
            break;
        case SystemStatus::CellularConnecting:
            row(1, "CELL CONNECTING ");
            break;
        case SystemStatus::CellularOnline:
            row(1, "CELLULAR ONLINE ");
            break;
        case SystemStatus::ApiOffline:
            row(1, "API OFFLINE     ");
            break;
        case SystemStatus::Reconnecting:
            row(1, "RECONNECTING... ");
            break;
    }
}

void LcdDisplay::showServerState(bool serverVerified) {
    showStatus(serverVerified ? SystemStatus::ServerOnline : SystemStatus::ServerOffline);
}

void LcdDisplay::showIdle() {
    row(0, "PEDALYA  PDY-001");
    row(1, "READY TO RIDE   ");
}

void LcdDisplay::showWifiConnecting() {
    row(0, "PEDALYA  PDY-001");
    row(1, "WIFI CONNECTING ");
}

void LcdDisplay::showDeviceStatus(const char* line2) {
    row(0, "PEDALYA  PDY-001");
    row(1, line2 ? line2 : "                ");
}

void LcdDisplay::showNoServer(const String& reason) {
    row(0, "SERVER OFFLINE  ");
    row(1, reason.c_str());
}

void LcdDisplay::showKeyRejected(const String& reason) {
    row(0, "SERVER REJECTED ");
    row(1, "CHECK DEVICE KEY");
    (void)reason;
}

void LcdDisplay::showExpiryWarning(const RentalState& state) {
    int64_t remaining = state.remainingSeconds();
    row(0, "EXPIRING  UNLCKD");
    if (remaining < 0) remaining = 0;
    rowf(1, "LEFT    %02lld:%02lld   ", (long long)(remaining / 60) % 100,
         (long long)(remaining % 60));
}

void LcdDisplay::showExpired() {
    row(0, "EXPIRED   LOCKED");
    row(1, "LOCK ENGAGED    ");
}

void LcdDisplay::showMessage(const String& line1, const String& line2) {
    DisplayGuard guard(*this);
    row(0, line1.c_str());
    row(1, line2.c_str());
}

void LcdDisplay::showMessageFor(uint32_t durationMs, const String& line1, const String& line2) {
    DisplayGuard guard(*this);
    messageUntilMs_ = millis() + durationMs;
    showMessage(line1, line2);
}

bool LcdDisplay::messageActive() const {
    return messageUntilMs_ > 0 && (int32_t)(millis() - messageUntilMs_) < 0;
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
