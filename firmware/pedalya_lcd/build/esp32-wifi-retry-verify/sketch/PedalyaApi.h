#line 1 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\PedalyaApi.h"
#pragma once

#include <Arduino.h>

#include "config.h"

struct RentalState {
    bool    hasRental       = false;
    String  rentalId;
    String  status;
    String  riderName;
    String  deadlineBasis;
    String  penaltyBasis;
    String  penaltyRule;

    int64_t startTs          = 0;
    int64_t deadlineTs       = 0;
    int64_t serverUnix       = 0;
    uint32_t syncedAtMs      = 0;

    bool    isOverdue        = false;
    int32_t utcOffsetSeconds = DEFAULT_UTC_OFFSET_SECONDS;

    float   penaltyAccrued   = 0.0f;
    float   penaltyRuleValue = 0.0f;
    float   ratePerHour      = 0.0f;

    uint16_t pollAfterSeconds = DEFAULT_POLL_SECONDS;

    void clearRental() {
        hasRental     = false;
        rentalId      = "";
        status        = "";
        riderName     = "";
        deadlineBasis = "";
        penaltyBasis  = "";
        penaltyRule   = "";
        startTs       = 0;
        deadlineTs    = 0;
        isOverdue     = false;
        penaltyAccrued   = 0.0f;
        penaltyRuleValue = 0.0f;
    }

    int64_t serverNow() const {
        return serverUnix + (int64_t)((uint32_t)(millis() - syncedAtMs) / 1000UL);
    }

    int64_t elapsedSeconds() const {
        if (startTs <= 0) {
            return 0;
        }
        int64_t v = serverNow() - startTs;
        return v > 0 ? v : 0;
    }

    /**
     * Seconds until the return deadline. Negative once the deadline has passed.
     *
     * Reports 0 when there is no deadline at all, which is why expiry must be
     * decided with expired() rather than by testing this value directly: a bare
     * remainingSeconds() <= 0 would call an unscheduled rental expired.
     */
    int64_t remainingSeconds() const {
        if (deadlineTs <= 0) {
            return 0;
        }
        return deadlineTs - serverNow();
    }

    /**
     * True only once the rental is genuinely over.
     *
     * Either the server declared it expired, or a synchronized deadline has
     * actually been reached. The deadlineTs > 0 test is what keeps a rental that
     * has no deadline yet from looking expired, and keeping this in one place
     * means the lock, the alarm and the LCD can never disagree about it.
     */
    bool expired() const {
        if (status == "expired") {
            return true;
        }
        if (status != "active" || deadlineTs <= 0) {
            return false;
        }
        return remainingSeconds() <= 0;
    }

    int64_t overdueSeconds() const {
        if (deadlineTs <= 0) {
            return 0;
        }
        int64_t v = serverNow() - deadlineTs;
        return v > 0 ? v : 0;
    }
};

struct DeviceCommand {
    uint32_t id = 0;
    String command;
    String reason;
    String message;
    uint32_t durationMs = 0;
    int servoAngle = -1;
};

class PedalyaApi {
public:
    bool connected();
    void begin();
    void ensureTime();

    bool poll(RentalState& out, String& error);
    bool pollCommands(DeviceCommand* commands, size_t capacity, size_t& count, String& error);
    bool acknowledgeCommand(uint32_t commandId, bool executed, const String& result, String& error);

private:
    bool parse(const String& body, RentalState& out, String& error);
    bool parseCommands(const String& body, DeviceCommand* commands, size_t capacity, size_t& count, String& error);
    void reportFailure(int status, const String& body, String& error);

    bool     timeSynced = false;
    uint32_t lastNtpAttempt = 0;
    int32_t  appliedUtcOffset = -1;
    // WiFi.begin() changes the ESP32 station configuration. Calling it again
    // while an association is already in progress triggers the ESP-IDF
    // "sta is connecting, cannot set config" error and prevents a clean retry.
    bool     wifiAttemptInProgress = false;
    uint32_t wifiAttemptStartedMs = 0;
};
