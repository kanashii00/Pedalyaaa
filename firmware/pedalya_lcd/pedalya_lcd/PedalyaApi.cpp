#include "PedalyaApi.h"

#include "Sim7600.h"
#include "config.h"

#include <ArduinoJson.h>
#include <HTTPClient.h>
#include <WiFi.h>
#include <WiFiClient.h>
#include <time.h>
#include <esp_sntp.h>

#if USE_HTTPS || CONNECTIVITY_MODE == CONNECTIVITY_MODE_WIFI_HEALTH_TEST
#include <WiFiClientSecure.h>

class VerifiedWiFiClientSecure : public WiFiClientSecure {
public:
    VerifiedWiFiClientSecure() {
        _use_insecure = false;
        setCACert(SERVER_ROOT_CA);
    }
};
typedef VerifiedWiFiClientSecure BundleVerifiedWiFiClientSecure;
#endif

bool PedalyaApi::cellularActive() const {
    // Cached state only: asking the modem here would block the loop on every
    // connected() call. simIsReady() is refreshed by simConnectInternet() and
    // by the bearer check after a failed exchange.
#if CONNECTIVITY_MODE == CONNECTIVITY_MODE_WIFI_HEALTH_TEST
    return false;
#elif SIM_ONLY_MODE
    // SIM-only build: the EC20F link is the ONLY route. Wi-Fi status is not
    // consulted, so API traffic can never silently fall back to Wi-Fi even if
    // the radio ever reports an association.
    return SIM_ENABLED && simIsReady();
#else
    return SIM_ENABLED && simIsReady() && WiFi.status() != WL_CONNECTED;
#endif
}

