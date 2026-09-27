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
// Cleared whenever the bicycle is rideable again. This allows exactly one
// deliberate rotation on each fresh expiry, while the safety loop continues
// issuing the final holding command afterwards.
static bool lockMovementCompleted = false;
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

/**
 * The only normal-operation path that drives the GPIO18 servo signal.
 *
 * This deliberately has no servoLocked/actuatorState guard: the Serial line
 * documents the exact ESP32Servo command being issued to the physical pin.
 */
static void writeServoPhysical(int angle, const char* reason) {
    int safeAngle = constrain(angle, 0, 180);
    Serial.printf("[SERVO] PHYSICAL WRITE -> GPIO%d angle=%d reason=%s\n",
                  SERVO_PIN, safeAngle, reason);
    lockServo.write(safeAngle);
}

static void moveServo(int angle, bool locked, const char* message) {
    if (ACTUATOR_CALIBRATION_MODE) {
        Serial.println("[servo] calibration mode");
        return;
    }

    int safeAngle = constrain(angle, 0, 180);

    Serial.printf("[servo] COMMAND angle=%d locked=%d\n", safeAngle, locked ? 1 : 0);

    writeServoPhysical(safeAngle, message);
    delay(300);

    servoLocked = locked;

    Serial.printf("[servo] %s (%d degrees)\n", message, safeAngle);
}

static void releaseServo() { moveServo(SERVO_RELEASE_ANGLE, false, "RELEASED"); }
static void lockServoAtConfiguredAngle() { moveServo(SERVO_LOCK_ANGLE, true, "LOCKED"); }

/**
 * One controlled mechanical lock stroke for an expiry transition.
 *
 * Normal enforcement calls this once, then keeps commanding the final angle
 * without restarting the 0-to-lock sweep on every loop.
 */
static void performLockMovement() {
    const int releaseAngle = constrain(SERVO_RELEASE_ANGLE, 0, 180);
    const int lockAngle = constrain(SERVO_LOCK_ANGLE, 0, 180);
    const int step = releaseAngle <= lockAngle ? 5 : -5;

    Serial.println("[LOCK] RENTAL EXPIRED");
    Serial.printf("[LOCK] BEGIN ROTATION %d -> %d DEGREES\n", releaseAngle, lockAngle);

    for (int angle = releaseAngle;
         step > 0 ? angle <= lockAngle : angle >= lockAngle;
         angle += step) {
        Serial.printf("[LOCK] SERVO %d\n", angle);
        writeServoPhysical(angle, "lock-rotation");
        delay(50);
    }

    // A non-multiple-of-five configured target is always commanded exactly.
    if ((lockAngle - releaseAngle) % step != 0) {
        Serial.printf("[LOCK] SERVO %d\n", lockAngle);
        writeServoPhysical(lockAngle, "lock-rotation-final");
    }
    Serial.printf("[LOCK] LOCK POSITION REACHED: %d DEGREES\n", lockAngle);
    Serial.println("[LOCK] HOLDING LOCK");
}

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

    Serial.println("[OVERDUE] Rental expired");
    Serial.println("[OVERDUE] 5-minute grace period started");
}

