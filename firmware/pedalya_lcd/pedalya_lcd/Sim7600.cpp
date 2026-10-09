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
    // EC20F with QHTTP supports HTTPS; TLS configured later if needed

    auto fail = [&](const char* why) -> int {
        Serial.printf("[SIM] %s; request abandoned\n", why);
        simSendCommand("AT+HTTPTERM", 1000);
        checkBearer();
        return 0;
    };

    Serial.println("[SIM] EC20F HTTP initialization");
    Serial.printf("[SIM] HTTPS configuration (USE_HTTPS=%d)\n", USE_HTTPS);
    Serial.printf("[SIM] HTTP URL: %s\n", url.c_str());
    Serial.printf("[SIM] HTTP request: %s %s\n", method == HttpPost ? "POST" : "GET", url.c_str());

    auto fail = [&](const char* why) -> int {
        Serial.printf("[SIM] %s; request abandoned\n", why);
        checkBearer();
        return 0;
    };

    // Configure HTTP context (0)
    simSendCommand("AT+QHTTPCFG=\"contextid\",0", SIM_CMD_TIMEOUT_MS);
    simSendCommand("AT+QHTTPCFG=\"responseheader\",0", SIM_CMD_TIMEOUT_MS);
    simSendCommand("AT+QHTTPCFG=\"closewaittm\",20", SIM_CMD_TIMEOUT_MS);

    if (url.startsWith("https://")) {
        simSendCommand("AT+QHTTPCFG=\"sslctxid\",1", SIM_CMD_TIMEOUT_MS);
        simSendCommand("AT+QSSLCFG=\"sslversion\",1,3", SIM_CMD_TIMEOUT_MS);
        simSendCommand("AT+QSSLCFG=\"ciphersuite\",1,0xFFFF", SIM_CMD_TIMEOUT_MS);
        simSendCommand("AT+QSSLCFG=\"seclevel\",1,0", SIM_CMD_TIMEOUT_MS);
        simSendCommand("AT+QSSLCFG=\"cacert\",1,\"\"", SIM_CMD_TIMEOUT_MS);
    } else {
        simSendCommand("AT+QHTTPCFG=\"sslctxid\",0", SIM_CMD_TIMEOUT_MS);
    }

    String urlCmd = "AT+QHTTPURL=" + String(url.length()) + "," + String(SIM_CMD_TIMEOUT_MS);
    String reply = simSendCommand(urlCmd, SIM_CMD_TIMEOUT_MS);
    if (reply.indexOf('>') < 0 && reply.indexOf("CONNECT") < 0) {
        // Some firmwares give CONNECT; also just send
    }
    // Send URL data
    Serial2.print(url);
    Serial2.print("\r\n");
    String urlAck = read(SIM_CMD_TIMEOUT_MS);
    if (urlAck.indexOf("OK") < 0) return fail("QHTTPURL rejected");

    if (method == HttpPost) {
        String len = String(payload.length());
        String hcmd = "AT+QHTTPPOST=" + len + "," + String(SIM_HTTP_TIMEOUT_MS) + "," + String(SIM_HTTP_TIMEOUT_MS);
        reply = simSendCommand(hcmd, SIM_CMD_TIMEOUT_MS);
        if (reply.indexOf('>') < 0 && reply.indexOf("CONNECT") < 0) {
            return fail("QHTTPPOST prompt not received");
        }
        Serial2.write((const uint8_t*)payload.c_str(), payload.length());
        const String packAck = read(SIM_HTTP_TIMEOUT_MS);
        if (packAck.indexOf("OK") < 0) return fail("payload not accepted");
        if (packAck.indexOf("+QHTTPPOST:") < 0) {
            String postRes = read(SIM_HTTP_TIMEOUT_MS, 100);
            if (postRes.indexOf("+QHTTPPOST:") < 0) return fail("no QHTTPPOST result");
        }
        String postRes = read(SIM_HTTP_TIMEOUT_MS);
        int p = postRes.indexOf("+QHTTPPOST: 0,");
        if (p < 0) p = postRes.indexOf("+QHTTPPOST:");
        if (p < 0) return fail("unparsable QHTTPPOST");
        int c1 = postRes.indexOf(',', p);
        int c2 = postRes.indexOf(',', c1 + 1);
        int status = 0;
        if (c1 >= 0 && c2 >= 0) {
            status = postRes.substring(c1 + 1, c2).toInt();
        } else if (c1 >= 0) {
            status = postRes.substring(c1 + 1).toInt();
        }
        lastHttpStatus_ = status;
        if (status > 0) {
            simSendCommand("AT+QHTTPREAD=" + String(SIM_HTTP_TIMEOUT_MS), SIM_CMD_TIMEOUT_MS);
            String rd = read(SIM_HTTP_TIMEOUT_MS * 2);
            int mr = rd.indexOf("+QHTTPREAD: ");
            if (mr >= 0) {
                int nl = rd.indexOf('\n', mr);
                if (nl >= 0) {
                    body = rd.substring(nl + 1);
                }
            }
            int ok2 = body.lastIndexOf("OK");
            if (ok2 >= 0) body.remove(ok2);
            while (body.endsWith("\r\n") || body.endsWith("\n") || body.endsWith("\r")) {
                body.remove(body.length() - 1);
            }
        }
        Serial.printf("[SIM] HTTP response/status: %d (body len=%u)\n", status, (unsigned)body.length());
        return status;
    } else {
        String getCmd = "AT+QHTTPGET=" + String(SIM_HTTP_TIMEOUT_MS);
        reply = simSendCommand(getCmd, SIM_CMD_TIMEOUT_MS);
        String gres = read(SIM_HTTP_TIMEOUT_MS * 2);
        int g = gres.indexOf("+QHTTPGET: 0,");
        if (g < 0) g = gres.indexOf("+QHTTPGET:");
        if (g < 0) return fail("no QHTTPGET result");
        int c1 = gres.indexOf(',', g);
        int c2 = gres.indexOf(',', c1 + 1);
        int status = 0;
        if (c1 >= 0 && c2 >= 0) {
            status = gres.substring(c1 + 1, c2).toInt();
        } else if (c1 >= 0) {
            status = gres.substring(c1 + 1).toInt();
        }
        lastHttpStatus_ = status;
        if (status > 0) {
            simSendCommand("AT+QHTTPREAD=" + String(SIM_HTTP_TIMEOUT_MS), SIM_CMD_TIMEOUT_MS);
            String rd = read(SIM_HTTP_TIMEOUT_MS * 2);
            int mr = rd.indexOf("+QHTTPREAD: ");
            if (mr >= 0) {
                int nl = rd.indexOf('\n', mr);
                if (nl >= 0) {
                    body = rd.substring(nl + 1);
                }
            }
            int ok2 = body.lastIndexOf("OK");
            if (ok2 >= 0) body.remove(ok2);
            while (body.endsWith("\r\n") || body.endsWith("\n") || body.endsWith("\r")) {
                body.remove(body.length() - 1);
            }
        }
        Serial.printf("[SIM] HTTP response/status: %d (body len=%u)\n", status, (unsigned)body.length());
        return status;
    }
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
