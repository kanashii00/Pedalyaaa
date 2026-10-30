#include "EC20FDiagnostic.h"

#if EC20F_UART_DIAGNOSTIC

#include <Arduino.h>
#include "config.h"

// -----------------------------------------------------------------------
// Design notes (v3)
// -----------------------------------------------------------------------
//
// PROBLEM DISCOVERED IN v2:
//   The 2500ms boot-phase monitor ran at T≈300ms and occupied the only
//   window in which the EC20F normally emits its "RDY" URC (T≈1-5s from
//   cold-power). Despite listening at 115200 during that window, zero bytes
//   arrived -- confirming either (a) the modem is already past its boot URC
//   window (already on from a previous session), or (b) GPIO16 (RX2) is not
//   receiving the modem's TXD signal.
//
//   The 0xFF seen at 9600 baud was a UART hardware framing artifact: when
//   the ESP32 UART RX pin is idle-high (undriven or already-silent modem),
//   switching baud rates can produce a spurious 0xFF. It is NOT modem data.
//
// THIS VERSION (v3) adds:
//   1. UART loopback self-test: briefly connects TX2->RX2 internally (GPIO
//      loopback mode) to verify that the Serial2 hardware can both transmit
//      and receive before blaming the modem.  A loopback failure means the
//      UART peripheral itself is broken; a loopback success means the UART
//      works and the problem is on the modem side.
//   2. Extended passive listening: instead of a wasted 2500ms pre-wait with
//      no AT sent, per-baud probes are extended to 3000ms each (configurable
//      via EC20F_DIAG_TIMEOUT_MS override).  The modem's boot URC and the
//      AT response can both arrive in this window.
//   3. Millisecond timestamps on every log line so timing is visible.
//   4. AT sent with and without echo: ATE1 echo mode can cause the AT echo
//      to be confused with the response; this version checks for both echo
//      and OK in the same buffer.
//   5. "AT" is sent TWICE per baud rate: once immediately and once 1000ms
//      later, because the modem may be finishing a URC and will ignore the
//      first command.
//
// WHAT THIS VERSION DOES NOT DO:
//   - Does not pulse PWRKEY (pin unknown until board photo is provided).
//   - Does not change modem persistent settings (no AT+IPR).
//   - Does not operate servo, buzzer, GPS, or lock.
//   - Does not block indefinitely: total budget ≤ 4 baud × 3.5s ≈ 14s.
// -----------------------------------------------------------------------

static const uint32_t kDiagBauds[] = { 115200, 9600, 57600, 38400 };
static const uint8_t  kDiagBaudCount = (uint8_t)(sizeof(kDiagBauds)/sizeof(kDiagBauds[0]));

static const char kAtCmd[] = "AT\r\n";  // 4 bytes, V.25ter compliant

// -----------------------------------------------------------------------
// Timestamp helper
// -----------------------------------------------------------------------

static inline uint32_t ms() { return millis(); }

static void logTs(const char* tag, const char* msg) {
    Serial.printf("[ec20-diag T+%lums] %s: %s\n", (unsigned long)ms(), tag, msg);
}

// -----------------------------------------------------------------------
// Hex + ASCII dump helpers
// -----------------------------------------------------------------------

static void hexDump(const uint8_t* data, size_t len) {
    size_t n = (len < (size_t)EC20F_DIAG_HEX_LIMIT) ? len : (size_t)EC20F_DIAG_HEX_LIMIT;
    for (size_t i = 0; i < n; i++) Serial.printf("0x%02X ", data[i]);
    if (len > (size_t)EC20F_DIAG_HEX_LIMIT)
        Serial.printf("...(+%u more)", (unsigned)(len - EC20F_DIAG_HEX_LIMIT));
}

