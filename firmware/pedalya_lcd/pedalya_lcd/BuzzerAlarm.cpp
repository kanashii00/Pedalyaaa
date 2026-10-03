#include "BuzzerAlarm.h"

#include "config.h"

#if defined(__has_include)
#if __has_include(<esp_arduino_version.h>)
#include <esp_arduino_version.h>
#endif
#endif

void BuzzerAlarm::begin() {
    if (BUZZER_PIN < 0) return;

    // Passive buzzer on LEDC. Writing a frequency of 0 leaves the channel
    // silent, so the buzzer starts OFF and stays OFF until a state asks for it.
#if BUZZER_IS_PASSIVE
#if defined(ESP_ARDUINO_VERSION_MAJOR) && ESP_ARDUINO_VERSION_MAJOR >= 3
    ledcAttach(BUZZER_PIN, BUZZER_FREQUENCY_HZ, 8);
    ledcWriteTone(BUZZER_PIN, 0);
#else
    ledcSetup(BUZZER_LEDC_CHANNEL, BUZZER_FREQUENCY_HZ, 8);
    ledcAttachPin(BUZZER_PIN, BUZZER_LEDC_CHANNEL);
    ledcWriteTone(BUZZER_LEDC_CHANNEL, 0);
#endif
    Serial.printf("[buzzer] GPIO%d passive, %d Hz, initialised silent\n",
                  BUZZER_PIN, BUZZER_FREQUENCY_HZ);
#else
    pinMode(BUZZER_PIN, OUTPUT);
    digitalWrite(BUZZER_PIN, BUZZER_ACTIVE_HIGH ? LOW : HIGH);
    Serial.printf("[buzzer] GPIO%d active, initialised silent\n", BUZZER_PIN);
#endif
}

/**
 * Every call performs a real write, setOn(false) included, so the buzzer is
 * physically forced off each time instead of trusting a cached flag that could
 * drift out of step with the hardware. Nothing else in the sketch drives the
 * pin. On a passive piezo, frequency 0 is the LEDC output-disabled state, so
 * the channel goes fully silent rather than emitting a DC level.
 */
void BuzzerAlarm::setOn(bool on) {
    if (BUZZER_PIN < 0) return;
#if BUZZER_IS_PASSIVE
    // A passive piezo has no oscillator. LEDC supplies the audible square
    // wave; a digital HIGH would only hold its diaphragm still.
#if defined(ESP_ARDUINO_VERSION_MAJOR) && ESP_ARDUINO_VERSION_MAJOR >= 3
    ledcWriteTone(BUZZER_PIN, on ? BUZZER_FREQUENCY_HZ : 0);
#else
    ledcWriteTone(BUZZER_LEDC_CHANNEL, on ? BUZZER_FREQUENCY_HZ : 0);
#endif
#else
    digitalWrite(BUZZER_PIN, (on == BUZZER_ACTIVE_HIGH) ? HIGH : LOW);
#endif
    on_ = on;
}

void BuzzerAlarm::drive(bool expired, uint32_t graceSecondsLeft, bool serverReachable,
                        uint32_t sinceExpiryMs) {
    if (BUZZER_PIN < 0) {
        patternRunning_ = false;
        return;
    }

    // At the instant of expiry the timer has just started, so this is true and
    // the flatline gets its full burst before any beep.
    const bool flatlineBurst = sinceExpiryMs < EXPIRED_FLATLINE_MS;

    patternRunning_ = expired && RETURN_COUNTDOWN_ENABLED && serverReachable &&
                      graceSecondsLeft > 0 && !flatlineBurst;

    if (!expired) {
        countdown_.reset();
        setOn(false);
        return;
    }

    if (patternRunning_) {
        setOn(countdown_.active(millis(), graceSecondsLeft));
        return;
    }

    countdown_.reset();
    setOn(true);
}

void BuzzerAlarm::silence() {
    countdown_.reset();
    patternRunning_ = false;
    setOn(false);
}