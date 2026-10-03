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
#define WIFI_SSID ""
#endif

#ifndef WIFI_PASSWORD
#define WIFI_PASSWORD ""
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

// All four per-bicycle paths must name the same bicycle as DEVICE_API_KEY.
#ifndef COMMANDS_PATH
#define COMMANDS_PATH "/api/iot/bicycle/1/commands"
#endif

#ifndef HEARTBEAT_PATH
#define HEARTBEAT_PATH "/api/iot/bicycle/1/heartbeat"
#endif

#ifndef GPS_PATH
#define GPS_PATH "/api/iot/bicycle/1/gps"
#endif

// Accident reports go to the per-bicycle endpoint so they use the same device
// key as every other call above, instead of the fleet-wide shared key.
#ifndef ACCIDENT_REPORT_PATH
#define ACCIDENT_REPORT_PATH "/api/iot/bicycle/1/accident-report"
#endif

// Kept in step with the "1" in the four paths above.
#ifndef ACCIDENT_BICYCLE_ID
#define ACCIDENT_BICYCLE_ID 1
#endif

// Recorded as the reporter on the accident row. This must be a device
// identifier, never DEVICE_API_KEY: the value is stored in the database and
// shown on the admin dashboard.
#ifndef ACCIDENT_DEVICE_ID
#define ACCIDENT_DEVICE_ID "pedalya-esp32-1"
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
// GPIO2 is used by the GPS UART in this prototype.
#define STATUS_LED_PIN -1
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
#define SERVO_LOCK_ANGLE 80
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

#ifndef GPS_RX_PIN
#define GPS_RX_PIN 4
#endif

#ifndef GPS_TX_PIN
#define GPS_TX_PIN 2
#endif

#ifndef GPS_BAUD_RATE
#define GPS_BAUD_RATE 9600
#endif

#ifndef GPS_REPORT_INTERVAL_MS
#define GPS_REPORT_INTERVAL_MS 15000UL
#endif

#ifndef GPS_FIX_STALE_MS
#define GPS_FIX_STALE_MS 30000UL
#endif

/* ------------------------------------------------------------------------
 *  MPU6050 accident detection
 *
 *  The IMU shares the I²C bus with the LCD, so it is read with the raw
 *  register interface and no extra library.
 *
 *  An accident is only reported when a *hard* signal and a *contextual* one
 *  agree, or when the impact is violent enough to stand on its own. Ordinary
 *  riding produces plenty of 1 g to 2 g of cornering and curb noise, so a
 *  single accelerometer reading is never treated as an accident by itself.
 *
 *  The accelerometer runs at ±8 g rather than the ±2 g default. A crash
 *  regularly exceeds 2 g, and at ±2 g the part saturates: the firmware would
 *  be unable to tell a 2 g pothole from a serious fall, and could not report
 *  the magnitudes the backend severity ladder expects.
 * ------------------------------------------------------------------------ */

#ifndef ACCIDENT_ENABLED
#define ACCIDENT_ENABLED 1
#endif

// AD0 low. High (0x69) is probed automatically when nothing answers here.
#ifndef MPU6050_I2C_ADDRESS
#define MPU6050_I2C_ADDRESS 0x68
#endif

// Re-probe this often while no sensor answers, so a module plugged in later
// is picked up without resetting the board.
#ifndef ACCIDENT_PROBE_INTERVAL_MS
#define ACCIDENT_PROBE_INTERVAL_MS 5000UL
#endif

// 100 ms is 10 samples/s: fast enough to catch an impact transient, slow
// enough that a busy I²C bus is not starved from the LCD and the servo.
#ifndef ACCIDENT_SAMPLE_INTERVAL_MS
#define ACCIDENT_SAMPLE_INTERVAL_MS 100UL
#endif

// Total acceleration magnitude that counts as a hit. Comfortably above the
// ~1.5 g a curb or a pothole produces at riding speed.
#ifndef ACCIDENT_IMPACT_G
#define ACCIDENT_IMPACT_G 3.0f
#endif

// Change in |a| between two samples that counts as a hit. This is the jerk
// term: it catches a blow that lands along the gravity axis, where the
// magnitude is climbing fast but has not yet reached ACCIDENT_IMPACT_G.
#ifndef ACCIDENT_JERK_G
#define ACCIDENT_JERK_G 2.0f
#endif

// Losing gravity is the other side of a crash. A bike that has stopped
// pressing on the ground has been thrown, dropped, or is in the air, and no
// upper bound can see that. Only a low limit can.
#ifndef ACCIDENT_FREEFALL_G
#define ACCIDENT_FREEFALL_G 0.4f
#endif

// A hit this violent is reported by itself: nothing else needs to agree.
#ifndef ACCIDENT_HARD_IMPACT_G
#define ACCIDENT_HARD_IMPACT_G 6.0f
#endif

// A rider hitting the deck or a wheel spinning up in the air.
#ifndef ACCIDENT_ROTATION_DPS
#define ACCIDENT_ROTATION_DPS 220.0f
#endif

