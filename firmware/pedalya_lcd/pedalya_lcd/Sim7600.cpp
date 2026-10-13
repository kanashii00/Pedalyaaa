#include "Sim7600.h"

#include <Arduino.h>

namespace {

// UART2 belongs to this module alone: the GPS is on UART1, the monitor on
// UART0 (TX0/RX0). Nothing else opens Serial2.
bool uartUp_ = false;
bool modemAnswered_ = false;
bool dataLinkUp_ = false;
int lastHttpStatus_ = 0;
// Boot gives the modem three tries (it may still be coming up seconds after
// the ESP32); later probes, called from the loop, stay light with one try.
bool everProbed_ = false;

void discardInput() {
    while (Serial2.available() > 0) {
        Serial2.read();
    }
}

/** Text after the final line break, with the line terminators stripped. */
String lastLine(String text) {
    while (text.endsWith("\n") || text.endsWith("\r")) {
        text.remove(text.length() - 1);
    }
    int i = text.lastIndexOf('\n');
    if (i >= 0) text.remove(0, i + 1);
    i = text.lastIndexOf('\r');
    if (i >= 0) text.remove(0, i + 1);
    return text;
}

/**
 * True once a result code sits on a finished line at the end of the buffer.
 *
 * Only a completed line counts, so a half-received "O" or "+HTTPACTION: 1,20"
 * never passes for an answer and truncates the read.
 */
bool terminalReached(const String& text) {
    if (!text.endsWith("\n")) return false;
    const String line = lastLine(text);
    return line == "OK" || line == "ERROR" || line == "DOWNLOAD" ||
           line == "CONNECT" || line == "SEND OK" ||
           line.startsWith("+CME ERROR") || line.startsWith("+CMS ERROR") ||
           line.startsWith("+CMD NOT SUPPORT") ||
           line.startsWith("+HTTPACTION:") || line.startsWith("+QHTTP") ||
           (line.startsWith("+HTTPACTION: ") && line.indexOf(',') >= 0);
}

/**
 * Reads until a result code arrives, or until the modem has been quiet for
 * quietMs, or until timeoutMs - whichever comes first.
 */
String read(uint32_t timeoutMs, uint32_t quietMs = 60) {
    String out;
    const uint32_t start = millis();
    uint32_t lastByteMs = start;
    bool sawAny = false;

    while ((uint32_t)(millis() - start) < timeoutMs) {
        bool readNow = false;
        while (Serial2.available() > 0) {
            const int c = Serial2.read();
            if (c < 0) break;
            out += (char)c;
            readNow = true;
            sawAny = true;
        }

        if (readNow) {
            lastByteMs = millis();
            if (terminalReached(out)) break;
            continue;
        }
        if (sawAny && (uint32_t)(millis() - lastByteMs) >= quietMs) break;
        delay(2);
    }
    return out;
}

/** Reads exactly bytes (or until the timeout) with no result-code shortcut. */
String readExact(size_t bytes, uint32_t timeoutMs) {
    String out;
    if (bytes == 0) return out;
    out.reserve(bytes);

    const uint32_t start = millis();
    while (out.length() < bytes && (uint32_t)(millis() - start) < timeoutMs) {
        while (Serial2.available() > 0 && out.length() < bytes) {
            const int c = Serial2.read();
            if (c < 0) break;
            out += (char)c;
        }
        if (out.length() < bytes) delay(1);
    }
    return out;
}

/** Strips the device key from anything that is about to be logged. */
String redact(String text) {
    const int at = text.indexOf("X-API-Key: ");
    if (at >= 0) {
        int end = text.indexOf('\n', at);
        if (end < 0) end = text.length();
        text = text.substring(0, at + 11) + "<hidden>" + text.substring(end);
    }
    return text;
}

void logRx(const String& reply) {
    String line = redact(reply);
    line.replace("\r\n", " | ");
    if (line.length() > 200) line = line.substring(0, 200) + "...";
    Serial.println(String("[SIM] << ") + (line.length() ? line : "(no reply)"));
}

/** Re-checks the packet attachment after a failed exchange. */
void checkBearer() {
    const String reply = simSendCommand("AT+CGATT?", SIM_CMD_TIMEOUT_MS);
    if (reply.indexOf("+CGATT: 1") < 0) {
        if (dataLinkUp_) Serial.println("[SIM] bearer lost; reconnecting on the next attempt");
        dataLinkUp_ = false;
    }
}

enum HttpMethod { HttpGet = 0, HttpPost = 1 };

/** Seconds the modem may take to finish one HTTP round trip, for the QHTTP
 *  <rsptime> parameters. Bounded so a hung request cannot starve the loop. */
uint32_t httpResponseSeconds() {
    uint32_t seconds = SIM_HTTP_TIMEOUT_MS / 1000UL;
    if (seconds < 5) seconds = 5;
    if (seconds > 120) seconds = 120;
    return seconds;
}

/**
 * One-time-per-exchange setup of the EC20F HTTP service on PDP context 1.
 *
 * Enables the custom request-header block (which carries X-API-Key) and, for
 * https URLs, binds SSL context 1: any TLS version the server offers, no
 * certificate verification (the modem holds no CA store; same trust model as
 * the Wi-Fi setInsecure() path), and SNI on because the Dev Tunnel front
 * door routes by hostname. SNI is best effort: older EC20F firmware may not
 * know it, and the verdict then comes from the exchange, not this line.
 */
bool qhttpConfigure(bool https) {
    Serial.println("[SIM] EC20F HTTP initialization");
    String reply = simSendCommand("AT+QHTTPCFG=\"contextid\",1", SIM_CMD_TIMEOUT_MS);
    if (reply.indexOf("OK") < 0) return false;
    reply = simSendCommand("AT+QHTTPCFG=\"requestheader\",1", SIM_CMD_TIMEOUT_MS);
    if (reply.indexOf("OK") < 0) return false;
    reply = simSendCommand("AT+QHTTPCFG=\"responseheader\",0", SIM_CMD_TIMEOUT_MS);
    if (reply.indexOf("OK") < 0) return false;

    if (!https) return true;

    Serial.println("[SIM] HTTPS configuration");
    reply = simSendCommand("AT+QHTTPCFG=\"sslctxid\",1", SIM_CMD_TIMEOUT_MS);
    if (reply.indexOf("OK") < 0) return false;
    reply = simSendCommand("AT+QSSLCFG=\"sslversion\",1,4", SIM_CMD_TIMEOUT_MS);
    if (reply.indexOf("OK") < 0) return false;
    reply = simSendCommand("AT+QSSLCFG=\"seclevel\",1,0", SIM_CMD_TIMEOUT_MS);
    if (reply.indexOf("OK") < 0) return false;
    simSendCommand("AT+QSSLCFG=\"sni\",1,1", SIM_CMD_TIMEOUT_MS);
    return true;
}

/** Splits "https://host/path" into host and path. False when unparsable. */
bool splitUrl(const String& url, String& host, String& path) {
    const int scheme = url.indexOf("://");
    if (scheme < 0) return false;
    const int slash = url.indexOf('/', scheme + 3);
    if (slash < 0) {
        host = url.substring(scheme + 3);
        path = "/";
    } else {
        host = url.substring(scheme + 3, slash);
        path = url.substring(slash);
    }
    return host.length() > 0 && path.length() > 0;
}

/** Pulls the key out of one "X-API-Key: <value>" header line. */
String apiKeyFrom(const String& extraHeaders) {
    const String prefix = "X-API-Key:";
    const int at = extraHeaders.indexOf(prefix);
    if (at < 0) return String();
    String key = extraHeaders.substring(at + prefix.length());
    key.trim();
    return key;
}

/**
 * Fetches the buffered HTTP response body with AT+QHTTPREAD.
 *
 * Collects the whole modem frame (CONNECT banner, length line, body bytes,
 * +QHTTPREAD result line) before interpreting any of it, so a body holding
 * lines like "OK" cannot truncate the read, and EC20F revisions that frame
 * the reply differently still yield their bytes: a leading bare-number line
 * is honored as the announced length when present, otherwise the whole
 * remainder past the banner is the body. The buffer is capped so an
 * oversized response cannot exhaust the heap.
 *
 * Returns true with `body` filled (empty only when the modem genuinely holds
 * no bytes); false only on modem/transport failure.
 */
bool qhttpReadBody(String& body, uint32_t rspSec, long knownLength) {
    body = "";
    discardInput();
    char readCmd[32];
    snprintf(readCmd, sizeof(readCmd), "AT+QHTTPREAD=%lu", (unsigned long)rspSec);
    Serial.println(String("[SIM] >> ") + readCmd);
    Serial2.print(readCmd);
    Serial2.print("\r\n");

    const size_t CAP = (size_t)HTTP_RESPONSE_BUFFER + 256;
    String raw;
    raw.reserve(CAP + 64);
    const uint32_t start = millis();
    bool sawTerminator = false;
    while (!sawTerminator && raw.length() < CAP &&
           (uint32_t)(millis() - start) < SIM_HTTP_TIMEOUT_MS) {
        while (Serial2.available() > 0 && raw.length() < CAP) {
            raw += (char)Serial2.read();
        }
        if (raw.endsWith("\n")) {
            const String line = lastLine(raw);
            if (line.startsWith("+QHTTPREAD:") || line == "OK") sawTerminator = true;
        }
        if (!sawTerminator) delay(2);
    }

    if (raw.length() == 0) {
        Serial.println("[SIM] QHTTPREAD: modem sent nothing");
        return false;
    }

    // Drop everything up to and including the CONNECT banner when present.
    String data = raw;
    const int connectAt = data.indexOf("CONNECT");
    if (connectAt >= 0) {
        data = data.substring(connectAt + 7);
    } else {
        Serial.println("[SIM] QHTTPREAD: no CONNECT banner in frame");
    }

    // Cut the +QHTTPREAD result line (and anything after it) off the body.
    int readErr = -1;
    const int urcAt = data.indexOf("+QHTTPREAD:");
    if (urcAt >= 0) {
        readErr = data.substring(urcAt + 11).toInt();
        data = data.substring(0, urcAt);
    }

    // Some revisions end the frame with a bare OK instead of (or after) the
    // result line; a JSON body never ends in a bare OK line, so dropping it
    // cannot harm the payload.
    data.trim();
    {
        const int nl = data.lastIndexOf('\n');
        String tail = nl >= 0 ? data.substring(nl + 1) : data;
        tail.trim();
        if (tail == "OK") data = nl >= 0 ? data.substring(0, nl) : String();
    }

    // A leading bare-number line is the announced length and is authoritative
    // when present; otherwise the whole remainder is the body.
    int announced = -1;
    {
        const int nl = data.indexOf('\n');
        String first = nl >= 0 ? data.substring(0, nl) : data;
        first.trim();
        bool numeric = first.length() > 0 && first.length() < 8;
        for (unsigned i = 0; numeric && i < first.length(); ++i) {
            const char c = first.charAt(i);
            if (c < '0' || c > '9') numeric = false;
        }
        if (numeric) {
            announced = first.toInt();
            data = nl >= 0 ? data.substring(nl + 1) : String();
        }
    }
    if (announced > 0 && (int)data.length() > announced) {
        data.remove(announced);
    } else if (announced < 0 && knownLength > 0 && (int)data.length() > knownLength) {
        data.remove(knownLength);
    }

    if (readErr >= 0 && readErr != 0) {
        Serial.printf("[SIM] QHTTPREAD modem error %d\n", readErr);
        return false;
    }

    body = data;
    if (announced == 0) {
        Serial.println("[SIM] QHTTPREAD: modem reports an empty body");
    }
    Serial.printf("[SIM] QHTTPREAD body: %u bytes (announced %d)\n",
                  (unsigned)body.length(), announced);
    return true;
}

/**
 * One complete HTTP exchange through the EC20F QHTTP service.
 * Returns the HTTP status, or 0 when no transport completed the request.
 *
 * Per request: QHTTPURL takes the full https URL, then QHTTPGET/QHTTPPOST
 * takes a hand-built request (request line + Host/X-API-Key/Content-Type
 * headers + body), and QHTTPREAD fetches the announced body byte-exact.
 * The header block is written straight to UART2 and only its length is
 * logged, so the key never reaches the monitor.
 */
int httpExchange(HttpMethod method,
                 const String& url,
                 const String& payload,
                 String& body,
                 const String& extraHeaders) {
    body = "";
    lastHttpStatus_ = 0;

    if (!dataLinkUp_) {
        Serial.println("[SIM] no data link; request skipped");
        return 0;
    }

    const bool https = url.startsWith("https://");
    const char* verb = (method == HttpPost) ? "POST" : "GET";

    auto fail = [&](const char* why) -> int {
        Serial.printf("[SIM] %s; request abandoned\n", why);
        // Cancel a half-open QHTTP session; the reply is irrelevant here.
        simSendCommand("AT+QHTTPSTOP", 2000);
        checkBearer();
        return 0;
    };

    String host, path;
    if (!splitUrl(url, host, path)) return fail("malformed URL");
    const String key = apiKeyFrom(extraHeaders);
    if (key.length() == 0) return fail("no API key for this endpoint");

    // A previous exchange can leave a session half open; start clean.
    simSendCommand("AT+QHTTPSTOP", 1000);
    if (!qhttpConfigure(https)) return fail("EC20F HTTP setup failed");

    Serial.println(String("[SIM] HTTP URL ") + url);

    // The URL goes in alone: the modem answers CONNECT, then takes exactly
    // url.length() bytes with no line terminator.
    {
        char command[48];
        snprintf(command, sizeof(command), "AT+QHTTPURL=%u,20", (unsigned)url.length());
        const String reply = simSendCommand(command, SIM_CMD_TIMEOUT_MS);
        if (reply.indexOf("CONNECT") < 0) return fail("URL input was not opened");
        Serial2.write((const uint8_t*)url.c_str(), url.length());
        const String ack = read(SIM_CMD_TIMEOUT_MS);
        if (ack.indexOf("OK") < 0) return fail("URL was not accepted");
    }

    // Hand-built request. Same request line, same header names and same JSON
    // bytes the Wi-Fi route sends, so the backend cannot tell routes apart.
    String head = String(verb) + " " + path + " HTTP/1.1\r\n";
    head += "Host: ";
    head += host;
    head += "\r\nX-API-Key: ";
    head += key;
    head += "\r\nAccept: application/json\r\n";
    if (method == HttpPost) {
        head += "Content-Type: application/json\r\nContent-Length: ";
        head += String(payload.length());
        head += "\r\n";
    }
    head += "Connection: close\r\n\r\n";
    const String packet = (method == HttpPost) ? head + payload : head;

    const uint32_t rspSec = httpResponseSeconds();
    Serial.printf("[SIM] HTTP request %s %s (%u bytes)\n",
                  verb, path.c_str(), (unsigned)packet.length());

    String action;
    if (method == HttpPost) {
        char command[64];
        snprintf(command, sizeof(command), "AT+QHTTPPOST=%u,20,%lu",
                 (unsigned)packet.length(), (unsigned long)rspSec);
        action = simSendCommand(command, SIM_CMD_TIMEOUT_MS);
    } else {
        char command[64];
        snprintf(command, sizeof(command), "AT+QHTTPGET=%lu,%u",
                 (unsigned long)rspSec, (unsigned)packet.length());
        action = simSendCommand(command, SIM_CMD_TIMEOUT_MS);
    }
    if (action.indexOf("CONNECT") < 0) return fail("request input was not opened");
    Serial2.write((const uint8_t*)packet.c_str(), packet.length());

    // The command answers OK once the block is taken; the +QHTTPGET /
    // +QHTTPPOST result code follows when the server has actually answered.
    String result = read(SIM_CMD_TIMEOUT_MS);
    if (result.indexOf("OK") < 0) return fail("request block was not accepted");
    result += read(SIM_HTTP_TIMEOUT_MS, 100);

    // +QHTTPGET: <err>[,<httprspcode>[,<content_length>]]
    const char* urc = (method == HttpPost) ? "+QHTTPPOST: " : "+QHTTPGET: ";
    const int at = result.indexOf(urc);
    if (at < 0) return fail("no HTTP response within the timeout");

    const String fields = result.substring(at + strlen(urc));
    const int comma1 = fields.indexOf(',');
    if (comma1 < 0) return fail("unparsable modem status line");

    const int err = fields.substring(0, comma1).toInt();
    if (err != 0) {
        Serial.printf("[SIM] modem HTTP error %d\n", err);
        return fail("server exchange failed");
    }

    int status = 0;
    long length = 0;
    bool lengthKnown = false;
    const int comma2 = fields.indexOf(',', comma1 + 1);
    if (comma2 >= 0) {
        status = fields.substring(comma1 + 1, comma2).toInt();
        length = fields.substring(comma2 + 1).toInt();
        lengthKnown = true;
    } else {
        status = fields.substring(comma1 + 1).toInt();
    }
    lastHttpStatus_ = status;
    if (lengthKnown) {
        Serial.printf("[SIM] HTTP response/status %d (%ld bytes)\n", status, length);
    } else {
        // No length in the URC is NOT proof of an empty body: responses
        // without a knowable length up front omit it, so the body is still
        // fetched below instead of assumed absent.
        Serial.printf("[SIM] HTTP response/status %d (length not in URC)\n", status);
    }

    // 204/304 never carry a body and are returned as-is; a 200 is always
    // probed with QHTTPREAD, and error statuses keep their bodies whenever
    // the modem announced one.
    const bool wantBody = (status == 200) ||
                          (lengthKnown && length > 0 && status != 204 && status != 304);
    if (wantBody) {
        if (!qhttpReadBody(body, rspSec, lengthKnown ? length : -1)) {
            return fail("body read failed");
        }
        Serial.println("[SIM] QHTTPREAD response received");
    }

    if (status == 0) checkBearer();  // status 0 means the request itself failed
    return status;
}

}  // namespace

