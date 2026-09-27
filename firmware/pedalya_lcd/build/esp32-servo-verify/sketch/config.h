#line 1 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\config.h"
#pragma once

// Live credentials live in secrets.h, which is gitignored. Every setting below
// is wrapped in #ifndef so a value from secrets.h always wins.
#if defined(__has_include)
#if __has_include("secrets.h")
#include "secrets.h"
#endif
#endif

// Real values live in the gitignored secrets.h. These are only fallbacks so the
// sketch still builds on a machine where secrets.h has not been created yet.
#ifndef WIFI_SSID
#define WIFI_SSID "PLDTHOMEFIBRZ47rS"
#endif

#ifndef WIFI_PASSWORD
#define WIFI_PASSWORD "PLDTWIFIZ2953"
#endif

// Dev machine on the LAN. Override for a real deployment with a hostname.
#ifndef SERVER_HOST
#define SERVER_HOST "192.168.1.112"
#endif

// php artisan serve listens here.
#ifndef SERVER_PORT
#define SERVER_PORT 8000
#endif

#ifndef SERVER_PATH
#define SERVER_PATH "/api/iot/bicycle/1/rental"
#endif

// Uses the same per-bicycle key as SERVER_PATH. Keep the bicycle id aligned
// with SERVER_PATH when provisioning another unit.
#ifndef COMMANDS_PATH
#define COMMANDS_PATH "/api/iot/bicycle/1/commands"
#endif

#ifndef USE_HTTPS
#define USE_HTTPS 0
#endif

#ifndef SERVER_FINGERPRINT
#define SERVER_FINGERPRINT ""
#endif

#ifndef DEVICE_API_KEY
#define DEVICE_API_KEY ""
#endif

#ifndef I2C_ADDRESS
#define I2C_ADDRESS 0x27
#endif

#ifndef I2C_SDA_PIN
#define I2C_SDA_PIN 21
#endif

#ifndef I2C_SCL_PIN
#define I2C_SCL_PIN 22
#endif

#ifndef LCD_COLUMNS
#define LCD_COLUMNS 16
#endif

#ifndef LCD_ROWS
#define LCD_ROWS 2
#endif

#ifndef BACKLIGHT_PIN
#define BACKLIGHT_PIN -1
#endif

#ifndef BACKLIGHT_ON
#define BACKLIGHT_ON true
#endif

// LED_BUILTIN is not defined on every ESP32 core/board pairing, so the pin is
// named explicitly. 2 is the onboard RGB LED on the DevKit V1.
#ifndef STATUS_LED_PIN
#define STATUS_LED_PIN 2
#endif

#ifndef STATUS_LED_ACTIVE_HIGH
#define STATUS_LED_ACTIVE_HIGH true
#endif

// Actuators. GPIO18 is the servo PWM signal only: power the servo from a
// separate regulated 5-6 V supply and bond that supply's GND to ESP32 GND.
// GPIO19 drives a transistor/MOSFET buzzer driver, never a high-current buzzer
// directly. Override these in secrets.h for a particular bicycle.
#ifndef SERVO_PIN
#define SERVO_PIN 18
#endif

#ifndef SERVO_RELEASE_ANGLE
#define SERVO_RELEASE_ANGLE 0
#endif

#ifndef SERVO_LOCK_ANGLE
#define SERVO_LOCK_ANGLE 100
#endif

#ifndef SERVO_FREQUENCY_HZ
#define SERVO_FREQUENCY_HZ 50
#endif

#ifndef SERVO_MIN_PULSE_US
#define SERVO_MIN_PULSE_US 500
#endif

#ifndef SERVO_MAX_PULSE_US
#define SERVO_MAX_PULSE_US 2400
#endif

#ifndef BUZZER_PIN
#define BUZZER_PIN 19
#endif

#ifndef BUZZER_ACTIVE_HIGH
#define BUZZER_ACTIVE_HIGH true
#endif

// A passive buzzer needs a PWM tone, unlike an active buzzer which can be
// switched with a steady HIGH/LOW signal. Set to 0 only when replacing this
// with an active buzzer module.
#ifndef BUZZER_IS_PASSIVE
#define BUZZER_IS_PASSIVE 1
#endif

