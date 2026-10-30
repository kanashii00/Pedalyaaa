#include "Sim7600.h"

#include <Arduino.h>

namespace {

// UART2 belongs to this module alone: the GPS is on UART1, the monitor on
// UART0 (TX0/RX0). Nothing else opens Serial2.
bool uartUp_ = false;
bool modemAnswered_ = false;
bool registered_ = false;
bool dataLinkUp_ = false;
int lastHttpStatus_ = 0;

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
 * Only a completed line counts, so a half-received "O" or "+QHTTPGET: 0,200"
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
String read(uint32_t timeoutMs, uint32_t quietMs = 250) {
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
        registered_ = false;
    }
}

enum HttpMethod { HttpGet = 0, HttpPost = 1 };

// QHTTP time base is seconds (the SIM_* timeouts are milliseconds and only
// bound the ESP32-side read). Response wait stays under the caller's read
// budget so a hung request cannot starve the main loop.
const uint32_t QHTTP_RSP_TIMEOUT_S = 30;
const uint32_t QHTTP_INPUT_TIMEOUT_S = 10;
const uint32_t QHTTP_READ_TIMEOUT_S = 30;

/** Splits an http(s)://host[:port][/path] URL for the request line + Host. */
bool splitUrl(const String& url, bool& isHttps, String& host, uint16_t& port, String& path) {
    isHttps = url.startsWith("https://");
    String rest = url.substring(isHttps ? 8 : 7);
    int slash = rest.indexOf('/');
    String authority = slash >= 0 ? rest.substring(0, slash) : rest;
    path = slash >= 0 ? rest.substring(slash) : "/";
    int colon = authority.indexOf(':');
    if (colon >= 0) {
        host = authority.substring(0, colon);
        port = (uint16_t)authority.substring(colon + 1).toInt();
    } else {
        host = authority;
        port = isHttps ? 443 : 80;
    }
    return host.length() > 0 && path.length() > 0;
}

// TEMP-DIAG: verbose QHTTPREAD framing diagnostics while the EC20 response
// retrieval is being verified against the live modem. Set to false afterwards;
// the [api] RAW RESPONSE print in PedalyaApi stays regardless.
const bool QHTTPREAD_DIAG = true;

/**
 * Reads the response body after a +QHTTPGET/+QHTTPPOST result URC.
 *
 * EC20 framing is "+QHTTPREAD: <len>" followed by exactly <len> raw bytes and
 * a trailing OK result code. The body is taken as EXACTLY <len> bytes (topped
 * up with readExact when the dump is still streaming), so a UART pause
 * mid-dump, or JSON containing "OK" / "+QHTTP" / line breaks, can never
 * corrupt or empty the framing. Only the modem's trailing OK line is ever
 * stripped, and only outside the <len> bytes.
 *
 * Never fails the exchange and never touches the bearer: a rejected or empty
 * READ keeps the HTTP status already obtained (204 legitimately has no body;
 * an empty body is not a dead link).
 */
String qhttpReadBody(long urcLength) {
    (void)urcLength;  // URC content-length is a diagnostic hint only; the
                      // +QHTTPREAD framing below is authoritative.
    String body;

    String rd = simSendCommand("AT+QHTTPREAD=" + String(QHTTP_READ_TIMEOUT_S),
                               SIM_HTTP_TIMEOUT_MS);

    const int mr = rd.indexOf("+QHTTPREAD:");
    if (mr < 0) {
        // Modem answered ERROR (e.g. nothing stored for this session) or
        // stayed silent: keep the HTTP status, report no body.
        if (QHTTPREAD_DIAG) {
            Serial.printf("[SIM] QHTTPREAD unframed reply (%u byte(s)); no body stored\n",
                          (unsigned)rd.length());
        }
        return body;
    }

    // Declared length, parsed digit-by-digit (never toInt here: leading
    // spaces and the trailing CR must not zero it).
    long declared = -1;
    int p = mr + 11;  // skip "+QHTTPREAD:"
    while (p < (int)rd.length() && (rd[p] == ' ' || rd[p] == '\t')) p++;
    if (p < (int)rd.length() && rd[p] >= '0' && rd[p] <= '9') {
        declared = 0;
        while (p < (int)rd.length() && rd[p] >= '0' && rd[p] <= '9') {
            declared = declared * 10 + (rd[p] - '0');
            p++;
        }
    }
    int lineEnd = rd.indexOf('\n', mr);
    if (lineEnd < 0) {
        // Marker arrived without its line ending yet; give the rest a moment.
        rd += read(SIM_HTTP_TIMEOUT_MS);
        lineEnd = rd.indexOf('\n', mr);
        if (lineEnd < 0) lineEnd = rd.indexOf('\r', mr);
    }

    if (QHTTPREAD_DIAG) {
        String marker = (lineEnd >= 0) ? rd.substring(mr, (unsigned int)lineEnd)
                                       : rd.substring(mr);
        if (marker.length() > 40) marker = marker.substring(0, 40);
        Serial.printf("[SIM] QHTTPREAD framing: %s (declared %ld byte(s))\n",
                      marker.c_str(), declared);
    }

    if (lineEnd < 0 || declared < 0) {
        if (QHTTPREAD_DIAG) {
            Serial.println("[SIM] QHTTPREAD framing incomplete; no body taken");
        }
        return body;
    }

    String have = rd.substring((unsigned int)(lineEnd + 1));
    if ((long)have.length() < declared) {
        // Dump still streaming (or paused mid-record): take exactly the
        // missing bytes, with no result-code shortcut.
        have += readExact((size_t)(declared - have.length()), SIM_HTTP_TIMEOUT_MS);
    }
    const long keep = (declared < (long)have.length()) ? declared : (long)have.length();
    if (keep < declared && QHTTPREAD_DIAG) {
        Serial.printf("[SIM] QHTTPREAD short read: declared %ld, received %u\n",
                      declared, (unsigned)have.length());
    }
    body = have.substring(0, (unsigned int)keep);

    Serial.println("[SIM] QHTTPREAD response received");
    if (QHTTPREAD_DIAG) {
        Serial.printf("[SIM] QHTTPREAD declared=%ld received=%u kept=%u\n",
                      declared, (unsigned)have.length(), (unsigned)body.length());
    }
    return body;
}

