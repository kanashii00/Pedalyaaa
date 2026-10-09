#include "PedalyaApi.h"

#include "Sim7600.h"
#include "config.h"

#include <ArduinoJson.h>
#include <HTTPClient.h>
#include <WiFi.h>
#include <WiFiClient.h>
#include <time.h>

#if USE_HTTPS
#include <WiFiClientSecure.h>
#endif

bool PedalyaApi::cellularActive() const {
    // Cached state only: asking the modem here would block the loop on every
    // connected() call. simIsReady() is refreshed by simConnectInternet() and
    // by the bearer check after a failed exchange.
    return SIM_ENABLED && simIsReady() && WiFi.status() != WL_CONNECTED;
}

bool PedalyaApi::connected() {
    const bool isConnected = WiFi.status() == WL_CONNECTED;
    if (isConnected && !wifiConnectionAnnounced_) {
        Serial.println("WiFi connected!");
        Serial.print("ESP32 IP address: ");
        Serial.println(WiFi.localIP());
        Serial.print("Gateway: ");
        Serial.println(WiFi.gatewayIP());
        Serial.print("Subnet: ");
        Serial.println(WiFi.subnetMask());
        wifiConnectionAnnounced_ = true;
    } else if (!isConnected) {
        wifiConnectionAnnounced_ = false;
    }

    // The same announcement for the cellular route, so an operator can tell
    // from the monitor which link the bike is actually reporting over.
    if (cellularActive()) {
        if (!cellularAnnounced_) {
            Serial.println("[SIM] link up: reporting over cellular");
            cellularAnnounced_ = true;
        }
    } else {
        cellularAnnounced_ = false;
    }

    return isConnected || cellularActive();
}

