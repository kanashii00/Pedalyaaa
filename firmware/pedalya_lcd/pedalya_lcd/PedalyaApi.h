#pragma once

#include <Arduino.h>

#include "config.h"
#include <ArduinoJson.h>

struct RentalState {
    bool    hasRental       = false;
    String  rentalId;
    String  status;
    String  riderName;
    String  deadlineBasis;
    String  penaltyBasis;
    String  penaltyRule;
    String  bicycleLockStatus;
    String  bicycleStatus;

    int64_t startTs          = 0;
    int64_t deadlineTs       = 0;
    uint32_t serverOverdueSeconds = 0;
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
        serverOverdueSeconds = 0;
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
        if (status == "expired" || status == "overdue" || status == "overdue_return") {
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
    /**
     * HTTP status behind the most recent failure, or 0 if the call succeeded.
     *
     * A 401 or 403 is a provisioning mistake rather than a transient fault: the
     * same key will be rejected just as firmly a minute later, so a caller that
     * retries on it only burns battery and delays the report. Reset at the top
     * of each request so the value always describes the call just made.
     */
    int lastStatus() const { return lastStatus_; }
    bool connected();
    void begin();
    void ensureTime();

    bool poll(RentalState& out, String& error);
    bool pollCommands(DeviceCommand* commands, size_t capacity, size_t& count, String& error);
    bool acknowledgeCommand(uint32_t commandId, bool executed, const String& result, String& error);
    bool sendHeartbeat(bool locked, bool lockStateKnown, uint32_t uptimeSeconds, String& error);
    bool sendGps(double latitude, double longitude, float speedKph, float heading, String& error);

    /**
     * Report a possible accident to the backend.
     *
     * Posts to ACCIDENT_REPORT_PATH, so it is authenticated by the same
     * per-bicycle device key as the rental, heartbeat and GPS calls, and the
     * backend derives the bicycle from that key rather than from the payload.
     *
     * The coordinates are only sent when `hasFix` is true. An accident during a
     * GPS outage still has to reach the admin, so the report is posted without
     * a location instead of being withheld or rejected as invalid.
     *
     * @param latitude      Latest latitude, used only when hasFix is true.
     * @param longitude     Latest longitude, used only when hasFix is true.
     * @param hasFix        Whether the GPS fix was valid at that moment.
     * @param impactG       Peak acceleration magnitude of the event, in g.
     * @param rotationDps   Peak rotation rate of the event, in degrees/second.
     * @param tiltDegrees   How far the bike was from upright, in degrees.
     * @param accelXG       Accelerometer X axis at that sample, in g.
     * @param accelYG       Accelerometer Y axis at that sample, in g.
     * @param accelZG       Accelerometer Z axis at that sample, in g.
     * @param error         On failure, this is set to a descriptive string.
     * @return true when the backend accepted the report.
     */
    bool reportAccident(double latitude,
                        double longitude,
                        bool hasFix,
                        float impactG,
                        float rotationDps,
                        float tiltDegrees,
                        float accelXG,
                        float accelYG,
                        float accelZG,
                        String& error);

private:
    bool parse(const String& body, RentalState& out, String& error);
    bool parseCommands(const String& body, DeviceCommand* commands, size_t capacity, size_t& count, String& error);
    bool postJson(const char* path, const String& body, String& error);
    void reportFailure(int status, const String& body, String& error);

    // Status of the last request, so a caller can tell a permanent rejection
    // from something worth retrying.
    int      lastStatus_ = 0;

    bool     timeSynced = false;
    uint32_t lastNtpAttempt = 0;
    int32_t  appliedUtcOffset = -1;
    // WiFi.begin() changes the ESP32 station configuration. Calling it again
    // while an association is already in progress triggers the ESP-IDF
    // "sta is connecting, cannot set config" error and prevents a clean retry.
    bool     wifiAttemptInProgress = false;
    uint32_t wifiAttemptStartedMs = 0;
};