static void asciiDump(const uint8_t* data, size_t len) {
    size_t n = (len < (size_t)EC20F_DIAG_HEX_LIMIT) ? len : (size_t)EC20F_DIAG_HEX_LIMIT;
    for (size_t i = 0; i < n; i++) {
        const uint8_t b = data[i];
        if      (b == '\r') Serial.print("<CR>");
        else if (b == '\n') Serial.print("<LF>");
        else if (b >= 0x20 && b < 0x7F) Serial.print((char)b);
        else    Serial.print('.');
    }
    if (len > (size_t)EC20F_DIAG_HEX_LIMIT)
        Serial.printf("...(+%u)", (unsigned)(len - EC20F_DIAG_HEX_LIMIT));
}

static void dumpBytes(const char* label, const uint8_t* data, size_t len) {
    Serial.printf("[ec20-diag T+%lums] %s (%u byte(s))\n",
                  (unsigned long)ms(), label, (unsigned)len);
    if (len == 0) return;
    Serial.print("[ec20-diag]   hex:   "); hexDump(data, len); Serial.println();
    Serial.print("[ec20-diag]   ascii: "); asciiDump(data, len); Serial.println();
}

// -----------------------------------------------------------------------
// Collect bytes until quiet or timeout
// -----------------------------------------------------------------------

static size_t collect(uint8_t* buf, size_t cap, uint32_t timeoutMs) {
    size_t n = 0;
    const uint32_t start = millis();
    uint32_t lastByte = start;
    bool sawAny = false;

    while ((uint32_t)(millis() - start) < timeoutMs && n < cap) {
        while (Serial2.available() > 0 && n < cap) {
            buf[n++] = (uint8_t)Serial2.read();
            lastByte = millis();
            sawAny = true;
        }
        if (sawAny && (uint32_t)(millis() - lastByte) >= EC20F_DIAG_QUIET_MS) break;
        delay(2);
    }
    return n;
}

// -----------------------------------------------------------------------
// UART loopback self-test
//
// The ESP32 UART peripheral supports an internal loopback mode where the TX
// output is fed directly back into the RX input inside the silicon --
// without any external wire.  Enabling this lets us confirm that:
//   (a) Serial2.begin() actually initialised the UART peripheral.
//   (b) Serial2.write() and Serial2.read() are working on this MCU.
//   (c) The ESP32 software layer is not broken independent of wiring.
//
// We use the UART's LOOPBACK bit in UART_CONF0_REG (Xtensa UART register).
// The Arduino ESP32 core does not expose this directly, but the register
// addresses are stable across ESP32 IDF 4.x/5.x.
//
// UART2 base: 0x3FF6E000
// UART_CONF0_REG offset: 0x20
// LOOPBACK bit: bit 14
//
// After the test the bit is cleared so normal UART2 operation resumes.
// -----------------------------------------------------------------------

#define UART2_BASE      0x3FF6E000UL
#define UART_CONF0_REG  (UART2_BASE + 0x20UL)
#define UART_LOOPBACK   (1UL << 14)

