#include "PedalyaApi.h"

#include "config.h"

#include <ArduinoJson.h>
#include <HTTPClient.h>
#include <WiFi.h>
#include <WiFiClient.h>
#include <time.h>

#if USE_HTTPS
#include <WiFiClientSecure.h>
#endif

bool PedalyaApi::connected() {
    return WiFi.status() == WL_CONNECTED;
}

void PedalyaApi::begin() {
    WiFi.mode(WIFI_STA);
    WiFi.setSleep(false);
    WiFi.setAutoReconnect(true);

    if (connected()) {
        wifiAttemptInProgress = false;
        return;
    }

    uint32_t now = millis();
    if (wifiAttemptInProgress) {
        if ((uint32_t)(now - wifiAttemptStartedMs) < WIFI_CONNECT_TIMEOUT_MS) {
            Serial.println("[wifi] connection attempt still in progress");
            return;
        }

        // Retain the configured credentials (the first false) and do not
        // erase them from NVS (the second false). This is only reached after
        // a whole attempt timed out, never while the ESP32 is associating.
        Serial.println("[wifi] connection timed out; restarting attempt");
        WiFi.disconnect(false, false);
        wifiAttemptInProgress = false;
    }

    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
    wifiAttemptInProgress = true;
    wifiAttemptStartedMs = now;
    Serial.printf("[wifi] connecting to \"%s\"\n", WIFI_SSID);
}

void PedalyaApi::ensureTime() {
    if (timeSynced) {
        return;
    }

    uint32_t now = millis();
    if (lastNtpAttempt != 0 && (uint32_t)(now - lastNtpAttempt) < 60000UL) {
        return;
    }
    lastNtpAttempt = now;

    // Reuse the offset the server reported, otherwise this would overwrite it
    // with the compile-time default on every attempt.
    configTime(appliedUtcOffset >= 0 ? appliedUtcOffset : DEFAULT_UTC_OFFSET_SECONDS, 0, NTP_SERVER);

    struct tm timeInfo;
    if (getLocalTime(&timeInfo, 200)) {
        char stamp[32];
        strftime(stamp, sizeof(stamp), "%Y-%m-%d %H:%M:%S", &timeInfo);
        Serial.printf("[ntp] clock set to %s\n", stamp);
        timeSynced = true;
    } else {
        Serial.println("[ntp] not available yet; relying on server clock");
    }
}

/**
 * Explains a failed poll on both channels.
 *
 * `error` is what the 16x2 LCD shows, so it stays short. The Serial log gets
 * the full reason, because "auth failed" on glass is useless for diagnosing a
 * provisioning mistake without the detail behind it.
 */
void PedalyaApi::reportFailure(int status, const String& body, String& error) {
    char shortReason[20];

    // Laravel returns {"message":"..."} for API errors. A 404 does not: implicit
    // model binding rejects an unknown bicycle before the middleware runs, so the
    // body is the HTML error page and there is nothing to parse.
    String detail;
    JsonDocument doc;
    if (!deserializeJson(doc, body)) {
        detail = doc["message"] | "";
    }

    switch (status) {
        case 0:
        case -1:
            error = "connect failed";
            Serial.printf("[api] cannot reach %s:%d\n", SERVER_HOST, SERVER_PORT);
            Serial.println("      Check the PC is on the same Wi-Fi, that php artisan serve");
            Serial.println("      was started with --host=0.0.0.0, and that Windows Firewall");
            Serial.println("      allows inbound TCP 8000.");
            return;

        case 401:
            error = "auth 401";
            Serial.println("[api] 401 Unauthorized");
            if (detail.length()) {
                Serial.printf("      server said: %s\n", detail.c_str());
            }
            if (strlen(DEVICE_API_KEY) == 0) {
                Serial.println("      No key was sent. Put the value printed by");
                Serial.println("        php artisan devices:provision-key 1");
                Serial.println("      into pedalya_lcd/secrets.h as DEVICE_API_KEY.");
            } else {
                Serial.println("      The key was sent but rejected. It must be the key of the");
                Serial.println("      bicycle in SERVER_PATH; a valid key for another bicycle");
                Serial.println("      is rejected too. Reissue with --rotate if unsure.");
            }
            return;

        case 403:
            error = "auth 403";
            Serial.println("[api] 403 Forbidden - key authenticated but not permitted");
            if (detail.length()) {
                Serial.printf("      server said: %s\n", detail.c_str());
            }
            return;

        case 404:
            error = "404 not found";
            Serial.println("[api] 404 Not Found");
            Serial.println("      SERVER_PATH does not resolve, or the bicycle id in it does");
            Serial.println("      not exist. This is a routing problem, not a key problem.");
            return;

        case 429:
            error = "rate limited";
            Serial.println("[api] 429 Too Many Requests - polling faster than the throttle allows");
            return;
    }

    if (status >= 500) {
        error = "server error";
        Serial.printf("[api] %d from Laravel - the request authenticated but the server failed\n", status);
        if (detail.length()) {
            Serial.printf("      server said: %s\n", detail.c_str());
        } else if (body.length()) {
            Serial.printf("      body: %.120s\n", body.c_str());
        }
        return;
    }

    snprintf(shortReason, sizeof(shortReason), "http %d", status);
    error = shortReason;
    Serial.printf("[api] unexpected HTTP %d\n", status);
    if (detail.length()) {
        Serial.printf("      server said: %s\n", detail.c_str());
    }
}