static void enterActuatorState(ActuatorState next) {
    // UI state only. Rental safety never depends on this transition: the
    // physical GPIO18/buzzer commands live exclusively in
    // enforceActuatorSafety(), which runs every normal loop.
    if (actuatorState == next) return;
    actuatorState = next;
    switch (next) {
        case ActuatorState::Idle:
            commandLockOverride = false;
            Serial.println("[actuator] IDLE - bicycle available, rental inactive");
            break;
        case ActuatorState::Active:
            Serial.println("[actuator] ACTIVE - rental running, buzzer off");
            break;
        case ActuatorState::Warning:
            Serial.printf("[actuator] WARNING - %d seconds or less, still silent\n",
                          EXPIRY_WARNING_SECONDS);
            break;
        case ActuatorState::Expired:
            commandLockOverride = false;
            Serial.println("[actuator] EXPIRED - rental timed out");
            break;
        case ActuatorState::Unknown:
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
        const char* reason = !state.hasRental ? "idle" :
                             (state.status == "returned" ? "returned" : "cancelled");
        writeServoPhysical(SERVO_RELEASE_ANGLE, reason);
        servoLocked = false;
        lockMovementCompleted = false;
        setBuzzer(false);
        if (state.status == "returned") {
            Serial.println("[LOCK] RETURNED -> RELEASE 0 DEGREES");
        }
        Serial.println("[SAFETY] RETURNED/IDLE -> SERVO.write(0)");
        return;
    }

    // The current API state is the authority for normal actuator decisions.
    // (The expiry latch is used only by the network-loss fail-safe below.)
    if (state.expired()) {
        updateOverdueTimer();
        if (!lockMovementCompleted) {
            performLockMovement();
            lockMovementCompleted = true;
        }
        writeServoPhysical(SERVO_LOCK_ANGLE, "expired");
        servoLocked = true;
        setBuzzer(true);
        Serial.printf("[SAFETY] EXPIRED -> SERVO.write(%d)\n", SERVO_LOCK_ANGLE);
        return;
    }

    // Both a running rental and its final warning window remain rideable.
    writeServoPhysical(SERVO_RELEASE_ANGLE, "active");
    servoLocked = false;
    lockMovementCompleted = false;
    setBuzzer(false);
    Serial.println("[SAFETY] ACTIVE -> SERVO.write(0)");
}

// During a failed poll preserve the last servo position. A locally-known
// expiry is still reasserted so a network outage cannot defeat a lock/alarm.
static void maintainExpiredSafetyDuringNetworkFailure() {
    if (ACTUATOR_CALIBRATION_MODE) {
        setBuzzer(false);
        return;
    }
    if (rentalIsExpired()) {
        writeServoPhysical(SERVO_LOCK_ANGLE, "network-expired");
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
            writeServoPhysical(SERVO_LOCK_ANGLE, "unlock-blocked-expired");
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
            writeServoPhysical(command.servoAngle, "calibration-command");
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
    // ESP32Servo returns a channel number (including valid channel 0), so a
    // direct bool cast would incorrectly mark channel 0 as a failed attach.
    bool servoAttached = servoAttachResult >= 0;
    Serial.printf("[SERVO] ATTACH RESULT = %s\n", servoAttached ? "SUCCESS" : "FAILED");
    Serial.printf("[SERVO] ATTACHED = %s\n", lockServo.attached() ? "YES" : "NO");
    Serial.printf("[SERVO] PIN = %d\n", SERVO_PIN);
    Serial.printf("[SERVO] RELEASE = %d\n", SERVO_RELEASE_ANGLE);
    Serial.printf("[SERVO] LOCK = %d\n", SERVO_LOCK_ANGLE);
    Serial.printf("[SERVO] GPIO=%d RELEASE=%d LOCK=%d CALIBRATION=%d\n", SERVO_PIN,
                  SERVO_RELEASE_ANGLE, SERVO_LOCK_ANGLE, ACTUATOR_CALIBRATION_MODE);
    delay(300);
    if (servoAttached) {
        // Explicit hardware command at boot. This does not depend on the
        // cached servoLocked flag, which begins false before any real write.
        writeServoPhysical(SERVO_RELEASE_ANGLE, "boot-release");
        servoLocked = false;
        Serial.println("[servo] initialized RELEASED");

        // Temporary production diagnostic: this uses the same attached Servo
        // object and GPIO18 configuration as normal actuator safety.
        Serial.println("[SERVO] STARTUP TEST -> 0");
        writeServoPhysical(SERVO_RELEASE_ANGLE, "startup-test-0");
        delay(2000);
        Serial.printf("[SERVO] STARTUP TEST -> %d\n", SERVO_LOCK_ANGLE);
        writeServoPhysical(SERVO_LOCK_ANGLE, "startup-test-lock");
        delay(3000);
        Serial.println("[SERVO] STARTUP TEST -> 0");
        writeServoPhysical(SERVO_RELEASE_ANGLE, "startup-test-0");
        delay(2000);
    }

    if (ACTUATOR_CALIBRATION_MODE) {
        writeServoPhysical(CALIBRATION_SERVO_ANGLE, "calibration-boot");
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