/**
 * One complete HTTP exchange through the modem's HTTP service.
 * Returns the HTTP status, or 0 when no transport completed the request.
 *
 * Custom request headers (X-API-Key) ride on AT+QHTTPCFG="requestheader",1:
 * after the QHTTPURL/QHTTPGET/QHTTPPOST prompt the full request head is
 * input verbatim, so the key is transmitted exactly like a Wi-Fi request.
 * The key itself is never printed; only byte counts reach the Serial log.
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

    bool isHttps = false;
    String host;
    uint16_t port = 0;
    String path;
    if (!splitUrl(url, isHttps, host, port, path)) {
        Serial.println("[SIM] malformed URL; request abandoned");
        return 0;
    }
    const bool wantHttps = (USE_HTTPS == 1);
    if (wantHttps != isHttps) {
        Serial.println("[SIM] URL scheme disagrees with USE_HTTPS; request abandoned");
        return 0;
    }

    Serial.println("[SIM] EC20F HTTP initialization");
    Serial.printf("[SIM] HTTPS configuration (USE_HTTPS=%d)\n", USE_HTTPS);
    Serial.printf("[SIM] HTTP URL: %s\n", url.c_str());
    Serial.printf("[SIM] HTTP request: %s %s (%u header byte(s), %u body byte(s))\n",
                  method == HttpPost ? "POST" : "GET", url.c_str(),
                  (unsigned)extraHeaders.length(), (unsigned)payload.length());

    auto fail = [&](const char* why) -> int {
        Serial.printf("[SIM] %s; request abandoned\n", why);
        checkBearer();
        return 0;
    };

    // PDP context 1 is the one simConnectInternet() activates (AT+CGDCONT=1 /
    // AT+CGACT=1,1); the valid range is 1-16. "requestheader",1 switches the
    // modem to custom-header mode so X-API-Key is actually transmitted.
    // "responseheader",0 keeps the reply to status + body via QHTTPREAD.
    simSendCommand("AT+QHTTPCFG=\"contextid\",1", SIM_CMD_TIMEOUT_MS);
    simSendCommand("AT+QHTTPCFG=\"requestheader\",1", SIM_CMD_TIMEOUT_MS);
    simSendCommand("AT+QHTTPCFG=\"responseheader\",0", SIM_CMD_TIMEOUT_MS);

    if (isHttps) {
        if (SIM_TLS_CA_FILE[0] == '\0') {
            Serial.println("[SIM] HTTPS blocked: no verified CA file is configured for the EC20F");
            Serial.println("[SIM] Provision an approved CA into modem UFS and set SIM_TLS_CA_FILE in secrets.h.");
            return 0;
        }
        simSendCommand("AT+QHTTPCFG=\"sslctxid\",1", SIM_CMD_TIMEOUT_MS);
        simSendCommand("AT+QSSLCFG=\"sslversion\",1,3", SIM_CMD_TIMEOUT_MS);
        simSendCommand("AT+QSSLCFG=\"ciphersuite\",1,0xFFFF", SIM_CMD_TIMEOUT_MS);
        const String caReply = simSendCommand(String("AT+QSSLCFG=\"cacert\",1,\"") +
                                              SIM_TLS_CA_FILE + "\"", SIM_CMD_TIMEOUT_MS);
        if (caReply.indexOf("OK") < 0) {
            Serial.println("[SIM] HTTPS blocked: EC20F rejected the configured CA file");
            return 0;
        }
        const String levelReply = simSendCommand("AT+QSSLCFG=\"seclevel\",1,1", SIM_CMD_TIMEOUT_MS);
        if (levelReply.indexOf("OK") < 0) {
            Serial.println("[SIM] HTTPS blocked: EC20F could not enable certificate verification");
            return 0;
        }
        // Tunnel and hosted hostnames only complete the handshake when the
        // client sends the hostname (SNI). Without this the TLS handshake
        // against a shared front-end fails before any HTTP is exchanged.
        simSendCommand("AT+QSSLCFG=\"sni\",1,1", SIM_CMD_TIMEOUT_MS);
    } else {
        simSendCommand("AT+QHTTPCFG=\"sslctxid\",0", SIM_CMD_TIMEOUT_MS);
    }

    // The URL is input as exactly <length> bytes after CONNECT: no trailing
    // CRLF, which would overrun the declared length on strict firmware.
    String urlCmd = "AT+QHTTPURL=" + String(url.length()) + "," + String(QHTTP_INPUT_TIMEOUT_S);
    String urlReply = simSendCommand(urlCmd, SIM_CMD_TIMEOUT_MS);
    if (urlReply.indexOf("CONNECT") < 0) return fail("QHTTPURL prompt not received");
    Serial2.write((const uint8_t*)url.c_str(), url.length());
    String urlAck = read(SIM_CMD_TIMEOUT_MS);
    if (urlAck.indexOf("OK") < 0) return fail("QHTTPURL rejected");

    // Full request head, sent verbatim after the GET/POST prompt. Host carries
    // the port whenever it is off the scheme default; the block always ends in
    // a blank line, and POST appends the JSON body after it.
    String head = method == HttpPost ? "POST " : "GET ";
    head += path;
    head += " HTTP/1.1\r\nHost: ";
    head += host;
    if ((isHttps && port != 443) || (!isHttps && port != 80)) {
        head += ":";
        head += String(port);
    }
    head += "\r\n";
    if (extraHeaders.length() > 0) {
        head += extraHeaders;
        head += "\r\n";
    }
    head += "Accept: application/json\r\n";
    if (method == HttpPost) {
        head += "Content-Type: application/json\r\nContent-Length: ";
        head += String(payload.length());
        head += "\r\n";
    }
    head += "Connection: close\r\n\r\n";

    String resultTag;
    String setupCmd;
    if (method == HttpPost) {
        resultTag = "+QHTTPPOST:";
        setupCmd = "AT+QHTTPPOST=" + String(head.length() + payload.length()) +
                   "," + String(QHTTP_INPUT_TIMEOUT_S) + "," + String(QHTTP_RSP_TIMEOUT_S);
    } else {
        resultTag = "+QHTTPGET:";
        setupCmd = "AT+QHTTPGET=" + String(QHTTP_RSP_TIMEOUT_S) + "," +
                   String(head.length()) + "," + String(QHTTP_INPUT_TIMEOUT_S);
    }

    String reply = simSendCommand(setupCmd, SIM_CMD_TIMEOUT_MS);
    if (reply.indexOf("CONNECT") < 0) return fail("request prompt not received");
    Serial2.write((const uint8_t*)head.c_str(), head.length());
    if (method == HttpPost && payload.length() > 0) {
        Serial2.write((const uint8_t*)payload.c_str(), payload.length());
    }
    String sentAck = read(SIM_HTTP_TIMEOUT_MS);
    if (sentAck.indexOf("OK") < 0) return fail("request input not accepted");

    String res = read(SIM_HTTP_TIMEOUT_MS * 2);
    int tag = res.indexOf(resultTag + " 0,");
    if (tag < 0) tag = res.indexOf(resultTag);
    if (tag < 0) return fail("no HTTP result URC");
    int c1 = res.indexOf(',', tag);
    int c2 = (c1 >= 0) ? res.indexOf(',', c1 + 1) : -1;
    int status = 0;
    if (c1 >= 0 && c2 >= 0) {
        status = res.substring(c1 + 1, c2).toInt();
    } else if (c1 >= 0) {
        status = res.substring(c1 + 1).toInt();
    }
    // Third URC field is the modem's own content-length report
    // ("+QHTTPGET: 0,200,348"). Parsed digit-by-digit and kept as a
    // diagnostic hint; the +QHTTPREAD framing stays authoritative, so a
    // wrong URC length can never empty or truncate the body.
    long urcLength = -1;
    if (c2 >= 0) {
        int q = c2 + 1;
        while (q < (int)res.length() && (res[q] == ' ' || res[q] == '\t')) q++;
        if (q < (int)res.length() && res[q] >= '0' && res[q] <= '9') {
            urcLength = 0;
            while (q < (int)res.length() && res[q] >= '0' && res[q] <= '9') {
                urcLength = urcLength * 10 + (res[q] - '0');
                q++;
            }
        }
    }
    lastHttpStatus_ = status;
    if (QHTTPREAD_DIAG) {
        Serial.printf("[SIM] QHTTPGET content length: %ld\n", urcLength);
    }
    if (status > 0) {
        body = qhttpReadBody(urcLength);
    }
    Serial.printf("[SIM] HTTP response/status: %d (body len=%u)\n", status, (unsigned)body.length());
    return status;
}

}  // namespace

static bool simSeenUrc(const String& text, const char* urc) {
    return text.indexOf(urc) >= 0;
}

/** One line of the staged bring-up log, printed once per stage. */
static void simLogStage(const char* stage, const char* detail) {
    Serial.printf("[SIM] %s: %s\n", stage, detail);
}

