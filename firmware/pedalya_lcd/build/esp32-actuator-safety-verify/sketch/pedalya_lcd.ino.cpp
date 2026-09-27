#line 1 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
#include <Arduino.h>
#include <ESP32Servo.h>
#include <WiFi.h>
#include <string.h>

#if defined(__has_include)
#if __has_include(<esp_arduino_version.h>)
#include <esp_arduino_version.h>
#endif
#endif

#include "LcdDisplay.h"
#include "PedalyaApi.h"
#include "config.h"

static PedalyaApi api;
static LcdDisplay display;
static RentalState state;
static Servo lockServo;

static const uint32_t RETRY_SECONDS = 5;
static const size_t MAX_PENDING_COMMANDS = 5;

enum class ActuatorState : uint8_t { Unknown, Idle, Active, Warning, Expired };

static uint32_t lastPollMs = 0;
static uint32_t lastWifiRetry = 0;
static uint32_t lcdMessageUntilMs = 0;
static bool everSynced = false;
static bool wifiAnnounced = false;
static bool polledOnce = false;
static bool serverReachable = false;
static bool buzzerOn = false;
static bool servoLocked = false;
static bool commandLockOverride = false;
static bool overdueTimerActive = false;
static uint32_t overdueStartMs = 0;
static uint32_t overdueSeconds = 0;
static float overduePenalty = 0.0f;

// Once a rental has expired it stays expired for this device until the rental
// itself ends. Without this a later poll that still reports the rental as active
// would walk the actuators back to Warning/Active and unlock a locked wheel.
static bool expiryLatched = false;

// Only the "remaining" line is logged, and only when the value actually
// changes, so the countdown reads as one line per second instead of five.
static bool remainingLogged = false;
static int64_t lastLoggedRemaining = 0;

static String lastError;
static String lcdMessage;
static ActuatorState actuatorState = ActuatorState::Unknown;
// This is deliberately separate from actuatorState. actuatorState is a
// display/state-transition helper; safetyOutput records the last output we
// announced while the physical GPIO commands below are issued every pass.
static ActuatorState safetyOutput = ActuatorState::Unknown;

#line 59 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static uint32_t pollIntervalMs();
#line 61 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static const char * actuatorStateName(ActuatorState value);
#line 80 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static bool rentalIsExpired();
#line 93 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static void setBuzzer(bool on);
#line 118 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static void beep();
#line 127 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static void moveServo(int angle, bool locked, const char* message);
#line 145 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static void releaseServo();
#line 146 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static void lockServoAtConfiguredAngle();
#line 148 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static void resetOverdueTimer();
#line 155 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static void updateOverdueTimer();
#line 172 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static void startOverdueTimer();
#line 187 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static void enterActuatorState(ActuatorState next);
#line 231 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static void logRemaining(int64_t remaining);
#line 238 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static void applyRentalActuators();
#line 299 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static void announceSafetyOutput(ActuatorState next, const char* message);
#line 312 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static void enforceActuatorSafety();
#line 350 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static void maintainExpiredSafetyDuringNetworkFailure();
#line 362 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static void executeCommand(const DeviceCommand& command);
#line 422 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static void pollAndExecuteCommands();
#line 437 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static const char * keyFingerprint();
#line 450 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static void connectWifi();
#line 456 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
void setup();
#line 522 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
void loop();
#line 59 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\pedalya_lcd.ino"
static uint32_t pollIntervalMs() { return (uint32_t)state.pollAfterSeconds * 1000UL; }

static const char* actuatorStateName(ActuatorState value) {
    switch (value) {
        case ActuatorState::Idle:    return "Idle";
        case ActuatorState::Active:  return "Active";
        case ActuatorState::Warning: return "Warning";
        case ActuatorState::Expired: return "Expired";
        case ActuatorState::Unknown: break;
    }
    return "Unknown";
}

/**
 * Expiry, decided in exactly one place.
 *
 * state.expired() is the authority. expiryLatched only ever becomes true after
 * state.expired() has already returned true, so it can never turn a running
 * rental into an expired one. It only stops a later poll that still reports the
 * rental as active from walking an already-locked wheel back to Active.
 */
static bool rentalIsExpired() {
    return expiryLatched || state.expired();
}

/**
 * The one and only place GPIO19 / the LEDC channel is written.
 *
 * Every call performs a real write, setBuzzer(false) included, so the buzzer is
 * physically forced off each time instead of trusting a cached flag that could
 * drift out of step with the hardware. Nothing else in the sketch drives the
 * pin. On a passive piezo, frequency 0 is the LEDC output-disabled state, so
 * the channel goes fully silent rather than emitting a DC level.
 */