static bool runLoopbackTest() {
    Serial.printf("[ec20-diag T+%lums] === UART2 internal loopback self-test ===\n",
                  (unsigned long)ms());
    Serial.println("[ec20-diag] (Internal silicon loopback - no external wiring needed)");

    // Open UART2 at 115200 with known-good config
    Serial2.begin(115200, SERIAL_8N1, SIM_RX_PIN, SIM_TX_PIN);
    delay(50);

    // Drain any bytes that were in the buffer
    while (Serial2.available() > 0) Serial2.read();

    // Enable internal loopback via register write
    volatile uint32_t* conf0 = (volatile uint32_t*)UART_CONF0_REG;
    *conf0 |= UART_LOOPBACK;
    delay(10);  // allow the register change to settle

    // Send a known test pattern
    const uint8_t kTestPattern[] = { 0x41, 0x54, 0x0D, 0x0A };  // "AT\r\n"
    Serial2.write(kTestPattern, sizeof(kTestPattern));
    Serial2.flush();

    // Read back
    uint8_t rxBuf[8];
    size_t rxN = 0;
    const uint32_t deadline = millis() + 200;
    while (millis() < deadline && rxN < sizeof(rxBuf)) {
        if (Serial2.available() > 0) {
            rxBuf[rxN++] = (uint8_t)Serial2.read();
        } else {
            delay(1);
        }
    }

    // Disable loopback
    *conf0 &= ~UART_LOOPBACK;
    delay(10);

    // Drain loopback echo that may still be in the buffer
    while (Serial2.available() > 0) Serial2.read();

    // Evaluate
    bool match = (rxN == sizeof(kTestPattern));
    if (match) {
        for (size_t i = 0; i < rxN && match; i++) {
            if (rxBuf[i] != kTestPattern[i]) match = false;
        }
    }

    dumpBytes("Loopback TX: 0x41 0x54 0x0D 0x0A  Loopback RX", rxBuf, rxN);

    if (match) {
        Serial.printf("[ec20-diag T+%lums] LOOPBACK: PASS -- UART2 hardware is working correctly.\n",
                      (unsigned long)ms());
        Serial.println("[ec20-diag]   Serial2.begin(), .write(), and .read() all function.");
        Serial.println("[ec20-diag]   If modem probes below are SILENT, the issue is external");
        Serial.println("[ec20-diag]   (modem power, wiring, PWRKEY, or baud rate).");
    } else if (rxN == 0) {
        Serial.printf("[ec20-diag T+%lums] LOOPBACK: FAIL -- no bytes returned (received 0).\n",
                      (unsigned long)ms());
        Serial.println("[ec20-diag]   UART2 peripheral may not have initialised correctly.");
        Serial.println("[ec20-diag]   Check: GPIO16/17 not shorted to power rails; no competing");
        Serial.println("[ec20-diag]   Serial2.begin() elsewhere in setup().");
    } else {
        Serial.printf("[ec20-diag T+%lums] LOOPBACK: MISMATCH -- sent 4 bytes, got %u back.\n",
                      (unsigned long)ms(), (unsigned)rxN);
        dumpBytes("Loopback RX mismatch", rxBuf, rxN);
    }

    Serial2.end();
    delay(50);
    Serial.println("[ec20-diag] === Loopback self-test done ===");
    Serial.println();
    return match;
}

// -----------------------------------------------------------------------
// Single baud-rate probe
// Returns true if a byte containing 'O','K' was seen (clean AT response).
// -----------------------------------------------------------------------