/**
 * Parses "+CREG: <n>,<stat>" / "+CGREG: <n>,<stat>" / "+CEREG: <n>,<stat>"
 * or single-argument "+CREG: <stat>" and returns a human-readable string.
 */
static const char* regStatText(int stat) {
    switch (stat) {
        case 0: return "not registered, not searching";
        case 1: return "registered, home";
        case 2: return "not registered, searching";
        case 3: return "registration denied";
        case 4: return "unknown";
        case 5: return "registered, roaming";
        default: return "?";
    }
}

/**
 * Extracts registration status from a +CREG, +CGREG, or +CEREG response line.
 * Handles:
 *   "+CREG: <stat>" (single parameter)
 *   "+CREG: <n>,<stat>"
 *   "+CREG: <n>, <stat>" (with spaces)
 *   "+CREG: <n>,<stat>,<lac>,<ci>,..." (with location info)
 * Returns stat (0-5) or -1 on parse failure.
 */
static int parseRegStat(const String& reply, const char* prefix) {
    int idx = reply.indexOf(prefix);
    if (idx < 0) return -1;
    int p = idx + (int)strlen(prefix);
    while (p < (int)reply.length() && (reply[p] == ' ' || reply[p] == '\t')) p++;
    if (p >= (int)reply.length()) return -1;

    int lineEnd = reply.indexOf('\r', p);
    if (lineEnd < 0) lineEnd = reply.indexOf('\n', p);
    if (lineEnd < 0) lineEnd = reply.length();

    // Check if there is a comma before line end (e.g. "+CREG: <n>,<stat>")
    int comma = reply.indexOf(',', p);
    if (comma >= 0 && comma < lineEnd) {
        p = comma + 1;
        while (p < lineEnd && (reply[p] == ' ' || reply[p] == '\t')) p++;
    }

    if (p < lineEnd && reply[p] >= '0' && reply[p] <= '9') {
        int stat = 0;
        while (p < lineEnd && reply[p] >= '0' && reply[p] <= '9') {
            stat = stat * 10 + (reply[p] - '0');
            p++;
        }
        return stat;
    }
    return -1;
}

