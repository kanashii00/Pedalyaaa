/**
 * GPS bring-up diagnostic.
 *
 * The firmware has no GPS support yet, so before any of it is written the wiring
 * and the module's baud rate have to be established rather than assumed. A GPS
 * module streams NMEA unprompted the moment it is powered, so both answers can be
 * found empirically instead of by guesswork:
 *
 *   - Which pin order carries data. GPIO32 receiving is the current belief, but it
 *     is the reverse of the usual convention, which is exactly how it ends up
 *     "fixed" in the wrong direction by someone who assumes the conventional one.
 *     The other order is tried too, so a negative result is proven rather than
 *     merely unfound.
 *
 *   - What baud rate the module is actually on. Factory default is 9600, but a
 *     module that has been configured before will not be.
 *
 * Wiring as currently connected: module TX to GPIO32, module RX to GPIO33.
 * Cross the two wires if you would rather the conventional order hold; the
 * verdict below says which one is carrying data.
 *
 * No NMEA parsing assumptions are made: any line beginning with '$' counts as
 * traffic, so this works for GGA-only, RMC-only, or combined receivers.
 */

#include <Arduino.h>

static const uint8_t PIN_A_RX = 32;  // current belief: module TX lands here
static const uint8_t PIN_A_TX = 33;
static const uint8_t PIN_B_RX = 33;  // the opposite assignment
static const uint8_t PIN_B_TX = 32;

static const uint32_t BAUDS[] = {9600, 4800, 38400, 57600, 115200};
static const uint8_t BAUD_COUNT = sizeof(BAUDS) / sizeof(BAUDS[0]);

static const uint32_t LISTEN_MS = 4000;   // per pin/baud attempt
static const char    TAG[] = "$";

static uint32_t g_sentences = 0;
static char     g_line[128];

struct Fix {
    bool     valid;
    uint8_t  quality;    // GGA fix quality, 0 = none
    uint8_t  satellites;
    double   latitude;
    double   longitude;
    double   altitude;
    bool     haveRmc;
    char     utc[8];
};

static Fix g_fix;

/**
 * Parse one NMEA sentence.
 *
 * Only GGA and RMC are decoded, and only because they are the two every module in
 * this class emits. Everything else is counted and ignored, because the point of
 * this sketch is to prove that bytes are arriving and that a fix exists, not to
 * be the parser that ships.
 */
static void parseSentence(const char* line) {
    if (strncmp(line, TAG, 1) != 0) return;

    if (strstr(line, "GGA") != nullptr) {
        double lat = 0, lon = 0, alt = 0;
        int quality = 0, sats = 0;
        char ns = 0, ew = 0;

        int fields = sscanf(line, "$*GGA,%*[^,],%lf,%c,%lf,%c,%d,%d,%*[^,],%lf",
                            &lat, &ns, &lon, &ew, &quality, &sats, &alt);
        if (fields == 7) {
            g_fix.valid = true;
            g_fix.quality = (uint8_t)quality;
            g_fix.satellites = (uint8_t)sats;
            g_fix.latitude = (ns == 'S') ? -lat : lat;
            g_fix.longitude = (ew == 'W') ? -lon : lon;
            g_fix.altitude = alt;
        }
        return;
    }

    if (strstr(line, "RMC") != nullptr) {
        char status = 0, ns = 0, ew = 0, utcf[16] = "";
        double lat = 0, lon = 0;

        int fields = sscanf(line, "$*RMC,%15[^,],%c,%lf,%c,%lf,%c",
                            utcf, &status, &lat, &ns, &lon, &ew);
        if (fields == 6 && status == 'A') {
            g_fix.valid = true;
            g_fix.haveRmc = true;
            g_fix.latitude = (ns == 'S') ? -lat : lat;
            g_fix.longitude = (ew == 'W') ? -lon : lon;
            strncpy(g_fix.utc, utcf, sizeof(g_fix.utc) - 1);
        }
    }
}