bool simInit() {
    if (!SIM_ENABLED) return false;
    if (modemAnswered_) return true;

    if (!uartUp_) {
        Serial2.begin(SIM_BAUD_RATE, SERIAL_8N1, SIM_RX_PIN, SIM_TX_PIN);
        uartUp_ = true;
        delay(100);
        Serial.printf("[SIM] UART2 up: RX GPIO%d  TX GPIO%d  %lu baud\n",
                      SIM_RX_PIN, SIM_TX_PIN, (unsigned long)SIM_BAUD_RATE);
    }

    const int attempts = everProbed_ ? 1 : 3;
    everProbed_ = true;
    for (int attempt = 1; attempt <= attempts && !modemAnswered_; ++attempt) {
        const String reply = simSendCommand("AT", SIM_CMD_TIMEOUT_MS);
        if (reply.indexOf("OK") >= 0) {
            modemAnswered_ = true;
            break;
        }
        Serial.printf("[SIM] AT attempt %d of %d: no answer\n", attempt, attempts);
        delay(300);
    }

    if (!modemAnswered_) {
        Serial.println("[SIM] modem not answering. Check SIM TXD -> GPIO16, SIM RXD -> GPIO17,");
        Serial.println("[SIM] a common GND, and the modem's power supply (not the ESP32 3V3 pin).");
        return false;
    }

    Serial.println("[SIM] modem answering");
    simSendCommand("ATE0", SIM_CMD_TIMEOUT_MS);

    const String pin = simSendCommand("AT+CPIN?", SIM_CMD_TIMEOUT_MS);
    if (pin.indexOf("+CPIN: READY") >= 0) {
        Serial.println("[SIM] SIM ready");
    } else if (pin.indexOf("+CPIN") >= 0) {
        Serial.println("[SIM] SIM not ready: PIN required, or no SIM detected");
    } else {
        Serial.println("[SIM] SIM state unknown");
    }
    return true;
}