static inline bool isRegistered(int stat) {
    return stat == 1 || stat == 5;
}

bool simProbeAt(uint32_t timeoutMs, bool* outSawRdy, size_t* outBytesReceived) {
    if (!uartUp_) return false;
    if (outSawRdy) *outSawRdy = false;
    if (outBytesReceived) *outBytesReceived = 0;

    // Capture and log any pre-existing bytes in the RX buffer without silently dropping them
    size_t preCount = 0;
    uint8_t preBuf[32];
    while (Serial2.available() > 0 && preCount < sizeof(preBuf)) {
        preBuf[preCount++] = (uint8_t)Serial2.read();
    }
    while (Serial2.available() > 0) { Serial2.read(); preCount++; }
    if (preCount > 0) {
        Serial.printf("[SIM-AT] captured %u pre-command byte(s) from RX buffer\n", (unsigned)preCount);
        Serial.print("[SIM-AT]   pre-cmd ascii: ");
        for (size_t pi = 0; pi < preCount && pi < sizeof(preBuf); pi++) {
            const uint8_t b = preBuf[pi];
            if (b == '\r')      Serial.print("<CR>");
            else if (b == '\n') Serial.print("<LF>");
            else if (b >= 0x20 && b < 0x7F) Serial.print((char)b);
            else Serial.print('.');
        }
        Serial.println();
    }

    const uint32_t startMs = millis();
    Serial2.print("AT\r\n");
    Serial2.flush();

    String reply;
    bool sawOk = false;
    bool sawError = false;
    bool sawRdy = false;
    size_t bytesRead = 0;
    uint32_t lastByteMs = startMs;

    while ((uint32_t)(millis() - startMs) < timeoutMs) {
        while (Serial2.available() > 0) {
            int c = Serial2.read();
            if (c >= 0) {
                reply += (char)c;
                bytesRead++;
                lastByteMs = millis();
            }
        }

        // Require a fresh matching OK token (isolated word bounded by whitespace/CRLF)
        int okIdx = reply.indexOf("OK");
        while (okIdx >= 0) {
            bool preBoundary = (okIdx == 0) || (reply[okIdx - 1] == '\r' || reply[okIdx - 1] == '\n' || reply[okIdx - 1] == ' ');
            int postIdx = okIdx + 2;
            bool postBoundary = (postIdx >= (int)reply.length()) || (reply[postIdx] == '\r' || reply[postIdx] == '\n' || reply[postIdx] == ' ');
            if (preBoundary && postBoundary) {
                sawOk = true;
                break;
            }
            okIdx = reply.indexOf("OK", okIdx + 1);
        }
        if (sawOk) break;

        if (reply.indexOf("ERROR") >= 0 || reply.indexOf("+CME ERROR") >= 0) {
            sawError = true;
            break;
        }
        if (reply.indexOf("RDY") >= 0) {
            sawRdy = true;
            if (outSawRdy) *outSawRdy = true;
        }

        // Bounded quiet period: if we've received bytes and line ended, give a small window
        if (bytesRead > 0 && reply.endsWith("\n") && (uint32_t)(millis() - lastByteMs) >= 150) {
            break;
        }
        delay(2);
    }

    const uint32_t elapsedMs = millis() - startMs;
    if (outBytesReceived) *outBytesReceived = bytesRead;

    if (sawOk) {
        Serial.printf("[SIM-AT] AT probe: OK received in %lu ms (%u byte(s))\n",
                      (unsigned long)elapsedMs, (unsigned)bytesRead);
        modemAnswered_ = true;
        return true;
    }

    if (sawRdy) {
        Serial.printf("[SIM-AT] AT probe: received unsolicited boot banner 'RDY' in %lu ms, "
                      "but NO matching 'OK' yet (modem rebooting/starting up)\n", (unsigned long)elapsedMs);
    } else if (sawError) {
        Serial.printf("[SIM-AT] AT probe: modem answered ERROR in %lu ms (%u byte(s)) - UART path conducts\n",
                      (unsigned long)elapsedMs, (unsigned)bytesRead);
    } else if (bytesRead == 0) {
        Serial.printf("[SIM-AT] AT probe: attempt timed out after %lu ms with 0 bytes received\n",
                      (unsigned long)elapsedMs);
    } else {
        Serial.printf("[SIM-AT] AT probe: received %u unexpected byte(s) in %lu ms (no OK matched)\n",
                      (unsigned)bytesRead, (unsigned long)elapsedMs);
        logRx(reply);
    }

    return false;
}

