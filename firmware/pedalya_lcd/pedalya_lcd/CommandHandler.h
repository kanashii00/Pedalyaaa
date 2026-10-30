#pragma once

#include <Arduino.h>

#include "LcdDisplay.h"
#include "LockActuator.h"
#include "PedalyaApi.h"
#include "RentalController.h"

/**
 * The server command queue.
 *
 * A command is a request, never a decision. The controller still has the final
 * say on the wheel and the alarm, so anything a command asks for that the rental
 * does not allow is refused here and re-asserted by the next enforce().
 */
class CommandHandler {
public:
    CommandHandler(LcdDisplay& display, LockActuator& lock, RentalController& controller,
                   PedalyaApi& api);

    /** Fetches the queue and runs each command in order. */
    void pollAndExecute(RentalState& rental);

private:
    void execute(const DeviceCommand& command, RentalState& rental);
    void acknowledge(const DeviceCommand& command, bool executed, const String& result);

    static const size_t MAX_PENDING_COMMANDS = 5;

    LcdDisplay& display_;
    LockActuator& lock_;
    RentalController& controller_;
    PedalyaApi& api_;
};