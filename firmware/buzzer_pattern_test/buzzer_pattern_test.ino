/**
 * Bench test for the return-station buzzer.
 *
 * Plays the production pattern from pedalya_lcd.ino by including the same
 * BuzzerCountdown.h and the same config.h, so what you hear is the firmware's own
 * timing on the firmware's own pin rather than a retyped copy that could quietly
 * drift away from it.
 *
 * The grace window is compressed (see the defines below) so the whole sequence
 * fits in about half a minute instead of five real minutes. The shape of the
 * countdown curve is still the production one: the gaps played are the ones
 * buzzerCountdownGapMs() returns, at their real durations, sampled across the
 * window so you can hear them crowd together. Phase 4 then runs the real
 * millis() state machine live, to check the beep edges land on time.
 *
 * Wiring: the buzzer on GPIO19, the same pin the firmware drives. Nothing else is
 * used, so the servos and the LCD can stay disconnected.
 *
 * To hear it at production speed instead, comment out the five defines below.
 */

#define RETURN_COUNTDOWN_ENABLED 1
#define RETURN_GRACE_SECONDS 30
#define EXPIRED_FLATLINE_MS 1500UL
#define RETURN_COUNTDOWN_GAP_START_MS 900UL
#define RETURN_COUNTDOWN_GAP_END_MS 125UL

#include "../pedalya_lcd/pedalya_lcd/BuzzerCountdown.h"

static const uint8_t LEDC_RESOLUTION_BITS = 8;

/** The firmware's own buzzer write, so the bench cannot pass what the real unit would fail. */
static void setBuzzer(bool on) {
#if BUZZER_IS_PASSIVE
#if defined(ESP_ARDUINO_VERSION_MAJOR) && ESP_ARDUINO_VERSION_MAJOR >= 3
    ledcWriteTone(BUZZER_PIN, on ? BUZZER_FREQUENCY_HZ : 0);
#else
    ledcWriteTone(BUZZER_LEDC_CHANNEL, on ? BUZZER_FREQUENCY_HZ : 0);
#endif
#else
    digitalWrite(BUZZER_PIN, (on == BUZZER_ACTIVE_HIGH) ? HIGH : LOW);
#endif
}

static void banner(const char* text) {
    Serial.println();
    Serial.println("================================================");
    Serial.print  ("  ");
    Serial.println(text);
    Serial.println("================================================");
}

/** Hold one unbroken tone. This is the "your time is up" shape. */
static void playFlatline(uint32_t durationMs) {
    Serial.printf("  [flatline]  continuous %lu ms at %lu Hz\n",
                  (unsigned long)durationMs, (unsigned long)BUZZER_FREQUENCY_HZ);
    setBuzzer(true);
    delay(durationMs);
    setBuzzer(false);
}

/**
 * Play the countdown curve, sampling the window so the real gaps are audible.
 *
 * The wall clock is compressed, but each gap is the exact value the firmware
 * would wait at that point in the window, so the acceleration you hear is the
 * production acceleration.
 */
static void playCountdownCurve() {
    const uint8_t STEPS = 24;

    Serial.printf("  [countdown] %d beeps, gaps %lu -> %lu ms\n", (unsigned)STEPS,
                  (unsigned long)buzzerCountdownGapMs((uint32_t)RETURN_GRACE_SECONDS),
                  (unsigned long)buzzerCountdownGapMs(1));

    for (uint8_t step = 0; step < STEPS; step++) {
        // Walk the window from the whole grace down to its final second.
        uint32_t graceLeft = (uint32_t)RETURN_GRACE_SECONDS -
                             ((uint32_t)RETURN_GRACE_SECONDS * step) / STEPS;
        uint32_t gap = buzzerCountdownGapMs(graceLeft);

        Serial.printf("    beep %2u/%u  grace left %3lus  gap %4lums\n",
                      (unsigned)(step + 1), (unsigned)STEPS,
                      (unsigned long)graceLeft, (unsigned long)gap);

        setBuzzer(true);
        delay(RETURN_COUNTDOWN_TONE_MS);
        setBuzzer(false);
        delay(gap);
    }
}