bool PedalyaApi::poll(RentalState& out, String& error) {
    if (!connected()) {
        error = "wifi down";
        return false;
    }

    if (strlen(DEVICE_API_KEY) == 0) {
        error = "no api key";
        Serial.println("[api] DEVICE_API_KEY is empty, so no key can be sent.");
        Serial.println("      Add it to pedalya_lcd/secrets.h, from the output of");
        Serial.println("        php artisan devices:provision-key 1");
        return false;
    }

    ensureTime();

#if USE_HTTPS
    WiFiClientSecure client;
    client.setInsecure();
    if (strlen(SERVER_FINGERPRINT) > 0) {
        client.setFingerprint(SERVER_FINGERPRINT);
    }
#else
    WiFiClient client;
#endif

    HTTPClient http;
    char url[160];
    snprintf(url, sizeof(url), "%s://%s:%d%s",
             USE_HTTPS ? "https" : "http", SERVER_HOST, SERVER_PORT, SERVER_PATH);

    if (!http.begin(client, url)) {
        error = "begin failed";
        Serial.printf("[api] could not build a request for %s\n", url);
        return false;
    }

    http.setTimeout(REQUEST_TIMEOUT_MS);
    http.addHeader("X-API-Key", DEVICE_API_KEY);
    http.addHeader("Accept", "application/json");

    int status = http.GET();
    String body = http.getString();
    http.end();

    // Temporary wire-level diagnostic for a successful device poll. This logs
    // only the response body; request headers (including X-API-Key) are never
    // printed.
    Serial.println("[api] RAW RESPONSE:");
    Serial.println(body);

    if (status != 200) {
        reportFailure(status, body, error);
        return false;
    }

    Serial.printf("[api] 200 OK from %s\n", url);

    if (body.length() == 0 || body.length() > (int)HTTP_RESPONSE_BUFFER) {
        error = "bad size";
        Serial.printf("[api] unexpected body length %u\n", (unsigned)body.length());
        return false;
    }

    return parse(body, out, error);
}

bool PedalyaApi::pollCommands(DeviceCommand* commands, size_t capacity, size_t& count, String& error) {
    count = 0;
    if (!connected()) {
        error = "wifi down";
        return false;
    }

    if (strlen(DEVICE_API_KEY) == 0) {
        error = "no api key";
        return false;
    }

#if USE_HTTPS
    WiFiClientSecure client;
    client.setInsecure();
    if (strlen(SERVER_FINGERPRINT) > 0) {
        client.setFingerprint(SERVER_FINGERPRINT);
    }
#else
    WiFiClient client;
#endif

    HTTPClient http;
    char url[160];
    snprintf(url, sizeof(url), "%s://%s:%d%s",
             USE_HTTPS ? "https" : "http", SERVER_HOST, SERVER_PORT, COMMANDS_PATH);

    if (!http.begin(client, url)) {
        error = "command begin";
        return false;
    }

    http.setTimeout(REQUEST_TIMEOUT_MS);
    http.addHeader("X-API-Key", DEVICE_API_KEY);
    http.addHeader("Accept", "application/json");
    int status = http.GET();
    String body = http.getString();
    http.end();

    if (status != 200) {
        reportFailure(status, body, error);
        return false;
    }

    if (body.length() == 0 || body.length() > (int)HTTP_RESPONSE_BUFFER) {
        error = "command size";
        return false;
    }

    return parseCommands(body, commands, capacity, count, error);
}

bool PedalyaApi::acknowledgeCommand(uint32_t commandId, bool executed, const String& result, String& error) {
    if (!connected()) {
        error = "wifi down";
        return false;
    }

#if USE_HTTPS
    WiFiClientSecure client;
    client.setInsecure();
    if (strlen(SERVER_FINGERPRINT) > 0) {
        client.setFingerprint(SERVER_FINGERPRINT);
    }
#else
    WiFiClient client;
#endif

    HTTPClient http;
    char url[196];
    snprintf(url, sizeof(url), "%s://%s:%d%s/%lu/ack",
             USE_HTTPS ? "https" : "http", SERVER_HOST, SERVER_PORT, COMMANDS_PATH,
             (unsigned long)commandId);

    if (!http.begin(client, url)) {
        error = "ack begin";
        return false;
    }

    JsonDocument payload;
    payload["status"] = executed ? "executed" : "failed";
    payload["result"] = result;
    String body;
    serializeJson(payload, body);

    http.setTimeout(REQUEST_TIMEOUT_MS);
    http.addHeader("X-API-Key", DEVICE_API_KEY);
    http.addHeader("Accept", "application/json");
    http.addHeader("Content-Type", "application/json");
    int status = http.POST(body);
    String response = http.getString();
    http.end();

    if (status < 200 || status >= 300) {
        reportFailure(status, response, error);
        return false;
    }
    return true;
}

