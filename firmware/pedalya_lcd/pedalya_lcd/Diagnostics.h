#pragma once

#include <Arduino.h>

/**
 * The boot banner.
 *
 * Every setting the firmware is actually running with is printed once, so a
 * behaviour question can be answered from the Serial log without re-reading the
 * source. The device key itself is never printed.
 */
namespace Diagnostics {

/** Whether DEVICE_API_KEY is configured, without exposing any part of it. */
bool apiKeyConfigured();

/** Server, paths, pin assignment and alarm timing. */
void printBanner();

/** Servo and buzzer geometry, which differs per bicycle. */
void printActuatorConfig();

}  // namespace Diagnostics