// How far the bike may lean from upright before it counts as down. A bike on
// its side is ~90°, so this is generous.
#ifndef ACCIDENT_TILT_DEGREES
#define ACCIDENT_TILT_DEGREES 55.0f
#endif

// Consecutive samples the bike must stay down before that alone reports, so a
// sharp swerve or a camera bump cannot trigger it. 10 samples is 1 second.
#ifndef ACCIDENT_TILT_HOLD_SAMPLES
#define ACCIDENT_TILT_HOLD_SAMPLES 10UL
#endif

// Consecutive agreeing samples needed to fire, and how many single samples may
// drop out in between. The tolerance absorbs the bounce as a bike hits and
// rebounds without letting a momentary spike through.
#ifndef ACCIDENT_TRIGGER_SAMPLES
#define ACCIDENT_TRIGGER_SAMPLES 2
#endif

#ifndef ACCIDENT_TRIGGER_TOLERANCE
#define ACCIDENT_TRIGGER_TOLERANCE 1
#endif

// Once an accident fires the detector stays latched for this long, so a rider
// sliding along the ground produces one report rather than one per sample.
#ifndef ACCIDENT_REARM_COOLDOWN_MS
#define ACCIDENT_REARM_COOLDOWN_MS 60000UL
#endif

// After the cooldown, the bike must also be quiet for this long before the
// detector can fire again. Upright, still, and not spinning.
#ifndef ACCIDENT_REARM_CALM_MS
#define ACCIDENT_REARM_CALM_MS 5000UL
#endif

// What "quiet" means for re-arming: how far the magnitude may sit from 1 g,
// and how fast it may still be turning.
#ifndef ACCIDENT_CALM_MARGIN_G
#define ACCIDENT_CALM_MARGIN_G 0.6f
#endif

#ifndef ACCIDENT_CALM_ROTATION_DPS
#define ACCIDENT_CALM_ROTATION_DPS 60.0f
#endif

#ifndef ACCIDENT_SERIAL_TRACE
#define ACCIDENT_SERIAL_TRACE 1
#endif

#ifndef ACCIDENT_TRACE_INTERVAL_MS
#define ACCIDENT_TRACE_INTERVAL_MS 1000UL
#endif

// A report that the server rejects is retried rather than dropped: the admin
// is never told about an accident the rider cannot see was sent.
#ifndef ACCIDENT_REPORT_RETRY_MS
#define ACCIDENT_REPORT_RETRY_MS 3000UL
#endif

// Attempts actually spent talking to the server. Time spent offline does not
// count against this, so a long outage does not silently discard the report.
#ifndef ACCIDENT_REPORT_MAX_ATTEMPTS
#define ACCIDENT_REPORT_MAX_ATTEMPTS 20
#endif

#ifndef ACCIDENT_LCD_HOLD_MS
#define ACCIDENT_LCD_HOLD_MS 10000UL
#endif

#ifndef BUZZER_ACTIVE_HIGH
#define BUZZER_ACTIVE_HIGH true
#endif

// The tested prototype uses an active buzzer: HIGH turns it on and LOW turns it
// off. Set this to 1 only for a passive piezo that needs PWM tone output.
#ifndef BUZZER_IS_PASSIVE
#define BUZZER_IS_PASSIVE 0
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

// The two alarm shapes, both of them inside the expired state only.
//
// A rental that times out is announced by a flat, unbroken tone. That is the
// "your time is up" alarm, and it is the loudest and least ambiguous thing the
// hardware can do.
//
// The free return window that follows is a different call: come back to the
// station. It is signalled with discrete beeps that crowd together as the
// grace runs out, so the pattern itself says how much time is left without the
// rider having to read the LCD. Return inside the window and the beeps stop; let
// it lapse and the flatline comes back for good.

// How long the flatline holds before the countdown beeps take over.
#ifndef EXPIRED_FLATLINE_MS
#define EXPIRED_FLATLINE_MS 4000UL
#endif

// Set to 0 to sound one continuous flatline for the entire expired state.
#ifndef RETURN_COUNTDOWN_ENABLED
#define RETURN_COUNTDOWN_ENABLED 1
#endif

// Length of a single countdown beep. Long enough to be heard as a beep rather
// than a tick through a small piezo driver, short enough that the shrinking
// gaps are what the rider hears as the countdown.
#ifndef RETURN_COUNTDOWN_TONE_MS
#define RETURN_COUNTDOWN_TONE_MS 200UL
#endif

// Silence before a countdown beep, with the full window left and with none of it
// left. The gap shrinks linearly in between.
#ifndef RETURN_COUNTDOWN_GAP_START_MS
#define RETURN_COUNTDOWN_GAP_START_MS 1800UL
#endif

#ifndef RETURN_COUNTDOWN_GAP_END_MS
#define RETURN_COUNTDOWN_GAP_END_MS 250UL
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