bool simPresent() {
    return SIM_ENABLED && modemAnswered_;
}

bool simIsReady() {
    return SIM_ENABLED && dataLinkUp_;
}

bool simConnectInternet(const char* apn) {
    if (!SIM_ENABLED) return false;

    // Fail fast on an unconfigured APN before spending a bounded AT probe on
    // a link that could never be built anyway.
    if (apn == nullptr || apn[0] == '\0' || strcmp(apn, "YOUR_APN_HERE") == 0) {
        Serial.println("[SIM] APN not configured: put the carrier's APN in pedalya_lcd/secrets.h as SIM_APN");
        dataLinkUp_ = false;
        return false;
    }

    if (!simInit()) {
        dataLinkUp_ = false;
        return false;
    }

    // Full radio functionality; harmless when the modem is already there.
    simSendCommand("AT+CFUN=1", SIM_NET_TIMEOUT_MS);

    String reply = simSendCommand(String("AT+CGDCONT=1,\"IP\",\"") + apn + "\"", SIM_CMD_TIMEOUT_MS);
    if (reply.indexOf("OK") < 0) {
        Serial.println("[SIM] APN was rejected by the modem");
        dataLinkUp_ = false;
        return false;
    }

    reply = simSendCommand("AT+CGATT=1", SIM_NET_TIMEOUT_MS);
    if (reply.indexOf("OK") < 0) {
        Serial.println("[SIM] packet attach failed: no coverage, or the SIM has no data service");
        dataLinkUp_ = false;
        return false;
    }

    const String csq = simSendCommand("AT+CSQ", SIM_CMD_TIMEOUT_MS);
    const int at = csq.indexOf("+CSQ: ");
    if (at >= 0) {
        const int bars = csq.substring(at + 6).toInt();
        const char* quality = bars >= 99 ? "unknown"
                                         : (bars >= 20 ? "good" : (bars >= 10 ? "fair" : "weak"));
        Serial.printf("[SIM] signal %s (csq %d)\n", quality, bars);
    }

    // Some firmware answers ERROR when the context is already active, so the
    // verdict comes from the query rather than from this write.
    simSendCommand("AT+CGACT=1,1", SIM_NET_TIMEOUT_MS);
    const String state = simSendCommand("AT+CGACT?", SIM_CMD_TIMEOUT_MS);
    if (state.indexOf("+CGACT: 1,1") < 0) {
        Serial.println("[SIM] PDP context did not activate");
        dataLinkUp_ = false;
        return false;
    }

    dataLinkUp_ = true;
    Serial.println("[SIM] data link up");
    return true;
}