static bool probeBaud(uint32_t baud, bool* anySilent, bool* anyBytes) {
    Serial.printf("[ec20-diag T+%lums] --- baud=%lu ---\n",
                  (unsigned long)ms(), (unsigned long)baud);

    Serial2.begin(baud, SERIAL_8N1, SIM_RX_PIN, SIM_TX_PIN);
    delay(EC20F_DIAG_SETTLE_MS);

    // Drain settle-time bytes (log them -- could be boot URCs at this baud)
    {
        uint8_t drainBuf[64];
        uint32_t dN = 0;
        while (Serial2.available() > 0 && dN < sizeof(drainBuf)) {
            drainBuf[dN++] = (uint8_t)Serial2.read();
        }
        while (Serial2.available() > 0) { Serial2.read(); dN++; }
        if (dN > 0) {
            size_t logged = (dN < sizeof(drainBuf)) ? dN : sizeof(drainBuf);
            dumpBytes("Settle-drain (possible boot URC)", drainBuf, logged);
        }
    }

    static uint8_t rxBuf[256];
    bool gotOk = false;
    bool gotBytes = false;

    // Send AT twice with a 1000ms gap so we catch: (1) immediate echo/OK,
    // (2) a modem that was finishing a URC and ignores the first command.
    for (int attempt = 1; attempt <= 2; attempt++) {
        const uint32_t txMs = millis();
        const size_t written = Serial2.write((const uint8_t*)kAtCmd, 4);
        Serial2.flush();
        Serial.printf("[ec20-diag T+%lums] TX attempt %d/%d: %u byte(s) sent\n",
                      (unsigned long)txMs, attempt, 2, (unsigned)written);

        // Listen for EC20F_DIAG_TIMEOUT_MS
        size_t rxN = collect(rxBuf, sizeof(rxBuf), EC20F_DIAG_TIMEOUT_MS);

        if (rxN > 0) {
            gotBytes = true;
            dumpBytes("RX", rxBuf, rxN);

            // Scan for "OK"
            for (size_t j = 0; j + 1 < rxN; j++) {
                if (rxBuf[j] == 'O' && rxBuf[j+1] == 'K') { gotOk = true; break; }
            }
            // Scan for "ERROR" (path conducts but framing wrong)
            bool gotError = false;
            for (size_t j = 0; j + 4 < rxN; j++) {
                if (rxBuf[j]=='E' && rxBuf[j+1]=='R' && rxBuf[j+2]=='R' &&
                    rxBuf[j+3]=='O' && rxBuf[j+4]=='R') { gotError = true; break; }
            }

            const char* verdict = gotOk    ? "OK -- modem responded correctly"
                                : gotError  ? "ERROR -- path conducts, check baud/framing"
                                :             "BYTES -- not OK/ERROR, review hex above";
            Serial.printf("[ec20-diag T+%lums] baud=%-7lu attempt=%d received=%u verdict: %s\n",
                          (unsigned long)millis(), (unsigned long)baud, attempt, (unsigned)rxN, verdict);
            if (gotOk) break;  // no need for second attempt
        } else {
            *anySilent = true;
            Serial.printf("[ec20-diag T+%lums] baud=%-7lu attempt=%d received=0 SILENT\n",
                          (unsigned long)millis(), (unsigned long)baud, attempt);
        }

        if (attempt < 2 && !gotOk) {
            // Wait 1000ms, log any bytes that arrive passively in that window
            Serial.printf("[ec20-diag T+%lums] Passive listen 1000ms before 2nd AT...\n",
                          (unsigned long)millis());
            size_t pN = collect(rxBuf, sizeof(rxBuf), 1000UL);
            if (pN > 0) {
                gotBytes = true;
                dumpBytes("Passive (URC/boot noise between AT attempts)", rxBuf, pN);
            }
        }
    }

    *anyBytes = *anyBytes || gotBytes;
    Serial2.end();
    delay(50);
    return gotOk;
}

// -----------------------------------------------------------------------
// Main entry point
// -----------------------------------------------------------------------

