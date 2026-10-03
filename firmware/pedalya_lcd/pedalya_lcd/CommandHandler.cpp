#include "CommandHandler.h"

#include "config.h"

CommandHandler::CommandHandler(LcdDisplay& display, LockActuator& lock,
                               RentalController& controller, PedalyaApi& api)
    : display_(display), lock_(lock), controller_(controller), api_(api) {}

void CommandHandler::pollAndExecute(const RentalState& rental) {
    DeviceCommand commands[MAX_PENDING_COMMANDS];
    size_t count = 0;
    String error;

    if (!api_.pollCommands(commands, MAX_PENDING_COMMANDS, count, error)) {
        Serial.printf("[api] command poll failed: %s\n", error.c_str());
        return;
    }

    for (size_t i = 0; i < count; ++i) {
        Serial.printf("[api] command %lu: %s\n", (unsigned long)commands[i].id,
                      commands[i].command.c_str());
        execute(commands[i], rental);
    }
}

void CommandHandler::execute(const DeviceCommand& command, const RentalState& rental) {
    bool executed = true;
    String result = "executed";

    if ((command.command == "lock" || command.command == "unlock") && !lock_.ready()) {
        executed = false;
        result = "servo unavailable";
        Serial.println("[command] actuator command failed: servo unavailable");
    } else if (command.command == "lock") {
        lock_.lockAtConfiguredAngle();
        Serial.println("[servo] manual lock command accepted");
    } else if (command.command == "unlock") {
        // Allowed only while the rental has not expired. A queued or mistimed
        // unlock must not release a wheel whose time is already up, so the
        // refusal immediately re-issues the lock rather than merely declining.
        if (controller_.rentalIsExpired(rental)) {
            executed = false;
            result = "unlock blocked: rental expired";
            Serial.println("[command] unlock blocked: rental expired");
            lock_.writePhysical(SERVO_LOCK_ANGLE, "unlock-blocked-expired");
            lock_.setLocked(true);
        } else {
            lock_.release();
            Serial.println("[servo] manual unlock command accepted");
        }
    } else if (command.command == "buzzer") {
        // A server command can never be the reason the buzzer sounds before the
        // timer reaches zero. It is refused outright unless the rental has
        // genuinely expired, and the controller then re-checks the same
        // condition when it drives the alarm.
        if (!controller_.rentalIsExpired(rental)) {
            executed = false;
            result = "buzzer blocked: rental not expired";
            Serial.printf("[buzzer] command BLOCKED - state %s, not expired\n",
                          actuatorStateName(controller_.state()));
        }
    } else if (command.command == "lcd") {
        display_.showMessageFor(3000UL, "PEDALYA",
                                command.message.length() ? command.message : "RENTAL WARNING");
        Serial.printf("[actuator] LCD command: %s\n", command.message.c_str());
    } else if (command.command == "calibrate") {
        if (ACTUATOR_CALIBRATION_MODE && command.servoAngle >= 0 && command.servoAngle <= 180) {
            lock_.writePhysical(command.servoAngle, "calibration-command");
            Serial.printf("[servo] CALIBRATION (%d degrees)\n", command.servoAngle);
        } else {
            executed = false;
            result = "enable ACTUATOR_CALIBRATION_MODE first";
        }
    } else {
        executed = false;
        result = "unsupported by display firmware";
    }

    acknowledge(command, executed, result);
}

void CommandHandler::acknowledge(const DeviceCommand& command, bool executed,
                                 const String& result) {
    String error;
    if (!api_.acknowledgeCommand(command.id, executed, result, error)) {
        Serial.printf("[api] command %lu acknowledgement failed: %s\n",
                      (unsigned long)command.id, error.c_str());
    }
}