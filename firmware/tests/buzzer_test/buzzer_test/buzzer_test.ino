#include <Arduino.h>

#if defined(__has_include)
#if __has_include(<esp_arduino_version.h>)
#include <esp_arduino_version.h>
#endif
#endif

/*
 * Buzzer only. Flash this to hear the two alarm shapes the firmware uses, at the
 * same timings, without a server or a rental.
 *
 *   1. a flat unbroken tone for EXPIRED_FLATLINE_MS: "your time is up"
 *   2. beeps whose gaps shrink as the free return window runs out
 *   3. the flat tone again, held
 *
 * GPIO19 drives a transistor/MOSFET driver, never a high-current buzzer
 * directly. Set BUZZER_IS_PASSIVE to 1 for a piezo that needs a PWM tone.
 */

#define BUZZER_PIN 19
#define BUZZER_IS_PASSIVE 0
#define BUZZER_ACTIVE_HIGH 1
#define BUZZER_FREQUENCY_HZ 2000
#define BUZZER_LEDC_CHANNEL 6

// Same values as config.h, so what you hear here is what a rider hears.
#define FLATLINE_MS 4000UL
#define BEEP_MS 200UL
#define GAP_START_MS 1800UL
#define GAP_END_MS 250UL
#define GRACE_SECONDS 300
#define RESTART_MS 8000UL

static bool on = false;

void setOn(bool value) {
#if BUZZER_IS_PASSIVE
#if defined(ESP_ARDUINO_VERSION_MAJOR) && ESP_ARDUINO_VERSION_MAJOR >= 3
    ledcWriteTone(BUZZER_PIN, value ? BUZZER_FREQUENCY_HZ : 0);
#else
    ledcWriteTone(BUZZER_LEDC_CHANNEL, value ? BUZZER_FREQUENCY_HZ : 0);
#endif
#else
    digitalWrite(BUZZER_PIN, (value == BUZZER_ACTIVE_HIGH) ? HIGH : LOW);
#endif
    on = value;
}

/** Silence before a beep, with the full window left and with none of it left. */
uint32_t gapMs(uint32_t graceSecondsLeft) {
    const uint32_t fullGrace = GRACE_SECONDS > 0 ? GRACE_SECONDS : 1;
    if (graceSecondsLeft <= 1 || fullGrace <= 1) return GAP_END_MS;
    if (graceSecondsLeft >= fullGrace) return GAP_START_MS;

    const uint32_t span = GAP_START_MS - GAP_END_MS;
    return GAP_END_MS + (span * (graceSecondsLeft - 1)) / (fullGrace - 1);
}

/** One unbroken tone. */
void flatline(uint32_t durationMs) {
    Serial.println("[buzzer] flatline");
    setOn(true);
    delay(durationMs);
    setOn(false);
}

/** The free return window, sampled every 15 s so the whole run is a few minutes. */
void returnCountdown() {
    const uint32_t sampleStepSeconds = 15;

    Serial.println("[buzzer] return countdown");
    for (uint32_t graceLeft = GRACE_SECONDS; graceLeft > 0; graceLeft -= sampleStepSeconds) {
        const uint32_t silence = gapMs(graceLeft);
        Serial.printf("[buzzer]   %3lu s left, beep %lu ms after %4lu ms\n",
                      (unsigned long)graceLeft, (unsigned long)BEEP_MS, (unsigned long)silence);

        setOn(true);
        delay(BEEP_MS);
        setOn(false);
        delay(silence);
    }
}

void setup() {
    Serial.begin(115200);
    delay(200);

#if BUZZER_IS_PASSIVE
#if defined(ESP_ARDUINO_VERSION_MAJOR) && ESP_ARDUINO_VERSION_MAJOR >= 3
    ledcAttach(BUZZER_PIN, BUZZER_FREQUENCY_HZ, 8);
    ledcWriteTone(BUZZER_PIN, 0);
#else
    ledcSetup(BUZZER_LEDC_CHANNEL, BUZZER_FREQUENCY_HZ, 8);
    ledcAttachPin(BUZZER_PIN, BUZZER_LEDC_CHANNEL);
    ledcWriteTone(BUZZER_LEDC_CHANNEL, 0);
#endif
    Serial.printf("[buzzer] GPIO%d passive piezo at %d Hz\n", BUZZER_PIN, BUZZER_FREQUENCY_HZ);
#else
    pinMode(BUZZER_PIN, OUTPUT);
    digitalWrite(BUZZER_PIN, BUZZER_ACTIVE_HIGH ? LOW : HIGH);
    Serial.printf("[buzzer] GPIO%d active buzzer\n", BUZZER_PIN);
#endif

    Serial.println("[buzzer] starting; nothing is driven until this finishes");
}

void loop() {
    flatline(FLATLINE_MS);
    returnCountdown();
    flatline(FLATLINE_MS * 2);

    Serial.println("[buzzer] done, resting");
    setOn(false);
    delay(RESTART_MS);
}