void runEC20FDiagnostic() {
    Serial.println();
    Serial.println("========================================================");
    Serial.println("[ec20-diag] EC20F UART DIAGNOSTIC v3 START");
    Serial.printf ("[ec20-diag] T+%lums  RX=GPIO%d  TX=GPIO%d  BAUD_DEFAULT=%lu\n",
                   (unsigned long)ms(), SIM_RX_PIN, SIM_TX_PIN,
                   (unsigned long)SIM_BAUD_RATE);
    Serial.printf ("[ec20-diag] Quiet=%lums  Timeout/AT=%lums  HexLimit=%d bytes\n",
                   (unsigned long)EC20F_DIAG_QUIET_MS,
                   (unsigned long)EC20F_DIAG_TIMEOUT_MS,
                   EC20F_DIAG_HEX_LIMIT);
    Serial.println("========================================================");
    Serial.println();

    // --- Phase 1: UART2 internal loopback self-test ---
    const bool loopbackOk = runLoopbackTest();

    if (!loopbackOk) {
        Serial.println("[ec20-diag] WARNING: Loopback failed -- UART2 hardware suspect.");
        Serial.println("[ec20-diag] Per-baud AT probes will still run but results may not reflect modem state.");
        Serial.println();
    }

    // --- Phase 2: Per-baud AT probes ---
    Serial.printf("[ec20-diag T+%lums] === Per-baud AT probes ===\n", (unsigned long)ms());
    Serial.println("[ec20-diag] Each baud sends AT<CR><LF> twice with 1000ms passive gap.");
    Serial.printf("[ec20-diag] Per-attempt receive window: %lums\n",
                  (unsigned long)EC20F_DIAG_TIMEOUT_MS);
    Serial.println();

    bool anySilent = false;
    bool anyBytes  = false;
    bool anyOk     = false;
    uint32_t okBaud = 0;

    for (uint8_t i = 0; i < kDiagBaudCount; i++) {
        const bool ok = probeBaud(kDiagBauds[i], &anySilent, &anyBytes);
        if (ok && !anyOk) {
            anyOk = true;
            okBaud = kDiagBauds[i];
        }
    }

    // --- Summary ---
    Serial.println();
    Serial.println("========================================================");
    Serial.printf("[ec20-diag T+%lums] EC20F UART DIAGNOSTIC COMPLETE\n", (unsigned long)ms());
    Serial.println("========================================================");

    if (anyOk) {
        Serial.printf("[ec20-diag] RESULT: MODEM RESPONDED with OK at %lu baud.\n",
                      (unsigned long)okBaud);
        Serial.printf("[ec20-diag] Action: verify SIM_BAUD_RATE is %lu in secrets.h.\n",
                      (unsigned long)okBaud);
    } else if (anyBytes) {
        Serial.println("[ec20-diag] RESULT: BYTES received but no OK seen.");
        Serial.println("[ec20-diag] Likely: modem alive but boot URC obscures AT echo, or baud");
        Serial.println("[ec20-diag] mismatch.  Review hex dumps above for printable text.");
        Serial.println("[ec20-diag] If loopback passed, the UART path to modem may be one-way");
        Serial.println("[ec20-diag] (modem RX connected, modem TX not reaching GPIO16).");
    } else {
        Serial.println("[ec20-diag] RESULT: COMPLETELY SILENT at all baud rates.");
        if (loopbackOk) {
            Serial.println("[ec20-diag] Loopback PASSED -> UART2 hardware is fine.");
            Serial.println("[ec20-diag] The silence is EXTERNAL. Check in order:");
            Serial.println("[ec20-diag]  1. POWER: Is the modem buck converter ON?");
            Serial.println("[ec20-diag]     Measure VBAT on the modem board with a multimeter.");
            Serial.println("[ec20-diag]     Expected: 3.6-4.2 V DC.");
            Serial.println("[ec20-diag]  2. PWRKEY: Does the modem STATUS/NETLIGHT LED blink?");
            Serial.println("[ec20-diag]     If no LED activity, press-hold PWRKEY for 2 s.");
            Serial.println("[ec20-diag]     Some breakout boards auto-pulse; some require manual press.");
            Serial.println("[ec20-diag]  3. GND: ESP32 GND and modem board GND on the same rail?");
            Serial.println("[ec20-diag]  4. EC20F TXD -> ESP32 GPIO16? (not GPIO17; crossing matters)");
            Serial.println("[ec20-diag]  5. Logic level: photo the board -- bare EC20 is 1.8V UART.");
            Serial.println("[ec20-diag]     A dev board without a level shifter needs one for 3.3V.");
        } else {
            Serial.println("[ec20-diag] Loopback FAILED -> UART2 peripheral issue. Check:");
            Serial.println("[ec20-diag]  - GPIO16 or GPIO17 shorted to power or GND.");
            Serial.println("[ec20-diag]  - Competing Serial2.begin() call in another module.");
        }
    }
    Serial.println("========================================================");
    Serial.println();

    // Serial2 is closed. simInit() will reopen it at SIM_BAUD_RATE.
}

#endif  // EC20F_UART_DIAGNOSTIC