static void setBuzzer(bool on) {
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
    buzzerOn = on;
}

/**
 * The one and only path that can switch the buzzer ON.
 *
 * Refuses to sound unless the rental has genuinely expired, so 5, 4, 3, 2 and 1
 * seconds remain silent no matter who calls it. A refusal also forces the pin
 * off, so a premature call cannot leave a tone behind. Once the rental really
 * has expired this holds the buzzer continuously, with no beep timing and no
 * repeat interval to time out.
 */
static void beep() {
    if (BUZZER_PIN < 0) return;
    if (!rentalIsExpired()) {
        setBuzzer(false);
        return;
    }
    setBuzzer(true);
}

static void moveServo(int angle, bool locked, const char* message) {
    if (ACTUATOR_CALIBRATION_MODE) {
        Serial.println("[servo] calibration mode");
        return;
    }

    int safeAngle = constrain(angle, 0, 180);

    Serial.printf("[servo] COMMAND angle=%d locked=%d\n", safeAngle, locked ? 1 : 0);

    lockServo.write(safeAngle);
    delay(300);

    servoLocked = locked;

    Serial.printf("[servo] %s (%d degrees)\n", message, safeAngle);
}

static void releaseServo() { moveServo(SERVO_RELEASE_ANGLE, false, "RELEASED"); }
static void lockServoAtConfiguredAngle() { moveServo(SERVO_LOCK_ANGLE, true, "LOCKED"); }

static void resetOverdueTimer() {
    overdueTimerActive = false;
    overdueStartMs = 0;
    overdueSeconds = 0;
    overduePenalty = 0.0f;
}

static void updateOverdueTimer() {
    if (!overdueTimerActive) return;

    overdueSeconds = (millis() - overdueStartMs) / 1000UL;

    if (overdueSeconds <= RETURN_GRACE_SECONDS) {
        overduePenalty = 0.0f;
        return;
    }

    uint32_t penaltySeconds = overdueSeconds - RETURN_GRACE_SECONDS;
    uint32_t penaltyIntervals =
        (penaltySeconds + PENALTY_INTERVAL_SECONDS - 1) /
        PENALTY_INTERVAL_SECONDS;
    overduePenalty = penaltyIntervals * PENALTY_PER_INTERVAL;
}

static void startOverdueTimer() {
    if (overdueTimerActive) return;

    overdueTimerActive = true;
    overdueStartMs = millis();
    overdueSeconds = 0;
    overduePenalty = 0.0f;

    lockServoAtConfiguredAngle();

    Serial.println("[OVERDUE] Rental expired");
    Serial.println("[OVERDUE] Servo LOCKED");
    Serial.println("[OVERDUE] 5-minute grace period started");
}

static void enterActuatorState(ActuatorState next) {
    // Physical outputs are enforced independently in enforceActuatorSafety().
    // This guard is therefore only for transition logging and display state;
    // it can never suppress a required servo write.
    if (actuatorState == next) return;
    actuatorState = next;
    switch (next) {
        case ActuatorState::Idle:
            commandLockOverride = false;
            setBuzzer(false);
            releaseServo();
            Serial.println("[actuator] IDLE - bicycle available, rental inactive");
            break;
        case ActuatorState::Active:
            // More than the warning window left: silent, and the lock released
            // unless an operator explicitly locked it.
            setBuzzer(false);
            if (!commandLockOverride) releaseServo();
            Serial.println("[actuator] ACTIVE - rental running, buzzer off");
            break;
        case ActuatorState::Warning:
            // The final window. The wheel must stay usable and the rider is
            // warned on the LCD only: servo RELEASED, buzzer hard OFF. There is
            // no sound path here at all.
            setBuzzer(false);
            if (!commandLockOverride) releaseServo();
            Serial.printf("[actuator] WARNING - %d seconds or less, still silent\n",
                          EXPIRY_WARNING_SECONDS);
            break;
        case ActuatorState::Expired:
            // Terminal for this rental: lock and hold the alarm until the rental
            // itself ends and Idle takes over.
            commandLockOverride = false;
            lockServoAtConfiguredAngle();
            Serial.println("[actuator] EXPIRED - rental timed out");
            beep();
            break;
        case ActuatorState::Unknown:
            setBuzzer(false);
            Serial.println("[actuator] UNKNOWN - buzzer forced off");
            break;
    }
}

static void logRemaining(int64_t remaining) {
    if (remainingLogged && remaining == lastLoggedRemaining) return;
    remainingLogged = true;
    lastLoggedRemaining = remaining;
    Serial.printf("[timer] remaining=%lld sec\n", (long long)remaining);
}

