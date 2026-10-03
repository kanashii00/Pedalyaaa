#pragma once

#include <Arduino.h>

/**
 * The boot banner.
 *
 * Every setting the firmware is actually running with is printed once, so a
 * behaviour question can be answered from the Serial log without re-reading the
 * source. The device key is never printed: only a fingerprint of its length and
 * leading characters.
 */
namespace Diagnostics {

/** First characters and length of DEVICE_API_KEY, never the key itself. */
const char* keyFingerprint();

/** Server, paths, pin assignment and alarm timing. */
void printBanner();

/** Servo and buzzer geometry, which differs per bicycle. */
void printActuatorConfig();

}  // namespace Diagnostics