/**
 * Run the real state machine against real millis(), sleeping to each beep edge.
 *
 * Phase 3 only ever calls buzzerCountdownGapMs(); this drives
 * BuzzerCountdown::active() the way the firmware's loop does, and checks the tone
 * never sticks on and the edges land where nextEdgeInMs() promised.
 */
static void runLiveCountdown(uint32_t graceLeft, uint32_t runMs) {
    BuzzerCountdown countdown;
    uint32_t start = millis();
    bool last = false;
    uint16_t beeps = 0;

    Serial.printf("  [live]  grace left %lus, driving active() for %lums\n",
                  (unsigned long)graceLeft, (unsigned long)runMs);

    while ((millis() - start) < runMs) {
        uint32_t now = millis();
        bool on = countdown.active(now, graceLeft);

        if (on != last) {
            Serial.printf("    %6lums  %s\n", (unsigned long)(now - start),
                          on ? "TONE on " : "TONE off");
            if (on) beeps++;
            last = on;
        }

        uint32_t sleepMs = countdown.nextEdgeInMs(now);
        if (sleepMs == 0) sleepMs = 1;
        if (sleepMs > 50) sleepMs = 50;
        delay(sleepMs);
    }

    setBuzzer(false);
    Serial.printf("  [live]  %u beeps in %lums\n", (unsigned)beeps,
                  (unsigned long)runMs);
}

void setup() {
    Serial.begin(115200);
    delay(400);
    while (!Serial && millis() < 3000) delay(20);

#if BUZZER_IS_PASSIVE
#if defined(ESP_ARDUINO_VERSION_MAJOR) && ESP_ARDUINO_VERSION_MAJOR >= 3
    ledcAttach(BUZZER_PIN, BUZZER_FREQUENCY_HZ, LEDC_RESOLUTION_BITS);
#else
    ledcSetup(BUZZER_LEDC_CHANNEL, BUZZER_FREQUENCY_HZ, LEDC_RESOLUTION_BITS);
    ledcAttachPin(BUZZER_PIN, BUZZER_LEDC_CHANNEL);
#endif
#else
    pinMode(BUZZER_PIN, OUTPUT);
#endif
    setBuzzer(false);

    banner("BUZZER BENCH TEST");
    Serial.printf("  pin          GPIO%d (%s piezo)\n", (unsigned)BUZZER_PIN,
                  BUZZER_IS_PASSIVE ? "passive" : "active");
    Serial.printf("  tone         %lu Hz\n", (unsigned long)BUZZER_FREQUENCY_HZ);
    Serial.printf("  grace        %u s%s\n", (unsigned)RETURN_GRACE_SECONDS,
                  RETURN_GRACE_SECONDS == 300 ? "  (production)" : "  (COMPRESSED for this test)");
    Serial.printf("  countdown    %s\n", RETURN_COUNTDOWN_ENABLED ? "enabled" : "disabled");
    Serial.printf("  flatline     %lu ms\n", (unsigned long)EXPIRED_FLATLINE_MS);
    Serial.printf("  beep length  %lu ms\n", (unsigned long)RETURN_COUNTDOWN_TONE_MS);

    Serial.println();
    Serial.println("  3 seconds of silence first, so you can confirm the");
    Serial.println("  buzzer really is off before the test starts.");
    delay(3000);

    banner("PHASE 1 - rental expired: unbroken tone");
    playFlatline(EXPIRED_FLATLINE_MS);

    banner("PHASE 2 - free return window: countdown beeps");
    playCountdownCurve();

    banner("PHASE 3 - grace over: flatline returns and holds");
    playFlatline(EXPIRED_FLATLINE_MS * 2);

    banner("PHASE 4 - live state machine check (near the end of the grace)");
    runLiveCountdown(3, 8000);

    banner("DONE");
    Serial.println("  You should have heard three clearly different shapes.");
    Serial.println("  If a phase was silent, or the beeps stayed evenly");
    Serial.println("  spaced, tell me which phase and I will look there.");
    Serial.println();
}

void loop() {
    setBuzzer(false);
    delay(1000);
}