bool simInit() {
    if (!SIM_ENABLED) return false;
    if (modemAnswered_) return true;

    if (!uartUp_) {
        Serial2.setRxBufferSize(1024);
        Serial2.begin(SIM_BAUD_RATE, SERIAL_8N1, SIM_RX_PIN, SIM_TX_PIN);
        uartUp_ = true;
        delay(100);
        Serial.printf("[SIM] UART2 up: RX GPIO%d  TX GPIO%d  %lu baud (rx buf 1024)\n",
                      SIM_RX_PIN, SIM_TX_PIN, (unsigned long)SIM_BAUD_RATE);

        // Drain and log any bytes already in the RX buffer.
        {
            uint8_t preBuf[64];
            size_t preCount = 0;
            while (Serial2.available() > 0 && preCount < sizeof(preBuf)) {
                preBuf[preCount++] = (uint8_t)Serial2.read();
            }
            while (Serial2.available() > 0) { Serial2.read(); preCount++; }
            if (preCount > 0) {
                size_t preLogged = (preCount < sizeof(preBuf)) ? preCount : sizeof(preBuf);
                Serial.printf("[SIM] UART2 open: %u pre-command byte(s) in RX buffer (boot URCs)\n",
                              (unsigned)preCount);
                Serial.print("[SIM]   pre-cmd hex: ");
                for (size_t pi = 0; pi < preLogged && pi < 32; pi++) {
                    Serial.printf("0x%02X ", preBuf[pi]);
                }
                if (preCount > 32) Serial.printf("... (+%u)", (unsigned)(preCount - 32));
                Serial.println();
                Serial.print("[SIM]   pre-cmd ascii: ");
                for (size_t pi = 0; pi < preLogged && pi < 32; pi++) {
                    const uint8_t b = preBuf[pi];
                    if (b == '\r')      Serial.print("<CR>");
                    else if (b == '\n') Serial.print("<LF>");
                    else if (b >= 0x20 && b < 0x7F) Serial.print((char)b);
                    else Serial.print('.');
                }
                Serial.println();
            } else {
                Serial.println("[SIM] UART2 open: no pre-command bytes (modem silent at open)");
            }
        }
    }

    // A cold EC20F emits its RDY boot banner (and only starts answering AT)
    // seconds after power. Probe AT inside a bounded window so the banner is
    // not mistaken for a dead modem: "RDY" without "OK" means "booting, keep
    // trying", silence means power/GND/TX-path, and ERROR means the path
    // conducts but baud/framing/model is wrong.
    const uint32_t windowStart = millis();
    bool sawRdy = false;
    bool sawCpinReady = false;
    bool sawRegistered = false;
    size_t totalBytesReceived = 0;

    Serial.printf("[SIM] Starting modem detection window: %lu ms budget (per-attempt timeout: %lu ms)\n",
                  (unsigned long)SIM_MODEM_READY_WINDOW_MS, (unsigned long)SIM_CMD_TIMEOUT_MS);

    int attempt = 0;
    while (!modemAnswered_) {
        const uint32_t elapsed = (uint32_t)(millis() - windowStart);
        // If no RDY was seen, the window is strictly bounded by SIM_MODEM_READY_WINDOW_MS.
        // If RDY was seen, an explicit grace extension is permitted (up to 15 attempts or +4000 ms)
        // because the modem actively announced it is rebooting/starting up.
        if (!sawRdy && elapsed >= (uint32_t)SIM_MODEM_READY_WINDOW_MS) {
            break;
        }
        if (sawRdy && (elapsed >= (uint32_t)(SIM_MODEM_READY_WINDOW_MS + 4000UL) || attempt >= 15)) {
            break;
        }

        const uint32_t remaining = (elapsed < (uint32_t)SIM_MODEM_READY_WINDOW_MS) ?
                                   ((uint32_t)SIM_MODEM_READY_WINDOW_MS - elapsed) : 0;

        // If remaining budget is less than 200 ms and no RDY was seen, stop:
        // there is insufficient time for a meaningful AT round trip without overrunning the deadline.
        if (!sawRdy && remaining < 200) {
            break;
        }

        // Clamp this attempt's probe timeout so it cannot overrun the nominal window unless RDY was seen.
        uint32_t attemptTimeout = (uint32_t)SIM_CMD_TIMEOUT_MS;
        if (!sawRdy && attemptTimeout > remaining) {
            attemptTimeout = remaining;
        }

        ++attempt;
        Serial.printf("[SIM] Attempt %d: sending AT (%lu ms elapsed, %lu ms left in window, timeout %lu ms)...\n",
                      attempt, (unsigned long)elapsed, (unsigned long)remaining, (unsigned long)attemptTimeout);

        bool rdyThisAttempt = false;
        size_t bytesThisAttempt = 0;
        if (simProbeAt(attemptTimeout, &rdyThisAttempt, &bytesThisAttempt)) {
            modemAnswered_ = true;
            break;
        }
        if (rdyThisAttempt) sawRdy = true;
        totalBytesReceived += bytesThisAttempt;

        if (!modemAnswered_) {
            const uint32_t retryDelay = sawRdy ? 150 : 350;
            const uint32_t nowElapsed = (uint32_t)(millis() - windowStart);
            if (!sawRdy && (nowElapsed + retryDelay >= (uint32_t)SIM_MODEM_READY_WINDOW_MS)) {
                break;
            }
            delay(retryDelay);
        }
    }

    if (!modemAnswered_) {
        const uint32_t totalElapsed = (uint32_t)(millis() - windowStart);
        Serial.printf("[SIM] Modem detection window expired after %lu ms (%d attempts, %u total byte(s) received).\n",
                      (unsigned long)totalElapsed, attempt, (unsigned)totalBytesReceived);
        if (sawRdy) {
            Serial.println("[SIM] Window overrun documented: unsolicited RDY was seen, allowed boot extension up to +4000 ms.");
        } else {
            Serial.println("[SIM] Deadline enforced: window strictly bounded to SIM_MODEM_READY_WINDOW_MS (no overrun allowed on silence).");
        }
        Serial.printf("[SIM] Stages seen: RDY=%s SIM_READY=no REGISTERED=no\n", sawRdy ? "yes" : "no");
        if (totalBytesReceived == 0) {
            Serial.println("[SIM] Complete UART silence detected (0 bytes returned across all attempts).");
            Serial.println("[SIM] Hardware troubleshooting checklist for EC20F silence:");
            Serial.println("[SIM]   1. POWER & CARRIER BOARD TYPE:");
            Serial.println("[SIM]      - Carrier board with 5V input (e.g. Micro-USB or VCC_5V pin with onboard regulator):");
            Serial.println("[SIM]        Supply 5.0V DC (>=2A). Do NOT under-power with 3.3V/3.8V through the board's 5V regulator!");
            Serial.println("[SIM]      - Bare module breakout connected directly to VBAT pin:");
            Serial.println("[SIM]        Supply 3.6V - 4.4V (nominal 3.8V, >=2A peak burst capability).");
            Serial.println("[SIM]      - In either case: the ESP32 3.3V pin CANNOT supply the modem's 2A RF burst current.");
            Serial.println("[SIM]   2. PWRKEY & POWER STATE:");
            Serial.println("[SIM]      - Check if carrier board has a 'POW' / 'PWRKEY' button or pin.");
            Serial.println("[SIM]      - Quectel EC20 requires pulling PWRKEY LOW for >=100ms (typically 500ms) to turn on.");
            Serial.println("[SIM]      - Check if STATUS or NETLIGHT LED on the modem board turns on or blinks.");
            Serial.println("[SIM]   3. COMMON GROUND:");
            Serial.println("[SIM]      - Ensure ESP32 GND and modem board GND are bonded with a solid ground wire.");
            Serial.println("[SIM]   4. UART PINS & LEVEL SHIFTER:");
            Serial.println("[SIM]      - Verify connection: EC20F TXD -> ESP32 GPIO16 (SIM_RX_PIN), EC20F RXD <- ESP32 GPIO17 (SIM_TX_PIN).");
            Serial.println("[SIM]      - Native EC20 module UART operates at 1.8V CMOS. If using a bare breakout PCB,");
            Serial.println("[SIM]        a bidirectional 1.8V <-> 3.3V level shifter is required.");
            Serial.println("[SIM]        (Carrier boards usually integrate level shifters; bare breakouts do not).");
            Serial.println("[SIM]   (Note: UART silence indicates physical power/serial disconnection; NOT SIM or network coverage failure.)");
        }
        return false;
    }

    Serial.println("[SIM] modem answering");
    simSendCommand("ATE0", SIM_CMD_TIMEOUT_MS);

    const String pin = simSendCommand("AT+CPIN?", SIM_CMD_TIMEOUT_MS);
    if (pin.indexOf("+CPIN: READY") >= 0) {
        Serial.println("[SIM] SIM ready");
        sawCpinReady = true;
    } else if (pin.indexOf("+CPIN") >= 0) {
        Serial.println("[SIM] SIM not ready: PIN required, or no SIM detected");
    } else {
        Serial.println("[SIM] SIM state unknown");
    }

    const String reg = simSendCommand("AT+CREG?", SIM_CMD_TIMEOUT_MS);
    int csStat = parseRegStat(reg, "+CREG:");
    if (csStat >= 0) {
        Serial.printf("[SIM] network registration: CREG stat=%d (%s)\n", csStat, regStatText(csStat));
        if (isRegistered(csStat)) {
            sawRegistered = true;
            registered_ = true;
        }
    } else {
        Serial.println("[SIM] registration state unknown");
    }

    const String csq = simSendCommand("AT+CSQ", SIM_CMD_TIMEOUT_MS);
    const int at = csq.indexOf("+CSQ: ");
    if (at >= 0) {
        const int bars = csq.substring(at + 6).toInt();
        const char* quality = bars >= 99 ? "unknown"
                                         : (bars >= 20 ? "good" : (bars >= 10 ? "fair" : "weak"));
        Serial.printf("[SIM] signal %s (csq %d)\n", quality, bars);
    }

    return true;
}

