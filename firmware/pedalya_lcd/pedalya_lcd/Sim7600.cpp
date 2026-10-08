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
           line.startsWith("+CME ERROR") || line.startsWith("+CMS ERROR") ||
           line.startsWith("+CMD NOT SUPPORT") ||
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

/**
 * One complete HTTP exchange through the modem's HTTP service.
 * Returns the HTTP status, or 0 when no transport completed the request.
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
    if (url.startsWith("https://")) {
        Serial.println("[SIM] TLS is not configured on the modem; use HTTP (USE_HTTPS 0) or send this request over Wi-Fi");
        return 0;
    }

    auto fail = [&](const char* why) -> int {
        Serial.printf("[SIM] %s; request abandoned\n", why);
        simSendCommand("AT+HTTPTERM", 1000);
        checkBearer();
        return 0;
    };

    // A previous exchange can leave the service open; start every request clean.
    simSendCommand("AT+HTTPTERM", 1000);
    String reply = simSendCommand("AT+HTTPINIT", 4000);
    if (reply.indexOf("OK") < 0) return fail("HTTPINIT failed");

    reply = simSendCommand("AT+HTTPPARA=\"URL\",\"" + url + "\"", SIM_CMD_TIMEOUT_MS);
    if (reply.indexOf("OK") < 0) return fail("URL rejected");

    if (method == HttpPost) {
        reply = simSendCommand("AT+HTTPPARA=\"CONTENT\",\"application/json\"", SIM_CMD_TIMEOUT_MS);
        if (reply.indexOf("OK") < 0) return fail("application/json content type rejected");
    }

    if (extraHeaders.length() > 0) {
        // The modem's USERDATA parameter carries one header line, which is the
        // X-API-Key header the backend requires; Content-Type has its own
        // parameter above.
        reply = simSendCommand("AT+HTTPPARA=\"USERDATA\",\"" + extraHeaders + "\"", SIM_CMD_TIMEOUT_MS);
        if (reply.indexOf("OK") < 0) return fail("X-API-Key header rejected");
    }

    if (method == HttpPost && payload.length() > 0) {
        char command[80];
        snprintf(command, sizeof(command), "AT+HTTPDATA=%u,%u",
                 (unsigned)payload.length(), (unsigned)SIM_HTTP_TIMEOUT_MS);
        reply = simSendCommand(command, SIM_CMD_TIMEOUT_MS);
        if (reply.indexOf("DOWNLOAD") < 0 && reply.indexOf('>') < 0) {
            return fail("HTTPDATA refused the payload");
        }
        Serial2.write((const uint8_t*)payload.c_str(), payload.length());
        const String ack = read(SIM_CMD_TIMEOUT_MS);
        if (ack.indexOf("OK") < 0) return fail("payload was not accepted");
    }

    reply = simSendCommand(method == HttpPost ? "AT+HTTPACTION=1" : "AT+HTTPACTION=0",
                           SIM_CMD_TIMEOUT_MS);
    if (reply.indexOf("OK") < 0) return fail("HTTPACTION refused");

    // The command answers OK at once; the +HTTPACTION result code follows when
    // the server has actually answered.
    String result = reply;
    if (result.indexOf("+HTTPACTION: ") < 0) result = read(SIM_HTTP_TIMEOUT_MS, 100);

    const int at = result.indexOf("+HTTPACTION: ");
    if (at < 0) return fail("no HTTP response within the timeout");

    const String fields = result.substring(at + 13);
    const int comma1 = fields.indexOf(',');
    const int comma2 = fields.indexOf(',', comma1 + 1);
    if (comma1 < 0 || comma2 < 0) return fail("unparsable +HTTPACTION line");

    const int status = fields.substring(comma1 + 1, comma2).toInt();
    const int length = fields.substring(comma2 + 1).toInt();
    lastHttpStatus_ = status;

    const char* verb = (method == HttpPost) ? "POST" : "GET";
    Serial.printf("[SIM] %s %s -> %d (%d bytes)\n", verb, url.c_str(), status, length);

    if (length > 0) {
        // The announced length is authoritative: it lets the body be read
        // byte-exact even if it contains a line that looks like a result code.
        String head;
        discardInput();
        Serial2.print("AT+HTTPREAD\r\n");
        const uint32_t start = millis();
        while (head.indexOf('\n') < 0 && (uint32_t)(millis() - start) < SIM_CMD_TIMEOUT_MS) {
            while (Serial2.available() > 0) head += (char)Serial2.read();
            delay(2);
        }
        const int marker = head.indexOf("+HTTPREAD: ");
        if (marker >= 0) {
            const int numberEnd = head.indexOf('\n', marker);
            const int announced = numberEnd > 0
                                      ? head.substring(marker + 11, numberEnd).toInt()
                                      : 0;
            String data = numberEnd > 0 ? head.substring(numberEnd + 1) : String();
            const int wanted = announced > 0 ? announced : length;
            if ((int)data.length() < wanted) {
                data += readExact(wanted - data.length(), SIM_HTTP_TIMEOUT_MS);
            } else if ((int)data.length() > wanted) {
                data.remove(wanted);
            }
            body = data;
        }
        read(SIM_CMD_TIMEOUT_MS);  // trailing OK
    }

    simSendCommand("AT+HTTPTERM", 2000);
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
               reply.indexOf("DOWNLOAD") < 0 && reply.indexOf("+HTTPACTION") < 0) {
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