static void showFix() {
    if (!g_fix.valid) {
        Serial.println("      no usable fix in the sentences seen");
        return;
    }

    Serial.printf("      FIX  quality=%u  satellites=%u  %.6f, %.6f  alt %.1f m",
                  (unsigned)g_fix.quality, (unsigned)g_fix.satellites,
                  g_fix.latitude, g_fix.longitude, g_fix.altitude);
    if (g_fix.haveRmc) Serial.printf("  UTC %s", g_fix.utc);
    Serial.println();

    if (g_fix.quality == 0 && !g_fix.haveRmc) {
        Serial.println("      -> module is transmitting but has NOT locked yet.");
        Serial.println("         It needs an open sky; indoors or in a metal cabinet");
        Serial.println("         it will stream valid-format sentences forever with");
        Serial.println("         quality 0 and no position. That is normal, not a fault.");
    }
}

/** Listen on one pin/baud combination and report what turned up. */
static void tryConfig(const char* label, uint8_t rx, uint8_t tx, uint32_t baud) {
    Serial2.end();
    delay(60);
    Serial2.begin(baud, SERIAL_8N1, rx, tx);
    delay(120);

    g_sentences = 0;
    g_fix = Fix{};

    const uint32_t start = millis();
    while ((millis() - start) < LISTEN_MS) {
        while (Serial2.available()) {
            char c = (char)Serial2.read();
            if (c == '\n' || c == '\r') {
                // Terminate only after the sentence has been consumed, otherwise
                // the line is wiped before there is anything left to parse.
                if (strlen(g_line) > 2) {
                    g_sentences++;
                    if (g_sentences <= 2) {
                        Serial.printf("      heard: %.90s\n", g_line);
                    }
                    parseSentence(g_line);
                }
                g_line[0] = '\0';
                continue;
            }
            size_t at = strlen(g_line);
            if (at < sizeof(g_line) - 1) g_line[at] = c;
        }
        delay(5);
    }

    Serial2.flush();
    Serial.printf("  %-22s %6lu  baud : %lu sentence(s)\n", label,
                  (unsigned long)g_sentences, (unsigned long)baud);
    showFix();

    Serial2.end();
    delay(40);
}

void setup() {
    Serial.begin(115200);
    delay(400);
    while (!Serial && millis() < 3000) delay(20);

    Serial.println();
    Serial.println("================================================");
    Serial.println("  GPS BRING-UP");
    Serial.println("================================================");
    Serial.println("  Believed wiring: module TX -> GPIO32,");
    Serial.println("                    module RX -> GPIO33");
    Serial.println("  Both orders and several baud rates are tried so");
    Serial.println("  a negative result is proven, not just unfound.");
    Serial.println("  Each attempt listens for 4 seconds.");

    bool foundOnA = false;
    bool foundOnB = false;

    Serial.println();
    Serial.println("  --- order A: receiving on GPIO32 ---");
    for (uint8_t i = 0; i < BAUD_COUNT; i++) {
        tryConfig("A  RX=32 TX=33", PIN_A_RX, PIN_A_TX, BAUDS[i]);
        if (g_sentences > 0) foundOnA = true;
    }

    Serial.println();
    Serial.println("  --- order B: receiving on GPIO33 ---");
    for (uint8_t i = 0; i < BAUD_COUNT; i++) {
        tryConfig("B  RX=33 TX=32", PIN_B_RX, PIN_B_TX, BAUDS[i]);
        if (g_sentences > 0) foundOnB = true;
    }

    Serial.println();
    Serial.println("================================================");
    Serial.println("  VERDICT");
    Serial.println("================================================");
    if (foundOnA && !foundOnB) {
        Serial.println("  Data arrives on GPIO32. The wiring as connected");
        Serial.println("  is correct:");
        Serial.println("      Serial2.begin(baud, SERIAL_8N1, 32, 33);");
    } else if (foundOnB && !foundOnA) {
        Serial.println("  Data arrives on GPIO33, NOT GPIO32. The two wires");
        Serial.println("  are the wrong way round. Either swap them on the");
        Serial.println("  board, or use:");
        Serial.println("      Serial2.begin(baud, SERIAL_8N1, 33, 32);");
    } else if (foundOnA && foundOnB) {
        Serial.println("  Data arrived on BOTH orders. That is not expected");
        Serial.println("  for a single module - check for a loose wire or a");
        Serial.println("  second GPS fitted, and read which baud worked.");
    } else {
        Serial.println("  Nothing on either order at any baud tried.");
        Serial.println("  The module is either not powered, not receiving");
        Serial.println("  power, or the common ground is missing. This is a");
        Serial.println("  wiring or power fault, not a firmware one.");
    }
    Serial.println("================================================");
}

void loop() {
    delay(1000);
}