bool simPresent() {
    return SIM_ENABLED && modemAnswered_;
}

bool simIsRegistered() {
    return SIM_ENABLED && modemAnswered_ && registered_;
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

    // Check CFUN state before sending AT+CFUN=1 to prevent modem resets
    String cfunQuery = simSendCommand("AT+CFUN?", SIM_CMD_TIMEOUT_MS);
    if (cfunQuery.indexOf("+CFUN: 1") < 0) {
        Serial.println("[SIM] Setting full functionality (AT+CFUN=1)");
        simSendCommand("AT+CFUN=1", SIM_NET_TIMEOUT_MS);
        delay(300);
    }

    // Check SIM card status first
    const String cpin = simSendCommand("AT+CPIN?", SIM_CMD_TIMEOUT_MS);
    if (cpin.indexOf("+CPIN: READY") < 0) {
        Serial.printf("[SIM] SIM card not ready (%s)\n", lastLine(cpin).c_str());
        registered_ = false;
        dataLinkUp_ = false;
        return false;
    }

    String reply = simSendCommand(String("AT+CGDCONT=1,\"IP\",\"") + apn + "\"", SIM_CMD_TIMEOUT_MS);
    if (reply.indexOf("OK") < 0) {
        Serial.println("[SIM] APN was rejected by the modem");
        dataLinkUp_ = false;
        return false;
    }

    // Check network registration before attempting packet attach.
    // CGATT=1 will always fail (and burn SIM_NET_TIMEOUT_MS) if the modem
    // isn't registered on any network. Query CS (CREG), PS (CGREG), and EPS/LTE (CEREG);
    // either stat=1 (home) or stat=5 (roaming) means registration is active.
    {
        const String creg = simSendCommand("AT+CREG?", SIM_CMD_TIMEOUT_MS);
        const int csStat = parseRegStat(creg, "+CREG:");

        const String cgreg = simSendCommand("AT+CGREG?", SIM_CMD_TIMEOUT_MS);
        const int psStat = parseRegStat(cgreg, "+CGREG:");

        const String cereg = simSendCommand("AT+CEREG?", SIM_CMD_TIMEOUT_MS);
        const int epsStat = parseRegStat(cereg, "+CEREG:");

        registered_ = isRegistered(csStat) || isRegistered(psStat) || isRegistered(epsStat);

        if (!registered_) {
            Serial.printf("[SIM] not registered on any network (CREG=%d [%s], CGREG=%d [%s], CEREG=%d [%s])\n",
                          csStat, regStatText(csStat), psStat, regStatText(psStat), epsStat, regStatText(epsStat));
            Serial.println("[SIM] Skipping CGATT; check antenna, SIM card, and operator coverage");
            dataLinkUp_ = false;
            return false;
        }
        Serial.printf("[SIM] registration active: CS=%d (%s) PS=%d (%s) EPS=%d (%s)\n",
                      csStat, regStatText(csStat), psStat, regStatText(psStat), epsStat, regStatText(epsStat));

        // Query operator and signal quality when registered
        simSendCommand("AT+COPS?", SIM_CMD_TIMEOUT_MS);
        const String csq = simSendCommand("AT+CSQ", SIM_CMD_TIMEOUT_MS);
        const int at = csq.indexOf("+CSQ: ");
        if (at >= 0) {
            const int bars = csq.substring(at + 6).toInt();
            const char* quality = bars >= 99 ? "unknown"
                                             : (bars >= 20 ? "good" : (bars >= 10 ? "fair" : "weak"));
            Serial.printf("[SIM] signal %s (csq %d)\n", quality, bars);
        }
    }

    reply = simSendCommand("AT+CGATT=1", SIM_NET_TIMEOUT_MS);
    if (reply.indexOf("OK") < 0) {
        Serial.println("[SIM] packet attach failed: registered but no data service, or SIM has no data plan");
        dataLinkUp_ = false;
        return false;
    }

    // Verify attachment state
    String cgattQuery = simSendCommand("AT+CGATT?", SIM_CMD_TIMEOUT_MS);
    if (cgattQuery.indexOf("+CGATT: 1") < 0) {
        Serial.println("[SIM] packet attach not confirmed (+CGATT != 1)");
        dataLinkUp_ = false;
        return false;
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

    // Log assigned IP address
    String ipReply = simSendCommand("AT+CGPADDR=1", SIM_CMD_TIMEOUT_MS);
    int ipIdx = ipReply.indexOf("+CGPADDR: 1,\"");
    if (ipIdx >= 0) {
        int ipEnd = ipReply.indexOf("\"", ipIdx + 13);
        if (ipEnd > ipIdx + 13) {
            String ip = ipReply.substring(ipIdx + 13, ipEnd);
            Serial.printf("[SIM] assigned IP address: %s\n", ip.c_str());
        }
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

    // Detect modem reset during normal operation. EC20F emits "RDY" once
    // after a cold boot. Seeing it in a command response (outside simInit)
    // means the modem power-cycled or watchdog-reset. Invalidate the cached
    // state so the next call re-probes instead of silently failing.
    if (modemAnswered_ && command != "AT" && reply.indexOf("RDY") >= 0 &&
        reply.indexOf("OK") < 0) {
        Serial.println("[SIM] WARNING: modem emitted RDY during operation (unexpected reset?)");
        Serial.println("[SIM] Clearing cached state; will re-probe on next call");
        modemAnswered_ = false;
        registered_ = false;
        dataLinkUp_ = false;
    }

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

/* ------------------------------------------------------------------ */
/*  simRunDiagnostics – bounded AT command census for serial-monitor   */
/* ------------------------------------------------------------------ */

/**
 * Helper: sends one AT command, logs the raw response with a [SIM-DIAG]
 * prefix, and returns the response for optional further parsing.
 * Uses a longer quiet window (200 ms) to capture multi-line replies
 * like ATI and AT+CGDCONT?.
 */
static String diagCmd(const char* cmd, uint32_t timeoutMs) {
    Serial.printf("[SIM-DIAG] >> %s\n", cmd);
    discardInput();
    Serial2.print(cmd);
    Serial2.print("\r\n");
    const String reply = read(timeoutMs, 200);
    // Log the raw reply, collapsing CRLFs for readability.
    String display = reply;
    display.replace("\r\n", " | ");
    display.replace("\r", "");
    while (display.endsWith(" | ")) display.remove(display.length() - 3);
    if (display.length() > 300) display = display.substring(0, 300) + "...";
    Serial.printf("[SIM-DIAG] << %s\n", display.length() ? display.c_str() : "(no reply)");
    return reply;
}

void simRunDiagnostics() {
    if (!SIM_ENABLED) {
        Serial.println("[SIM-DIAG] SIM_ENABLED=0; skipping diagnostics");
        return;
    }
    if (!modemAnswered_) {
        Serial.println("[SIM-DIAG] modem did not answer AT; skipping diagnostics");
        return;
    }

    Serial.println("[SIM-DIAG] ======== EC20F diagnostic suite ========");

    // 1. AT - basic liveness via bounded probe
    {
        if (simProbeAt(SIM_CMD_TIMEOUT_MS)) {
            Serial.println("[SIM-DIAG] AT: modem alive");
        } else {
            Serial.println("[SIM-DIAG] AT: modem NOT responding (stale cached state?)");
        }
    }

    // 2. ATI – module identification (multi-line)
    diagCmd("ATI", SIM_CMD_TIMEOUT_MS);

    // 3. AT+CPIN? – SIM status
    {
        String r = diagCmd("AT+CPIN?", SIM_CMD_TIMEOUT_MS);
        if (r.indexOf("+CPIN: READY") >= 0) {
            Serial.println("[SIM-DIAG] SIM: ready");
        } else if (r.indexOf("+CPIN: SIM PIN") >= 0) {
            Serial.println("[SIM-DIAG] SIM: PIN required");
        } else if (r.indexOf("+CPIN: SIM PUK") >= 0) {
            Serial.println("[SIM-DIAG] SIM: PUK required");
        } else if (r.indexOf("ERROR") >= 0) {
            Serial.println("[SIM-DIAG] SIM: not detected or error");
        } else {
            Serial.println("[SIM-DIAG] SIM: unknown state");
        }
    }

    // 4. AT+CSQ – signal quality
    {
        String r = diagCmd("AT+CSQ", SIM_CMD_TIMEOUT_MS);
        int idx = r.indexOf("+CSQ: ");
        if (idx >= 0) {
            int rssi = r.substring(idx + 6).toInt();
            int commaPos = r.indexOf(',', idx + 6);
            int ber = (commaPos >= 0) ? r.substring(commaPos + 1).toInt() : 99;
            const char* quality = (rssi >= 99) ? "not detectable"
                : (rssi >= 20) ? "good (>= -73 dBm)"
                : (rssi >= 10) ? "fair (-93 to -74 dBm)"
                : "weak (< -93 dBm)";
            Serial.printf("[SIM-DIAG] CSQ: rssi=%d ber=%d -> %s\n", rssi, ber, quality);
        }
    }

    // 5. AT+COPS? – current operator
    diagCmd("AT+COPS?", SIM_CMD_TIMEOUT_MS);

    // 6. AT+CREG? – CS network registration
    {
        String r = diagCmd("AT+CREG?", SIM_CMD_TIMEOUT_MS);
        int stat = parseRegStat(r, "+CREG:");
        if (stat >= 0) {
            Serial.printf("[SIM-DIAG] CREG stat=%d (%s)\n", stat, regStatText(stat));
        }
    }

    // 7. AT+CGREG? – PS (GPRS) network registration
    {
        String r = diagCmd("AT+CGREG?", SIM_CMD_TIMEOUT_MS);
        int stat = parseRegStat(r, "+CGREG:");
        if (stat >= 0) {
            Serial.printf("[SIM-DIAG] CGREG stat=%d (%s)\n", stat, regStatText(stat));
        }
    }

    // 8. AT+CEREG? – EPS (LTE) network registration
    {
        String r = diagCmd("AT+CEREG?", SIM_CMD_TIMEOUT_MS);
        int stat = parseRegStat(r, "+CEREG:");
        if (stat >= 0) {
            Serial.printf("[SIM-DIAG] CEREG stat=%d (%s)\n", stat, regStatText(stat));
        }
    }

    // 9. AT+CGATT? – PS attachment status
    {
        String r = diagCmd("AT+CGATT?", SIM_CMD_TIMEOUT_MS);
        if (r.indexOf("+CGATT: 1") >= 0) {
            Serial.println("[SIM-DIAG] CGATT: attached");
        } else if (r.indexOf("+CGATT: 0") >= 0) {
            Serial.println("[SIM-DIAG] CGATT: not attached");
        }
    }

    // 10. AT+CGDCONT? – PDP context list (multi-line)
    diagCmd("AT+CGDCONT?", SIM_CMD_TIMEOUT_MS);

    Serial.println("[SIM-DIAG] ======== diagnostic suite complete ========");
}
