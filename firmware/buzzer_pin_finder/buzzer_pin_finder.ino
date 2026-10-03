/**
 * Buzzer pin finder.
 *
 * GPIO19 is what the code and README assumed, and the bench test drove it with no
 * result, so the assumption needs checking against the actual hardware rather than
 * another round of guessing.
 *
 * How to use this: stand at the board with the Serial Monitor open, and COUNT THE
 * BEEPS. Each pin gets a signature of N beeps where N is its position in the table
 * below, so you can tell me "I heard 2 beeps" instead of trying to remember which
 * block of a sweep it was.
 *
 * Every pin is tried twice:
 *   1. PWM  2 kHz at 50% - the right drive for a passive piezo.
 *   2. DC   steady HIGH - the right drive for an active buzzer module.
 * A buzzer that only answers to one of the two tells us its type, which decides
 * whether BUZZER_IS_PASSIVE in config.h is correct.
 *
 * Wiring: leave the servos and the LCD connected if you like, the pins below
 * avoid them. Nothing is left driven when the sketch ends.
 */

/**
 * Candidates, in the order they are tried. The count signature is the 1-based
 * position in this list, so the order is part of the interface: if you change it,
 * change the table in the serial output too.
 */
static const uint8_t PINS[] = {19, 26, 25, 27, 13, 14, 16, 17};
static const uint8_t PIN_COUNT = sizeof(PINS) / sizeof(PINS[0]);

static const uint32_t PWM_HZ = 2000;
static const uint8_t PWM_BITS = 8;
static const uint32_t PWM_TONE_MS = 150;
static const uint32_t PWM_GAP_MS = 250;
static const uint32_t DC_TONE_MS = 300;
static const uint32_t DC_GAP_MS = 500;
static const uint32_t BLOCK_GAP_MS = 1200;
static const uint8_t CYCLES = 2;

static void showTable() {
    Serial.println();
    Serial.println("  COUNT THE BEEPS - the count tells you the pin:");
    Serial.println();
    for (uint8_t i = 0; i < PIN_COUNT; i++) {
        Serial.printf("    %u beep(s) = GPIO%u\n", (unsigned)(i + 1), (unsigned)PINS[i]);
    }
    Serial.println();
}

static void driveLow(uint8_t pin) {
    pinMode(pin, OUTPUT);
    digitalWrite(pin, LOW);
}

/** Emit `count` beeps of the requested length, so the count identifies the pin. */
static void beepSignature(uint8_t pin, uint8_t count, uint32_t toneMs, uint32_t gapMs) {
    for (uint8_t b = 0; b < count; b++) {
#if defined(ESP_ARDUINO_VERSION_MAJOR) && ESP_ARDUINO_VERSION_MAJOR >= 3
        ledcWriteTone(pin, PWM_HZ);
#else
        ledcAttachPin(pin, 0);
        ledcWriteTone(0, PWM_HZ);
#endif
        delay(toneMs);
#if defined(ESP_ARDUINO_VERSION_MAJOR) && ESP_ARDUINO_VERSION_MAJOR >= 3
        ledcWriteTone(pin, 0);
#else
        ledcWriteTone(0, 0);
#endif
        delay(gapMs);
    }
    delay(gapMs);
}

void setup() {
    Serial.begin(115200);
    delay(400);
    while (!Serial && millis() < 3000) delay(20);

#if defined(ESP_ARDUINO_VERSION_MAJOR) && ESP_ARDUINO_VERSION_MAJOR >= 3
    ledcAttach(19, PWM_HZ, PWM_BITS);
    ledcAttach(26, PWM_HZ, PWM_BITS);
    ledcAttach(25, PWM_HZ, PWM_BITS);
    ledcAttach(27, PWM_HZ, PWM_BITS);
    ledcAttach(13, PWM_HZ, PWM_BITS);
    ledcAttach(14, PWM_HZ, PWM_BITS);
    ledcAttach(16, PWM_HZ, PWM_BITS);
    ledcAttach(17, PWM_HZ, PWM_BITS);
#endif

    for (uint8_t i = 0; i < PIN_COUNT; i++) driveLow(PINS[i]);

    Serial.println();
    Serial.println("================================================");
    Serial.println("  BUZZER PIN FINDER");
    Serial.println("================================================");
    showTable();
    Serial.println("  4 seconds of silence first, so you can confirm");
    Serial.println("  the board is quiet before the sweep starts.");
    delay(4000);

    for (uint8_t cycle = 0; cycle < CYCLES; cycle++) {
        Serial.printf("  --- cycle %u of %u ---\n", (unsigned)(cycle + 1), (unsigned)CYCLES);

        for (uint8_t i = 0; i < PIN_COUNT; i++) {
            uint8_t pin = PINS[i];
            uint8_t count = (uint8_t)(i + 1);

            Serial.printf("  GPIO%u  (%u beeps)  PWM %u Hz\n",
                          (unsigned)pin, (unsigned)count, (unsigned)PWM_HZ);
#if defined(ESP_ARDUINO_VERSION_MAJOR) && ESP_ARDUINO_VERSION_MAJOR >= 3
            beepSignature(pin, count, PWM_TONE_MS, PWM_GAP_MS);

            Serial.printf("  GPIO%u  (%u beeps)  DC HIGH\n",
                          (unsigned)pin, (unsigned)count);
            beepSignature(pin, count, DC_TONE_MS, DC_GAP_MS);
#else
            for (uint8_t b = 0; b < count; b++) {
                digitalWrite(pin, HIGH);
                delay(DC_TONE_MS);
                digitalWrite(pin, LOW);
                delay(DC_GAP_MS);
            }
#endif
            delay(BLOCK_GAP_MS);
        }
    }

    for (uint8_t i = 0; i < PIN_COUNT; i++) driveLow(PINS[i]);

    Serial.println();
    Serial.println("================================================");
    Serial.println("  SWEEP DONE");
    Serial.println("================================================");
    Serial.println("  Tell me the beep count you heard, and whether it");
    Serial.println("  answered to PWM only, DC only, or both.");
    Serial.println("  If nothing sounded at all, the buzzer is not on any");
    Serial.println("  of these pins, or it is not connected.");
}

void loop() {
    for (uint8_t i = 0; i < PIN_COUNT; i++) driveLow(PINS[i]);
    delay(1000);
}