const char* PedalyaApi::offlineError() const {
    // "wifi down" is only accurate while there is no modem to blame instead.
    if (SIM_ENABLED && simPresent()) {
        return "link down";
    }
    return "wifi down";
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
    lastStatus_ = status;

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
            if (WiFi.status() != WL_CONNECTED && SIM_ENABLED && simPresent()) {
                // No Wi-Fi association and a modem that is answering: the fault
                // is on the cellular path, so pointing at the router would send
                // the reader to the wrong room.
                Serial.printf("[api] cannot reach %s:%d over the EC20F link\n",
                              SERVER_HOST, SERVER_PORT);
                Serial.println("      Check SIM_APN in pedalya_lcd/secrets.h, that the SIM has");
                Serial.println("      a data plan, and that SERVER_HOST is a public address the");
                Serial.println("      cellular network can route to (a LAN IP cannot be reached");
                Serial.println("      from outside the LAN).");
            } else {
                Serial.printf("[api] cannot reach %s:%d\n", SERVER_HOST, SERVER_PORT);
                Serial.println("      Check the PC is on the same Wi-Fi, that php artisan serve");
                Serial.println("      was started with --host=0.0.0.0, and that Windows Firewall");
                Serial.println("      allows inbound TCP 8000.");
            }
            return;

        case 401:
            error = "auth 401";
            Serial.println("[api] 401 Unauthorized");
            if (detail.length()) {
                Serial.printf("      server said: %s\n", detail.c_str());
            }
            if (strlen(DEVICE_API_KEY) == 0 && strlen(PEDALYA_API_KEY) == 0) {
                Serial.println("      No key was sent. Put the value printed by");
                Serial.println("        php artisan devices:provision-key 1");
                Serial.println("      into pedalya_lcd/secrets.h as DEVICE_API_KEY.");
            } else {
                Serial.println("      The key was sent but rejected. Two keys exist and each");
                Serial.println("      endpoint only accepts one of them:");
                Serial.println("        per-bicycle (/rental, /commands, /gps) -> DEVICE_API_KEY");
                Serial.println("        fleet-wide  (/heartbeat, /accident-report) -> PEDALYA_API_KEY");
                Serial.println("      DEVICE_API_KEY must be the key of the bicycle named in the");
                Serial.println("      path; a valid key for another bicycle is rejected too.");
                Serial.println("      Reissue with --rotate if unsure.");
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

bool PedalyaApi::cellularRequest(const char* method, const char* path, const String& payload,
                                 String& responseBody, String& error, const char* apiKey) {
    responseBody = "";
    lastStatus_ = 0;

    if (apiKey == nullptr || strlen(apiKey) == 0) {
        error = "no api key";
        Serial.println("[api] the API key for this endpoint is empty, so no key can be sent.");
        Serial.println("      Add it to pedalya_lcd/secrets.h (DEVICE_API_KEY for the");
        Serial.println("      per-bicycle routes, PEDALYA_API_KEY for heartbeat and");
        Serial.println("      accident-report) and reflash the bike.");
        return false;
    }

    const bool useHttps = (USE_HTTPS == 1);
    char url[160];
    if (useHttps) {
        snprintf(url, sizeof(url), "https://%s%s", SERVER_HOST, path);
    } else {
        snprintf(url, sizeof(url), "http://%s:%d%s", SERVER_HOST, SERVER_PORT, path);
    }
    const String authHeader = String("X-API-Key: ") + apiKey;
    const bool isPost = (strcmp(method, "POST") == 0);

    const bool ok = isPost ? simHttpPost(url, payload, &responseBody, authHeader)
                           : simHttpGet(url, &responseBody, authHeader);
    const int status = simLastHttpStatus();

    if (status == 0) {
        reportFailure(0, responseBody, error);
        return false;
    }
    if (!ok) {
        reportFailure(status, responseBody, error);
        return false;
    }
    return true;
}

bool PedalyaApi::poll(RentalState& out, String& error) {
    // Cleared up front: this poll may fail before any HTTP status exists, and a
    // status left over from an earlier rejection would mislabel an offline bike
    // as a key fault on the LCD.
    lastStatus_ = 0;

    if (!connected()) {
        error = offlineError();
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

    // Second route to the same endpoint. The Wi-Fi request below is untouched
    // and runs whenever an association exists; this only runs when it does not.
    if (cellularActive()) {
        String body;
        if (!cellularRequest("GET", SERVER_PATH, String(), body, error)) {
            return false;
        }

        // Temporary wire-level diagnostic, identical in both routes: response
        // body only, never the request headers.
        Serial.println("[api] RAW RESPONSE:");
        Serial.println(body);

        if (body.length() == 0 || body.length() > (int)HTTP_RESPONSE_BUFFER) {
            error = "bad size";
            Serial.printf("[api] unexpected body length %u\n", (unsigned)body.length());
            return false;
        }

        Serial.printf("[api] 200 OK over cellular from %s%s\n", SERVER_HOST, SERVER_PATH);
        return parse(body, out, error);
    }

#if USE_HTTPS
    WiFiClientSecure client;
    client.setInsecure();
#else
    WiFiClient client;
#endif

    HTTPClient http;
    char url[160];
    if (USE_HTTPS) {
        snprintf(url, sizeof(url), "https://%s%s", SERVER_HOST, SERVER_PATH);
    } else {
        snprintf(url, sizeof(url), "http://%s:%d%s", SERVER_HOST, SERVER_PORT, SERVER_PATH);
    }

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
        error = offlineError();
        return false;
    }

    if (strlen(DEVICE_API_KEY) == 0) {
        error = "no api key";
        return false;
    }

    if (cellularActive()) {
        String body;
        if (!cellularRequest("GET", COMMANDS_PATH, String(), body, error)) {
            return false;
        }
        if (body.length() == 0 || body.length() > (int)HTTP_RESPONSE_BUFFER) {
            error = "command size";
            return false;
        }
        return parseCommands(body, commands, capacity, count, error);
    }

#if USE_HTTPS
    WiFiClientSecure client;
    client.setInsecure();
#else
    WiFiClient client;
#endif

    HTTPClient http;
    char url[160];
    if (USE_HTTPS) {
        snprintf(url, sizeof(url), "https://%s%s", SERVER_HOST, COMMANDS_PATH);
    } else {
        snprintf(url, sizeof(url), "http://%s:%d%s", SERVER_HOST, SERVER_PORT, COMMANDS_PATH);
    }

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
        error = offlineError();
        return false;
    }

    if (cellularActive()) {
        JsonDocument payload;
        payload["status"] = executed ? "executed" : "failed";
        payload["result"] = result;
        String body;
        serializeJson(payload, body);

        char path[196];
        snprintf(path, sizeof(path), "%s/%lu/ack", COMMANDS_PATH, (unsigned long)commandId);
        String response;
        return cellularRequest("POST", path, body, response, error);
    }

#if USE_HTTPS
    WiFiClientSecure client;
    client.setInsecure();
#else
    WiFiClient client;
#endif

    HTTPClient http;
    char url[196];
    if (USE_HTTPS) {
        snprintf(url, sizeof(url), "https://%s%s/%lu/ack", SERVER_HOST, COMMANDS_PATH, (unsigned long)commandId);
    } else {
        snprintf(url, sizeof(url), "http://%s:%d%s/%lu/ack", SERVER_HOST, SERVER_PORT, COMMANDS_PATH, (unsigned long)commandId);
    }

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

bool PedalyaApi::postJson(const char* path, const String& payload, String& error,
                          const char* apiKey) {
    if (!connected()) {
        error = offlineError();
        return false;
    }
    if (apiKey == nullptr || strlen(apiKey) == 0) {
        error = "no api key";
        Serial.printf("[api] no key configured for %s; nothing was sent.\n", path);
        return false;
    }

    // Heartbeat, GPS and accident uploads all flow through here, so this one
    // branch carries every report onto the cellular route at once.
    if (cellularActive()) {
        String response;
        return cellularRequest("POST", path, payload, response, error, apiKey);
    }

#if USE_HTTPS
    WiFiClientSecure client;
    client.setInsecure();
#else
    WiFiClient client;
#endif

    HTTPClient http;
    char url[192];
    if (USE_HTTPS) {
        snprintf(url, sizeof(url), "https://%s%s", SERVER_HOST, path);
    } else {
        snprintf(url, sizeof(url), "http://%s:%d%s", SERVER_HOST, SERVER_PORT, path);
    }
    if (!http.begin(client, url)) {
        error = "post begin";
        return false;
    }

    http.setTimeout(REQUEST_TIMEOUT_MS);
    http.addHeader("X-API-Key", apiKey);
    http.addHeader("Accept", "application/json");
    http.addHeader("Content-Type", "application/json");
    const int status = http.POST(payload);
    const String response = http.getString();
    http.end();
    if (status < 200 || status >= 300) {
        reportFailure(status, response, error);
        return false;
    }
    return true;
}

bool PedalyaApi::sendHeartbeat(bool locked, bool lockStateKnown, uint32_t uptimeSeconds, String& error) {
    JsonDocument payload;
    if (lockStateKnown) payload["locked"] = locked;
    payload["uptime"] = uptimeSeconds;
    payload["firmware"] = "pedalya-esp32-v1";
    String body;
    serializeJson(payload, body);
    // Fleet-wide route: authenticated with the shared fleet key, not this
    // bicycle's key. The backend rejects the per-bicycle key here with 401
    // Invalid API key, which is a configuration fault rather than a bad key.
    return postJson(HEARTBEAT_PATH, body, error, PEDALYA_API_KEY);
}

bool PedalyaApi::sendGps(double latitude, double longitude, float speedKph,
                         float heading, String& error) {
    JsonDocument payload;
    payload["lat"] = latitude;
    payload["lng"] = longitude;
    payload["speed"] = speedKph;
    payload["heading"] = heading;
    String body;
    serializeJson(payload, body);
    return postJson(GPS_PATH, body, error);
}

bool PedalyaApi::reportAccident(double latitude,
                                double longitude,
                                bool hasFix,
                                float impactG,
                                float rotationDps,
                                float tiltDegrees,
                                float accelXG,
                                float accelYG,
                                float accelZG,
                                String& error) {
    // Cleared up front so a stale status from an earlier poll cannot be read as
    // the verdict on this report.
    lastStatus_ = 0;

    JsonDocument payload;

    // Only claim a position when there is one. A crash under a bridge or in a
    // tunnel must still be reported, so the coordinates are left out entirely
    // rather than sent as zero, which would point at Null Island.
    if (hasFix) {
        payload["lat"] = latitude;
        payload["lng"] = longitude;
    }

    // The fleet-wide endpoint has no authenticated bicycle of its own, so the
    // bicycle has to travel in the payload; device_id records who reported it.
    payload["bicycle_id"] = (uint32_t)ACCIDENT_BICYCLE_ID;
    payload["device_id"] = ACCIDENT_DEVICE_ID;

    // impact is what the backend bands into a severity, so it carries the
    // measured peak. impact_force is kept in step for the older endpoint.
    payload["impact"] = impactG;
    payload["impact_force"] = impactG;
    payload["rotation_dps"] = rotationDps;
    payload["tilt_degrees"] = tiltDegrees;

    // The vector the event was judged on, so the record shows what the sensor
    // actually saw and not only the verdict derived from it.
    JsonArray accelerometer = payload["accelerometer"].to<JsonArray>();
    accelerometer.add(accelXG);
    accelerometer.add(accelYG);
    accelerometer.add(accelZG);

    // The timestamp is optional; the server stamps it on arrival.
    String body;
    serializeJson(payload, body);
    Serial.printf("[api] accident report -> %s %s\n", ACCIDENT_REPORT_PATH, body.c_str());
    return postJson(ACCIDENT_REPORT_PATH, body, error, PEDALYA_API_KEY);
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

    JsonObjectConst bicycle = data["bicycle"].as<JsonObjectConst>();
    if (!bicycle.isNull()) {
        out.bicycleLockStatus = bicycle["lockStatus"] | "locked";
        out.bicycleStatus = bicycle["status"] | "available";
    }

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
    out.serverOverdueSeconds = rental["overdueSeconds"] | 0UL;
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