static void applyRentalActuators() {
    // A failed poll never infers an actuator state: keep the last physical state
    // so a network blip cannot unlock a wheel or silence an expired alarm.
    if (!serverReachable || ACTUATOR_CALIBRATION_MODE) return;

    if (!state.hasRental || state.status == "returned" || state.status == "cancelled") {
        // The rental is over, which is the only thing that clears the latch.
        expiryLatched = false;
        remainingLogged = false;
        resetOverdueTimer();
        enterActuatorState(ActuatorState::Idle);
        return;
    }

    int64_t remaining = state.remainingSeconds();

    // Expiry is tested before anything else, and latched, so Expired always
    // wins over Active and Warning. state.expired() is the authority: it is
    // false for a rental that simply has no deadline yet.
    if (expiryLatched || state.expired()) {
        if (!expiryLatched) {
            expiryLatched = true;
            startOverdueTimer();
        }
        updateOverdueTimer();
        if (state.deadlineTs > 0) {
            logRemaining(remaining);
        }
        enterActuatorState(ActuatorState::Expired);
        return;
    }

    bool warning = state.status == "active" && EXPIRY_WARNING_SECONDS > 0 &&
                   remaining > 0 && remaining <= EXPIRY_WARNING_SECONDS;

    if (warning) {
        if (state.deadlineTs > 0) {
            logRemaining(remaining);
        }
        enterActuatorState(ActuatorState::Warning);
        return;
    }

    if (state.deadlineTs > 0) {
        logRemaining(remaining);
    }
    enterActuatorState(ActuatorState::Active);
}

/**
 * The expiry safety rule, re-asserted continuously.
 *
 * Expired  -> servo LOCKED, buzzer ON.
 * Not expired -> buzzer OFF.
 *
 * This is deliberately the only safety function and it is called from the main
 * loop after the poll, after the server commands, and once more after those
 * commands. Because it re-issues the real servo write and re-asserts the tone
 * on every pass, it is impossible for a queued command or a missed update to
 * leave a timed-out wheel unlocked or silent.
 */
static void announceSafetyOutput(ActuatorState next, const char* message) {
    if (safetyOutput == next) return;
    safetyOutput = next;
    Serial.println(message);
}

/**
 * Physically synchronize both actuators with the latest known rental state.
 *
 * No early return is permitted before the writes: software can claim an
 * actuator has already moved even when a loose servo horn, brownout, or a
 * previous command left the real hardware in a different position.
 */
static void enforceActuatorSafety() {
    if (ACTUATOR_CALIBRATION_MODE) {
        setBuzzer(false);
        return;
    }

    // A completed rental always wins over a previously latched expiry.
    if (!state.hasRental || state.status == "returned" || state.status == "cancelled") {
        lockServo.write(constrain(SERVO_RELEASE_ANGLE, 0, 180));
        servoLocked = false;
        setBuzzer(false);
        announceSafetyOutput(ActuatorState::Idle,
                             "[ACTUATOR] RETURNED/CANCELLED -> SERVO 0, BUZZER OFF");
        return;
    }

    // state.expired() is the authority. expiryLatched retains the lock across
    // a stale follow-up response, but cannot override returned/cancelled above.
    if (state.expired() || expiryLatched) {
        updateOverdueTimer();
        lockServo.write(constrain(SERVO_LOCK_ANGLE, 0, 180));
        servoLocked = true;
        setBuzzer(true);
        announceSafetyOutput(ActuatorState::Expired,
                             "[ACTUATOR] EXPIRED -> SERVO 90, BUZZER ON");
        return;
    }

    // Both a running rental and its final warning window remain rideable.
    lockServo.write(constrain(SERVO_RELEASE_ANGLE, 0, 180));
    servoLocked = false;
    setBuzzer(false);
    announceSafetyOutput(ActuatorState::Active,
                         "[ACTUATOR] ACTIVE -> SERVO 0, BUZZER OFF");
}

// During a failed poll preserve the last servo position. A locally-known
// expiry is still reasserted so a network outage cannot defeat a lock/alarm.
static void maintainExpiredSafetyDuringNetworkFailure() {
    if (ACTUATOR_CALIBRATION_MODE) {
        setBuzzer(false);
        return;
    }
    if (rentalIsExpired()) {
        lockServo.write(constrain(SERVO_LOCK_ANGLE, 0, 180));
        servoLocked = true;
        setBuzzer(true);
    }
}