bool PedalyaApi::connected() {
#if CONNECTIVITY_MODE == CONNECTIVITY_MODE_WIFI_HEALTH_TEST || !SIM_ONLY_MODE
    const bool isConnected = WiFi.status() == WL_CONNECTED;
#elif SIM_ONLY_MODE
    // SIM-only build: the Wi-Fi radio is never started (begin() is a no-op),
    // so it can never be a route. Only the EC20F data link counts.
    const bool isConnected = false;
#else
    const bool isConnected = WiFi.status() == WL_CONNECTED;
#endif
    if (isConnected && !wifiConnectionAnnounced_) {
        Serial.println("[wifi] connected & IP acquired!");
        Serial.print("       IP: ");
        Serial.println(WiFi.localIP());
        Serial.print("       Gateway: ");
        Serial.println(WiFi.gatewayIP());
        Serial.print("       Subnet: ");
        Serial.println(WiFi.subnetMask());
        Serial.printf("       DNS1: %s  DNS2: %s  RSSI: %d dBm\n",
                      WiFi.dnsIP(0).toString().c_str(),
                      WiFi.dnsIP(1).toString().c_str(),
                      WiFi.RSSI());
        IPAddress resolvedIp;
        if (WiFi.hostByName(SERVER_HOST, resolvedIp)) {
            Serial.printf("[dns] %s -> %s\n", SERVER_HOST, resolvedIp.toString().c_str());
        } else {
            Serial.printf("[dns] failed to resolve %s\n", SERVER_HOST);
        }
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

static const char* wifiDisconnectReasonStr(uint8_t reason) {
    switch (reason) {
        case 201: return "AP not found (SSID out of range, hidden, or 5GHz-only)";
        case 202: return "Authentication failed (wrong password)";
        case 203: return "Association failed";
        case 204: return "Handshake timeout";
        case 205: return "Connection failed";
        case 200: return "Beacon timeout";
        case 1:   return "Unspecified error";
        case 2:   return "Auth expired";
        case 3:   return "Auth leave";
        case 4:   return "Assoc expired";
        case 5:   return "Assoc too many";
        case 6:   return "Not authed";
        case 7:   return "Not assoced";
        case 8:   return "Assoc leave";
        default:  return "See ESP32 WiFi reason code";
    }
}

const char* PedalyaApi::offlineError() const {
#if CONNECTIVITY_MODE == CONNECTIVITY_MODE_WIFI_HEALTH_TEST
    return "wifi down";
#else
    // "wifi down" is only accurate while there is no modem to blame instead.
    if (SIM_ENABLED && simPresent()) {
        return "link down";
    }
    return "wifi down";
#endif
}

void PedalyaApi::begin() {
#if SIM_ONLY_MODE || CONNECTIVITY_MODE == CONNECTIVITY_MODE_EC20F_SIM
    // SIM-only build: the Wi-Fi radio is never brought up, so there is no
    // association to start and no fallback to maintain. The cellular route
    // attaches separately via simConnectInternet() from the sketch loop.
    wifiAttemptInProgress = false;
    return;
#endif
    if (WIFI_SSID == nullptr || WIFI_SSID[0] == '\0') {
        wifiAttemptInProgress = false;
        return;
    }

    static bool eventHandlerRegistered = false;
    if (!eventHandlerRegistered) {
        eventHandlerRegistered = true;
        WiFi.onEvent([](WiFiEvent_t event, WiFiEventInfo_t info) {
            if (event == ARDUINO_EVENT_WIFI_STA_DISCONNECTED) {
                const uint8_t reason = info.wifi_sta_disconnected.reason;
                Serial.printf("[wifi] disconnected (reason %d: %s)\n",
                              reason, wifiDisconnectReasonStr(reason));
            }
        });
    }

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

static void buildApiUrl(char* buf, size_t maxLen, bool useHttps, const char* host, uint16_t port, const char* path) {
    const bool defaultPort = (useHttps && port == 443) || (!useHttps && port == 80);
    const char* p = (path && path[0] == '/') ? path : "/";
    if (defaultPort) {
        snprintf(buf, maxLen, "%s://%s%s", useHttps ? "https" : "http", host, p);
    } else {
        snprintf(buf, maxLen, "%s://%s:%u%s", useHttps ? "https" : "http", host, (unsigned)port, p);
    }
}

void PedalyaApi::ensureTime() {
    time_t cur = time(nullptr);
    // If the ESP32 clock is at Unix epoch 1970, seed it with a safe baseline
    // (~October 2026) so mbedTLS X.509 notBefore validation does not fail before NTP sync.
    if (cur < 1700000000ULL) {
        struct timeval tv = { 1792000000ULL, 0 };
        settimeofday(&tv, nullptr);
        Serial.println("[ntp] clock initialized with baseline timestamp for TLS validation");
    }

    if (timeSynced) {
        return;
    }

    uint32_t now = millis();
    // Bounded retry: check every 5s while unsynced
    if (lastNtpAttempt != 0 && (uint32_t)(now - lastNtpAttempt) < 5000UL) {
        return;
    }
    lastNtpAttempt = now;

    // Reuse the offset the server reported, otherwise this would overwrite it
    // with the compile-time default on every attempt.
    configTime(appliedUtcOffset >= 0 ? appliedUtcOffset : DEFAULT_UTC_OFFSET_SECONDS, 0, NTP_SERVER);

    if (sntp_get_sync_status() == SNTP_SYNC_STATUS_COMPLETED) {
        struct tm timeInfo;
        if (getLocalTime(&timeInfo, 100)) {
            char stamp[32];
            strftime(stamp, sizeof(stamp), "%Y-%m-%d %H:%M:%S", &timeInfo);
            Serial.printf("[ntp] clock synchronized via NTP: %s\n", stamp);
            timeSynced = true;
        }
    } else {
        Serial.println("[ntp] sync in progress; using baseline clock for TLS");
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

    if (status <= 0) {
        String httpErrStr = HTTPClient::errorToString(status);
        if (status == HTTPC_ERROR_READ_TIMEOUT) {
            error = "timeout";
            Serial.printf("[api] Request timeout reaching %s:%d (server took > %lu ms to respond)\n",
                          SERVER_HOST, SERVER_PORT, (unsigned long)REQUEST_TIMEOUT_MS);
        } else {
            error = "connect failed";
            if (WiFi.status() != WL_CONNECTED && SIM_ENABLED && simPresent()) {
                Serial.printf("[api] cannot reach %s:%d over the EC20F link (%s [%d])\n",
                              SERVER_HOST, SERVER_PORT, httpErrStr.c_str(), status);
                Serial.println("      Check SIM_APN in pedalya_lcd/secrets.h, that the SIM has");
                Serial.println("      a data plan, and that SERVER_HOST is a public address the");
                Serial.println("      cellular network can route to (a LAN IP cannot be reached");
                Serial.println("      from outside the LAN).");
            } else {
                Serial.printf("[api] cannot reach %s:%d (%s [%d])\n",
                              SERVER_HOST, SERVER_PORT, httpErrStr.c_str(), status);
                Serial.println("      Check the server is running, the Dev Tunnel is forwardable,");
                Serial.println("      and TLS/DNS connection succeeds.");
            }
        }
        return;
    }

    switch (status) {

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

    if (status == 502 || status == 504) {
        error = (status == 502) ? "bad gateway 502" : "gateway timeout 504";
        Serial.printf("[api] %d %s - devtunnel/reverse proxy responded,\n",
                      status, (status == 502) ? "Bad Gateway" : "Gateway Timeout");
        Serial.println("      but the local backend (Laravel on port 8000) was unreachable or timed out.");
        Serial.println("      Ensure 'php artisan serve' is running on 127.0.0.1:8000.");
        if (detail.length()) {
            Serial.printf("      proxy said: %s\n", detail.c_str());
        } else if (body.length()) {
            Serial.printf("      proxy body: %.120s\n", body.c_str());
        }
        return;
    }

    if (status >= 500) {
        error = "server error";
        Serial.printf("[api] %d from Laravel - the request reached the application but an unhandled exception occurred\n", status);
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
    const uint32_t requestStartedAtMs = millis();

    // Second route to the same endpoint. The Wi-Fi request below is untouched
    // and runs whenever an association exists; this only runs when it does not.
    if (cellularActive()) {
        String body;
        if (!cellularRequest("GET", SERVER_PATH, String(), body, error)) {
            return false;
        }
        const uint32_t responseReceivedAtMs = millis();

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
        return parse(body, out, error, requestStartedAtMs, responseReceivedAtMs);
    }

#if USE_HTTPS
    BundleVerifiedWiFiClientSecure client;
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
    const uint32_t responseReceivedAtMs = millis();

    if (status <= 0) {
        // Log TLS/transport error from WiFiClientSecure before http.end()
        char tlsErr[128] = "";
        client.lastError(tlsErr, sizeof(tlsErr));
        Serial.printf("[api] WiFi GET failed: HTTPClient error %d (%s)\n",
                      status, HTTPClient::errorToString(status).c_str());
        if (tlsErr[0]) Serial.printf("[api] TLS error: %s\n", tlsErr);
    }

    http.end();

    // Temporary wire-level diagnostic for a successful device poll. This logs
    // only the response body; request headers (including X-API-Key) are never
    // printed.
    if (status > 0) {
        Serial.println("[api] RAW RESPONSE:");
        Serial.println(body);
    }

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

    return parse(body, out, error, requestStartedAtMs, responseReceivedAtMs);
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
    BundleVerifiedWiFiClientSecure client;
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
    BundleVerifiedWiFiClientSecure client;
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
    BundleVerifiedWiFiClientSecure client;
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

bool PedalyaApi::sendHeartbeat(bool locked, bool lockStateKnown, uint32_t uptimeSeconds,
                               const HallSpeedReading& speed, String& error) {
    JsonDocument payload;
    // The fleet endpoint requires both: without them every heartbeat is a
    // 422 and the unit looks dead on the dashboard while the link is fine.
    payload["bicycle_id"] = (uint32_t)BICYCLE_ID;
    payload["device_id"] = DEVICE_ID;
    if (lockStateKnown) payload["locked"] = locked;
    payload["uptime"] = uptimeSeconds;
    payload["firmware"] = "pedalya-esp32-v1";
    payload["speedSensorStatus"] = speed.status;
    payload["hallPulseCount"] = speed.pulseCount;
    if (speed.wheelCircumferenceM > 0.0f) {
        payload["wheelCircumferenceM"] = speed.wheelCircumferenceM;
    }
    if (speed.speedAvailable) {
        payload["speedKmh"] = speed.speedKmh;
    }

    // Keep the admin and rider maps live even while the modem or GPS receiver
    // is not producing a fresh fix. The backend treats heartbeat lat/lng as the
    // current position source and refreshes lastGpsUpdate from it, so a last
    // known fix continues to move the marker without requiring a separate GPS
    // POST every cycle.
    if (hasGpsFix_) {
        payload["lat"] = lastGpsFix_.latitude;
        payload["lng"] = lastGpsFix_.longitude;
        payload["speed"] = lastGpsFix_.speedKph;
        payload["heading"] = lastGpsFix_.heading;
    }

    String body;
    serializeJson(payload, body);
    // Fleet-wide route: authenticated with the shared fleet key, not this
    // bicycle's key. The backend rejects the per-bicycle key here with 401
    // Invalid API key, which is a configuration fault rather than a bad key.
    return postJson(HEARTBEAT_PATH, body, error, PEDALYA_API_KEY);
}

bool PedalyaApi::sendGps(double latitude, double longitude, float speedKph,
                         float heading, String& error) {
    lastGpsFix_.latitude = latitude;
    lastGpsFix_.longitude = longitude;
    lastGpsFix_.speedKph = speedKph;
    lastGpsFix_.heading = heading;
    lastGpsFix_.receivedAtMs = millis();
    hasGpsFix_ = true;

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

bool PedalyaApi::checkHealth(String& detail) {
    // Cleared up front so a stale rejection from an earlier poll cannot be
    // mistaken for the verdict on this probe.
    lastStatus_ = 0;
    detail = "";

    // Stage 1: modem initialization. simPresent() is cached from simInit(),
    // so this is a cheap check with no UART traffic.
#if SIM_ONLY_MODE || CONNECTIVITY_MODE == CONNECTIVITY_MODE_EC20F_SIM
    if (SIM_ENABLED && !simPresent()) {
        detail = "modem init failure";
        Serial.println("[health] Stage 1 failed: EC20F silent on UART2 (modem init failure)");
        Serial.println("         health HTTP status: not attempted; server response success: NOT TESTED");
        Serial.println("         Check EC20F TXD -> GPIO16, RXD -> GPIO17, common GND,");
        Serial.println("         and modem power supply (3.8V-4.2V VBAT rail).");
        return false;
    }
#endif

    // Stage 2: link availability. EC20F mode can distinguish registration
    // failure from Wi-Fi being unavailable.
    if (!connected()) {
#if SIM_ONLY_MODE || CONNECTIVITY_MODE == CONNECTIVITY_MODE_EC20F_SIM
        if (SIM_ENABLED && simPresent() && !simIsRegistered()) {
            detail = "network registration failure";
            Serial.println("[health] Stage 2 failed: modem not registered on any cellular network");
            Serial.println("         health HTTP status: not attempted; server response success: NOT TESTED");
            Serial.println("         Check RF antenna, SIM card, and carrier coverage.");
        } else if (SIM_ENABLED && simPresent() && !simIsReady()) {
            detail = "packet data link failure";
            Serial.println("[health] Stage 3 failed: packet data link down (CGATT/CGACT)");
            Serial.println("         health HTTP status: not attempted; server response success: NOT TESTED");
            Serial.println("         Check SIM_APN in secrets.h and that SIM has an active data plan.");
        } else {
            detail = offlineError();
            Serial.printf("[health] link down (%s)\n", detail.c_str());
            Serial.println("         health HTTP status: not attempted; server response success: NOT TESTED");
        }
#else
        detail = offlineError();
        Serial.println("[health] Wi-Fi is not connected; health request not attempted");
#endif
        return false;
    }

#if CONNECTIVITY_MODE == CONNECTIVITY_MODE_WIFI_HEALTH_TEST
    const char* healthHost = WIFI_TEST_SERVER_HOST;
    const char* healthPath = WIFI_TEST_HEALTH_PATH;
#else
    const char* healthHost = SERVER_HOST;
    const char* healthPath = HEALTH_PATH;
#endif

    // The test URL is fixed to the requested HTTPS host, port and health path.
    // No key is attached: /api/health is public.
    char url[160];
#if CONNECTIVITY_MODE == CONNECTIVITY_MODE_WIFI_HEALTH_TEST
    snprintf(url, sizeof(url), "https://%s:%d%s", healthHost, WIFI_TEST_SERVER_PORT, healthPath);
#else
    if (USE_HTTPS) {
        snprintf(url, sizeof(url), "https://%s%s", healthHost, healthPath);
    } else {
        snprintf(url, sizeof(url), "http://%s:%d%s", healthHost, SERVER_PORT, healthPath);
    }
#endif

    // Same bearer the rental poll would use. The EC20F branch reuses the
    // existing QHTTP GET + QHTTPREAD sequence verbatim via simHttpGet()
    // (empty extraHeaders = no X-API-Key line in the request head).
    if (cellularActive()) {
        String body;
        const bool ok = simHttpGet(url, &body, String());
        const int status = simLastHttpStatus();
        lastStatus_ = status;

        // Stage 3: HTTPS/TLS or transport failure. The modem completed no
        // HTTP exchange, so there is no status to report.
        if (status == 0) {
            detail = "https/tls failure";
            Serial.printf("[health] HTTPS/TLS failure reaching %s over cellular\n", url);
            Serial.println("         TLS handshake or TCP setup failed before any HTTP status.");
            Serial.println("         Check the hostname, that the tunnel is running, and SNI/TLS config.");
            return false;
        }

        // Stage 4: the server answered with an HTTP error status.
        if (!ok) {
            char shortReason[24];
            snprintf(shortReason, sizeof(shortReason), "http error %d", status);
            detail = shortReason;
            Serial.printf("[health] HTTP %d from %s over cellular\n", status, HEALTH_PATH);
            if (body.length()) {
                Serial.println("[health] body:");
                Serial.println(body);
            }
            return false;
        }

        // Stage 5: successful response. Report the status and the body, so
        // the operator can confirm the tunnel serves this firmware's host.
        Serial.printf("[health] success: HTTP %d from %s over cellular\n", status, HEALTH_PATH);
        Serial.println("[health] body:");
        Serial.println(body);
        char verdict[32];
        snprintf(verdict, sizeof(verdict), "success http %d", status);
        detail = verdict;
        return true;
    }

#if USE_HTTPS || CONNECTIVITY_MODE == CONNECTIVITY_MODE_WIFI_HEALTH_TEST
    BundleVerifiedWiFiClientSecure client;
#else
    WiFiClient client;
#endif

#if USE_HTTPS || CONNECTIVITY_MODE == CONNECTIVITY_MODE_WIFI_HEALTH_TEST
    ensureTime();
    Serial.printf("[health] attempting HTTPS GET %s\n", url);
#endif
    HTTPClient http;
    if (!http.begin(client, url)) {
        detail = "https/tls failure";
        Serial.printf("[health] Stage3: HTTPS setup failed for %s\n", url);
        return false;
    }

    http.setTimeout(REQUEST_TIMEOUT_MS);
    http.addHeader("Accept", "application/json");
    const int status = http.GET();
    String body = http.getString();

    if (status <= 0) {
        // Stage 3: TLS/TCP failure — capture mbedTLS error string before http.end()
        char tlsErr[128] = "";
#if USE_HTTPS || CONNECTIVITY_MODE == CONNECTIVITY_MODE_WIFI_HEALTH_TEST
        client.lastError(tlsErr, sizeof(tlsErr));
#endif
        lastStatus_ = 0;
        if (status == HTTPC_ERROR_CONNECTION_REFUSED) {
            detail = "connection refused";
            Serial.printf("[health] Stage3: TCP connection refused to %s\n", url);
        } else if (status == HTTPC_ERROR_SEND_HEADER_FAILED) {
            detail = "tls handshake fail";
            Serial.printf("[health] Stage3: TLS handshake failed to %s\n", url);
        } else if (status == HTTPC_ERROR_READ_TIMEOUT) {
            detail = "read timeout";
            Serial.printf("[health] Stage3: read timeout reaching %s\n", url);
        } else {
            detail = "https/tls failure";
            Serial.printf("[health] Stage3: transport error %d (%s) reaching %s\n",
                          status, HTTPClient::errorToString(status).c_str(), url);
        }
        if (tlsErr[0]) {
            Serial.printf("[health] TLS detail: %s\n", tlsErr);
        }
        Serial.println("[health] Possible causes: clock not synced (notBefore/notAfter), wrong CA cert, SNI mismatch, or TCP refused.");
        http.end();
        return false;
    }

    http.end();

    // Stage 4: HTTP error from server
    lastStatus_ = status;
    if (status < 200 || status >= 300) {
        char shortReason[24];
        snprintf(shortReason, sizeof(shortReason), "http error %d", status);
        detail = shortReason;
        Serial.printf("[health] Stage4: HTTP %d from %s\n", status, healthPath);
        if (body.length()) {
            Serial.println("[health] body:");
            Serial.println(body);
        }
        return false;
    }

    // Stage 5: success
    Serial.printf("[health] Stage5: HTTP %d OK from %s\n", status, healthPath);
    Serial.println("[health] body:");
    Serial.println(body);
    char verdict[32];
    snprintf(verdict, sizeof(verdict), "success http %d", status);
    detail = verdict;
    return true;
}

bool PedalyaApi::parse(
    const String& body,
    RentalState& out,
    String& error,
    uint32_t requestStartedAtMs,
    uint32_t responseReceivedAtMs
) {
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
        out.serverUnixMs = clock["unixMilliseconds"].as<int64_t>();
        if (out.serverUnixMs <= 0) {
            out.serverUnixMs = out.serverUnix * 1000;
        }
        out.utcOffsetSeconds = clock["utcOffsetSeconds"] | DEFAULT_UTC_OFFSET_SECONDS;

        // If NTP has not yet synced, synchronize the system clock immediately
        // from the server's authoritative Unix time so TLS and logs stay accurate.
        if (out.serverUnix > 1700000000ULL && (!timeSynced || sntp_get_sync_status() != SNTP_SYNC_STATUS_COMPLETED)) {
            struct timeval tv = { (time_t)out.serverUnix, 0 };
            settimeofday(&tv, nullptr);
            timeSynced = true;
            char stamp[32];
            struct tm tmServer;
            time_t t = (time_t)(out.serverUnix + out.utcOffsetSeconds);
            gmtime_r(&t, &tmServer);
            strftime(stamp, sizeof(stamp), "%Y-%m-%d %H:%M:%S", &tmServer);
            Serial.printf("[clock] synchronized from server: %s\n", stamp);
        }
    }
    // The server timestamp was sampled while handling the request, not when
    // this device finished receiving it. Anchor it to the request midpoint so
    // network/server latency does not become a countdown jump on every poll.
    out.syncedAtMs = requestStartedAtMs
        + (uint32_t)(responseReceivedAtMs - requestStartedAtMs) / 2UL;

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
    out.wheelCircumferenceM = 0.0f;
    if (!bicycle.isNull()) {
        out.bicycleLockStatus = bicycle["lockStatus"] | "locked";
        out.bicycleStatus = bicycle["status"] | "available";
        out.manualUnlockOverride = bicycle["manualUnlockOverrideActive"] | false;
        out.bicycleSerial = bicycle["serialNumber"] | "";
        if (bicycle["batteryLevel"].is<int>()) {
            out.bicycleBatteryLevel = bicycle["batteryLevel"].as<int>();
        }
        if (bicycle["wheelCircumferenceM"].is<float>()) {
            out.wheelCircumferenceM = bicycle["wheelCircumferenceM"].as<float>();
        }
    }

    JsonObjectConst rental = data["rental"].as<JsonObjectConst>();
    if (rental.isNull()) {
        out.clearRental();
        Serial.printf("[api] parsed hasRental=%d status=%s start=%lld deadline=%lld overdue=%d\n",
                      out.hasRental ? 1 : 0, out.status.c_str(), (long long)out.startTs,
                      (long long)out.deadlineTs, out.isOverdue ? 1 : 0);
        if (out.bicycleStatus == "maintenance") {
            Serial.println("[api] bicycle in maintenance (not available)");
        } else {
            Serial.println("[api] bicycle idle");
        }
        return true;
    }

    out.hasRental       = true;
    out.rentalId        = rental["rentalId"] | "";
    out.status          = rental["status"] | "";
    out.riderName       = rental["riderName"] | "";
    out.deadlineBasis   = rental["deadlineBasis"] | "";
    out.startTs         = rental["startTimestamp"].as<int64_t>();
    out.deadlineTs      = rental["returnDeadlineTimestamp"].as<int64_t>();
    out.returnGraceStartedAtTs = rental["returnGraceStartedAtTimestamp"].as<int64_t>();
    out.returnCountdownDeadlineTs = rental["returnCountdownDeadlineTimestamp"].as<int64_t>();
    out.nextPenaltyDeadlineTs = rental["penalty"]["nextPenaltyDeadlineTimestamp"].as<int64_t>();
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
            command.manualOverride = params["manual_override"] | false;
        }
    }
    return true;
}