bool PedalyaApi::parse(const String& body, RentalState& out, String& error) {
    JsonDocument doc;
    if (deserializeJson(doc, body)) {
        error = "bad json";
        Serial.printf("[api] parse failed: %s\n", body.c_str());
        return false;
    }

    JsonObjectConst data = doc["data"].as<JsonObjectConst>();
    if (data.isNull()) {
        error = "no data";
        return false;
    }

    JsonObjectConst clock = data["clock"].as<JsonObjectConst>();
    if (!clock.isNull()) {
        out.serverUnix = clock["unix"].as<int64_t>();
        out.utcOffsetSeconds = clock["utcOffsetSeconds"] | DEFAULT_UTC_OFFSET_SECONDS;
    }
    out.syncedAtMs = millis();

    // Adopt the server's own UTC offset so local time and the log timestamps
    // match Asia/Manila without hardcoding the timezone on the device.
    if (out.utcOffsetSeconds != appliedUtcOffset) {
        configTime(out.utcOffsetSeconds, 0, NTP_SERVER);
        appliedUtcOffset = out.utcOffsetSeconds;
        timeSynced = false;
        Serial.printf("[clock] utc offset set to %d seconds\n", out.utcOffsetSeconds);
    }

    uint16_t requested = data["pollAfterSeconds"] | (uint16_t)DEFAULT_POLL_SECONDS;
    out.pollAfterSeconds = requested < 5 ? 5 : requested;

    JsonObjectConst rental = data["rental"].as<JsonObjectConst>();
    if (rental.isNull()) {
        out.clearRental();
        Serial.printf("[api] parsed hasRental=%d status=%s start=%lld deadline=%lld overdue=%d\n",
                      out.hasRental ? 1 : 0, out.status.c_str(), (long long)out.startTs,
                      (long long)out.deadlineTs, out.isOverdue ? 1 : 0);
        Serial.println("[api] bicycle idle");
        return true;
    }

    out.hasRental       = true;
    out.rentalId        = rental["rentalId"] | "";
    out.status          = rental["status"] | "";
    out.riderName       = rental["riderName"] | "";
    out.deadlineBasis   = rental["deadlineBasis"] | "";
    out.startTs         = rental["startTimestamp"].as<int64_t>();
    out.deadlineTs      = rental["returnDeadlineTimestamp"].as<int64_t>();
    out.isOverdue       = rental["isOverdue"] | false;
    out.ratePerHour     = rental["ratePerHour"] | 0.0f;

    JsonObjectConst penalty = rental["penalty"].as<JsonObjectConst>();
    if (!penalty.isNull()) {
        out.penaltyBasis     = penalty["basis"] | "";
        out.penaltyRule      = penalty["rule"] | "";
        out.penaltyRuleValue = penalty["ruleValue"] | 0.0f;
        out.penaltyAccrued   = penalty["accrued"] | 0.0f;
    }

    Serial.printf("[api] parsed hasRental=%d status=%s start=%lld deadline=%lld overdue=%d\n",
                  out.hasRental ? 1 : 0, out.status.c_str(), (long long)out.startTs,
                  (long long)out.deadlineTs, out.isOverdue ? 1 : 0);

    Serial.printf("[api] %s status=%s basis=%s deadline=%lld penalty=%.2f serverIsOverdue=%d\n",
                  out.rentalId.c_str(), out.status.c_str(), out.deadlineBasis.c_str(),
                  (long long)out.deadlineTs, out.penaltyAccrued, out.isOverdue ? 1 : 0);

    return true;
}

bool PedalyaApi::parseCommands(const String& body, DeviceCommand* commands, size_t capacity, size_t& count, String& error) {
    JsonDocument doc;
    if (deserializeJson(doc, body)) {
        error = "bad commands";
        return false;
    }

    JsonArrayConst source = doc["data"]["commands"].as<JsonArrayConst>();
    if (source.isNull()) {
        error = "no commands";
        return false;
    }

    for (JsonObjectConst item : source) {
        if (count >= capacity) {
            break;
        }
        DeviceCommand& command = commands[count++];
        command.id = item["id"] | 0;
        command.command = item["command"] | "";
        JsonObjectConst params = item["params"].as<JsonObjectConst>();
        if (!params.isNull()) {
            command.reason = params["reason"] | "";
            command.message = params["message"] | "";
            command.durationMs = params["duration_ms"] | 0;
            command.servoAngle = params["servo_angle"] | -1;
        }
    }
    return true;
}