static void executeCommand(const DeviceCommand& command) {
    bool executed = true;
    String result = "executed";
    if (command.command == "lock") {
        commandLockOverride = true;
        lockServoAtConfiguredAngle();
        Serial.println("[servo] manual lock command accepted");
    } else if (command.command == "unlock") {
        // Allowed only while the rental has not expired. A queued or mistimed
        // unlock must not release a wheel whose time is already up, so the
        // refusal immediately re-issues the lock rather than merely declining.
        if (rentalIsExpired()) {
            executed = false;
            result = "unlock blocked: rental expired";
            Serial.println("[command] unlock blocked: rental expired");
            lockServo.write(constrain(SERVO_LOCK_ANGLE, 0, 180));
            servoLocked = true;
            setBuzzer(true);
            return;
        } else {
            commandLockOverride = false;
            releaseServo();
            Serial.println("[servo] manual unlock command accepted");
        }
    } else if (command.command == "buzzer") {
        // A server command can never be the reason the buzzer sounds before the
        // timer reaches zero. It is refused outright unless the rental has
        // genuinely expired, and beep() re-checks the same condition.
        if (!rentalIsExpired()) {
            executed = false;
            result = "buzzer blocked: rental not expired";
            setBuzzer(false);
            Serial.printf("[buzzer] command BLOCKED - state %s, not expired\n",
                          actuatorStateName(actuatorState));
        } else {
            beep();
        }
    } else if (command.command == "lcd") {
        lcdMessage = command.message.length() ? command.message : "RENTAL WARNING";
        lcdMessageUntilMs = millis() + 3000UL;
        Serial.printf("[actuator] LCD command: %s\n", lcdMessage.c_str());
    } else if (command.command == "calibrate") {
        if (ACTUATOR_CALIBRATION_MODE && command.servoAngle >= 0 && command.servoAngle <= 180) {
            lockServo.write(command.servoAngle);
            Serial.printf("[servo] CALIBRATION (%d degrees)\n", command.servoAngle);
        } else {
            executed = false;
            result = "enable ACTUATOR_CALIBRATION_MODE first";
        }
    } else {
        executed = false;
        result = "unsupported by display firmware";
    }
    String error;
    if (!api.acknowledgeCommand(command.id, executed, result, error)) {
        Serial.printf("[api] command %lu acknowledgement failed: %s\n",
                      (unsigned long)command.id, error.c_str());
    }
}

static void pollAndExecuteCommands() {
    DeviceCommand commands[MAX_PENDING_COMMANDS];
    size_t count = 0;
    String error;
    if (!api.pollCommands(commands, MAX_PENDING_COMMANDS, count, error)) {
        Serial.printf("[api] command poll failed: %s\n", error.c_str());
        return;
    }
    for (size_t i = 0; i < count; ++i) {
        Serial.printf("[api] command %lu: %s\n", (unsigned long)commands[i].id,
                      commands[i].command.c_str());
        executeCommand(commands[i]);
    }
}

static const char* keyFingerprint() {
    static char buffer[32];
    size_t length = strlen(DEVICE_API_KEY);
    if (length == 0) {
        snprintf(buffer, sizeof(buffer), "MISSING - put it in secrets.h");
        return buffer;
    }
    size_t visible = length < 8 ? length : 8;
    snprintf(buffer, sizeof(buffer), "%.*s... (%u chars)", (int)visible, DEVICE_API_KEY,
             (unsigned)length);
    return buffer;
}

static void connectWifi() {
    api.begin();
    wifiAnnounced = false;
    lastWifiRetry = millis();
}