#ifndef BUZZER_FREQUENCY_HZ
#define BUZZER_FREQUENCY_HZ 2000
#endif

// Keep this LEDC channel free of other PWM devices. ESP32Servo allocates its
// own timer/channel, so channel 6 avoids the normal servo range.
#ifndef BUZZER_LEDC_CHANNEL
#define BUZZER_LEDC_CHANNEL 6
#endif

#ifndef BUZZER_BEEP_DURATION_MS
#define BUZZER_BEEP_DURATION_MS 300
#endif

// Length of the final window in which the rider is warned before the rental
// expires. The servo stays RELEASED for the whole window; only the timer runs
// down to zero.
#ifndef EXPIRY_WARNING_SECONDS
#define EXPIRY_WARNING_SECONDS 5
#endif

// Audible beeps during that final window. MUST stay 0: the rider is warned by
// the LCD and the servo lock only. The buzzer is reserved exclusively for the
// expired alarm, so a running rental can never make a sound.
#ifndef WARNING_BUZZER_ENABLED
#define WARNING_BUZZER_ENABLED 0
#endif

// Retired. The expired alarm is continuous, so there is no repeat interval and
// no 300 ms pulse to schedule. Kept at 0 so any leftover reference fails
// closed: a non-zero value would re-introduce the pulsing alarm.
#ifndef WARNING_BEEP_INTERVAL_MS
#define WARNING_BEEP_INTERVAL_MS 0UL
#endif

// Retired. The expired alarm is now continuous, so there is no repeat interval
// and no 300 ms pulse to schedule. Kept at 0 so any leftover reference fails
// closed: a non-zero value would re-introduce the pulsing alarm.
#ifndef EXPIRED_BEEP_INTERVAL_MS
#define EXPIRED_BEEP_INTERVAL_MS 0
#endif

// Once a rental expires, the on-device overdue timer counts upward from the
// local expiry transition. The first five minutes are free; each started
// interval thereafter adds the configured peso amount.
#ifndef RETURN_GRACE_SECONDS
#define RETURN_GRACE_SECONDS 300
#endif

#ifndef PENALTY_INTERVAL_SECONDS
#define PENALTY_INTERVAL_SECONDS 300
#endif

#ifndef PENALTY_PER_INTERVAL
#define PENALTY_PER_INTERVAL 30.0f
#endif

// Safety calibration is opt-in. Enable only while the bicycle is stationary,
// its wheel is lifted, and the normal mechanical brake remains usable. In this
// mode automatic lock/release commands are ignored and the servo holds the
// explicit test angle below.
#ifndef ACTUATOR_CALIBRATION_MODE
#define ACTUATOR_CALIBRATION_MODE 0
#endif

#ifndef CALIBRATION_SERVO_ANGLE
#define CALIBRATION_SERVO_ANGLE SERVO_RELEASE_ANGLE
#endif

#ifndef CALIBRATION_TEST_BUZZER
#define CALIBRATION_TEST_BUZZER 0
#endif

#ifndef WIFI_CONNECT_ATTEMPTS
#define WIFI_CONNECT_ATTEMPTS 40
#endif

#ifndef WIFI_RETRY_INTERVAL_MS
#define WIFI_RETRY_INTERVAL_MS 15000
#endif

// Do not reconfigure the station during association. If it has still not
// joined after this long, PedalyaApi cleanly ends that one attempt and starts
// a new one on the next retry.
#ifndef WIFI_CONNECT_TIMEOUT_MS
#define WIFI_CONNECT_TIMEOUT_MS 30000
#endif

#ifndef DEFAULT_POLL_SECONDS
#define DEFAULT_POLL_SECONDS 15
#endif

#ifndef REQUEST_TIMEOUT_MS
#define REQUEST_TIMEOUT_MS 8000
#endif

#ifndef NTP_SERVER
#define NTP_SERVER "pool.ntp.org"
#endif

#ifndef DEFAULT_UTC_OFFSET_SECONDS
#define DEFAULT_UTC_OFFSET_SECONDS 28800
#endif

#ifndef HTTP_RESPONSE_BUFFER
#define HTTP_RESPONSE_BUFFER 4096
#endif

#ifndef STATUS_LED_ON_ERROR
#define STATUS_LED_ON_ERROR 1
#endif
