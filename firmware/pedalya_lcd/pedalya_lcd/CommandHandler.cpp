#include "CommandHandler.h"

#include "config.h"

CommandHandler::CommandHandler(LcdDisplay& display, LockActuator& lock,
                               RentalController& controller, PedalyaApi& api)
    : display_(display), lock_(lock), controller_(controller), api_(api) {}

void CommandHandler::pollAndExecute(RentalState& rental) {
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

void CommandHandler::execute(const DeviceCommand& command, RentalState& rental) {
    bool executed = true;
    String result = "executed";

    if ((command.command == "lock" || command.command == "unlock") && !lock_.ready()) {
        executed = false;
        result = "servo unavailable";
        Serial.println("[command] actuator command failed: servo unavailable");
    } else if (command.command == "lock") {
        lock_.lockAtConfiguredAngle();
        rental.bicycleLockStatus = "locked";
        Serial.println("[servo] manual lock command accepted");
    } else if (command.command == "unlock") {
        // Refuse unlock if:
        // 1. Rental is expired or overdue
        // 2. Rental is explicitly returned or cancelled
        // 3. Bicycle is under maintenance
        // 4. API authorization failed (e.g. 401/403)
        const bool expired = controller_.rentalIsExpired(rental);
        const bool terminated = (rental.status == "returned" || rental.status == "cancelled");
        const bool maintenance = (rental.bicycleStatus == "maintenance");
        const bool authFailed = (api_.lastStatus() == 401 || api_.lastStatus() == 403);

        if ((expired && !command.manualOverride) || terminated || maintenance || authFailed) {
            executed = false;
            if (expired) result = "unlock blocked: rental expired";
            else if (terminated) result = "unlock blocked: rental terminated";
            else if (maintenance) result = "unlock blocked: bicycle in maintenance";
            else result = "unlock blocked: unauthorized";

            Serial.printf("[command] %s\n", result.c_str());
            lock_.writePhysical(SERVO_LOCK_ANGLE, "unlock-blocked");
            lock_.setLocked(true);
            rental.bicycleLockStatus = "locked";
        } else {
            lock_.release();
            rental.bicycleLockStatus = "unlocked";
            if (command.manualOverride) controller_.activateManualUnlockOverride();
            Serial.println("[servo] unlock command accepted");
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
        Serial.printf("[actuator] LCD command: %s\n", command.message.c_str());
        if (command.message.length() > 0) {
            uint32_t dur = (command.durationMs > 0 && command.durationMs <= 10000) ? command.durationMs : 4000;
            display_.showMessageFor(dur, "SERVER MESSAGE  ", command.message);
        }
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