void setup() {
    Serial.begin(115200);
    delay(200);
    Serial.println("\n[boot] pedalya lcd display + actuators");
    if (STATUS_LED_PIN >= 0) {
        pinMode(STATUS_LED_PIN, OUTPUT);
        digitalWrite(STATUS_LED_PIN, STATUS_LED_ACTIVE_HIGH ? LOW : HIGH);
    }

    // Passive buzzer on LEDC. Writing a frequency of 0 leaves the channel
    // silent, so the buzzer starts OFF and stays OFF until a state asks for it.
    if (BUZZER_PIN >= 0) {
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
#endif
    }

    lockServo.setPeriodHertz(SERVO_FREQUENCY_HZ);
    int servoAttachResult = lockServo.attach(
        SERVO_PIN,
        SERVO_MIN_PULSE_US,
        SERVO_MAX_PULSE_US
    );
    Serial.printf("[SERVO] attach result: %s\n",
                  servoAttachResult >= 0 ? "SUCCESS" : "FAILED");
    delay(300);
    if (servoAttachResult >= 0) {
        // Explicit hardware command at boot. This does not depend on the
        // cached servoLocked flag, which begins false before any real write.
        lockServo.write(constrain(SERVO_RELEASE_ANGLE, 0, 180));
        servoLocked = false;
        Serial.println("[servo] initialized RELEASED");
    }

    if (ACTUATOR_CALIBRATION_MODE) {
        lockServo.write(CALIBRATION_SERVO_ANGLE);
        Serial.printf("[servo] CALIBRATION MODE (%d degrees)\n", CALIBRATION_SERVO_ANGLE);
        Serial.println("[servo] wheel lifted; keep the mechanical brake usable.");
    }

    display.begin();
    display.splash();
    Serial.printf("[cfg] server  %s://%s:%d%s\n", USE_HTTPS ? "https" : "http", SERVER_HOST,
                  SERVER_PORT, SERVER_PATH);
    Serial.printf("[cfg] commands %s\n", COMMANDS_PATH);
    Serial.printf("[cfg] key     %s\n", keyFingerprint());
    Serial.printf("[cfg] servo GPIO%d release=%d lock=%d at %d Hz; buzzer GPIO%d\n", SERVO_PIN,
                  SERVO_RELEASE_ANGLE, SERVO_LOCK_ANGLE, SERVO_FREQUENCY_HZ, BUZZER_PIN);
    Serial.printf("[cfg] expiry: silent until 0, then servo LOCK + continuous alarm "
                  "(warning window %d s, warning buzzer disabled)\n",
                  EXPIRY_WARNING_SECONDS);
    connectWifi();
}

void loop() {
    uint32_t now = millis();

    if (!api.connected()) {
        // A dead link must not relax the actuators: whatever was last enforced
        // stays enforced until the server is reachable again.
        maintainExpiredSafetyDuringNetworkFailure();
        if (now - lastWifiRetry > WIFI_RETRY_INTERVAL_MS) connectWifi();
        if (!wifiAnnounced) {
            display.showWifiConnecting();
            wifiAnnounced = true;
        }
        delay(250);
        return;
    }
    if (!everSynced) {
        display.showWifiConnecting();
        Serial.print("[ip] ");
        Serial.println(WiFi.localIP());
        everSynced = true;
    }

    // 1. Poll / update rental state.
    bool polledThisPass = false;
    uint32_t interval = serverReachable ? pollIntervalMs() : (uint32_t)RETRY_SECONDS * 1000UL;
    if (!polledOnce || now - lastPollMs >= interval) {
        lastPollMs = now;
        polledOnce = true;
        polledThisPass = true;
        String error;
        serverReachable = api.poll(state, error);
        if (!serverReachable) {
            lastError = error;
            // A failed poll changes nothing: the actuators keep their last state.
            Serial.printf("[loop] poll failed: %s (actuator state preserved)\n", error.c_str());
            display.setErrorLed(true);
        } else {
            display.setErrorLed(false);
        }
    }
    if (!serverReachable) {
        // Still enforce expiry safety on the last known state, so a network blip
        // cannot unlock a timed-out wheel or silence its alarm.
        maintainExpiredSafetyDuringNetworkFailure();
        display.showNoServer(lastError);
        delay(500);
        return;
    }

    // 2. Apply actuator safety from the freshly polled state.
    applyRentalActuators();
    enforceActuatorSafety();

    // 3. Process server commands.
    if (polledThisPass) {
        pollAndExecuteCommands();
    }

    // 4. Apply actuator safety AGAIN, so no command can leave a timed-out
    //    wheel unlocked or silent, whatever it asked for.
    enforceActuatorSafety();

    // 5. Update the LCD.
    if (lcdMessageUntilMs > now) display.showMessage("PEDALYA", lcdMessage);
    else if (actuatorState == ActuatorState::Expired) {
        // A 16x2 display cannot show the requested heading, overdue time, and
        // penalty at once. Preserve the immediate expiry confirmation, then
        // alternate the count-up time and peso penalty on row two.
        if (overdueSeconds < 3) {
            display.showExpired();
        } else if (((overdueSeconds / 2) % 2) == 0) {
            char overdueLine[17];
            snprintf(overdueLine, sizeof(overdueLine), "OVERDUE %02lu:%02lu",
                     (unsigned long)(overdueSeconds / 60),
                     (unsigned long)(overdueSeconds % 60));
            display.showMessage("RETURN BICYCLE", overdueLine);
        } else {
            char penaltyLine[17];
            snprintf(penaltyLine, sizeof(penaltyLine), "PENALTY P%.0f", overduePenalty);
            display.showMessage("RETURN BICYCLE", penaltyLine);
        }
    }
    else if (actuatorState == ActuatorState::Warning) display.showExpiryWarning(state);
    else if (state.hasRental) display.showRental(state);
    else display.showIdle();

    delay(200);
}