String simSendCommand(const String& command, uint32_t timeoutMs) {
    if (!uartUp_) return String();

    Serial.println(String("[SIM] >> ") + redact(command));
    discardInput();
    Serial2.print(command);
    Serial2.print("\r\n");

    const String reply = read(timeoutMs);
    if (reply.length() == 0) {
        Serial.println("[SIM] << (no reply)");
    } else if (reply.indexOf("OK") < 0 && reply.indexOf("ERROR") < 0 &&
               reply.indexOf("DOWNLOAD") < 0 && reply.indexOf("CONNECT") < 0 &&
               reply.indexOf("+HTTPACTION") < 0 && reply.indexOf("+QHTTP") < 0) {
        logRx(reply);
    }
    return reply;
}

bool simHttpPost(const String& url,
                 const String& jsonPayload,
                 String* responseBody,
                 const String& extraHeaders) {
    String body;
    const int status = httpExchange(HttpPost, url, jsonPayload, body, extraHeaders);
    if (responseBody) *responseBody = body;
    return status >= 200 && status < 300;
}

bool simHttpGet(const String& url, String* responseBody, const String& extraHeaders) {
    String body;
    const int status = httpExchange(HttpGet, url, String(), body, extraHeaders);
    if (responseBody) *responseBody = body;
    return status >= 200 && status < 300;
}

int simLastHttpStatus() {
    return lastHttpStatus_;
}
