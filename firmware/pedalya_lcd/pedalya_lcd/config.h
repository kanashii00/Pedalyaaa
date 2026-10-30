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
// They are placeholders and must never hold real credentials: secrets.h wins
// via #ifndef whenever it is present.
#ifndef WIFI_SSID
#define WIFI_SSID "YOUR_WIFI_SSID"
#endif

#ifndef WIFI_PASSWORD
#define WIFI_PASSWORD "YOUR_WIFI_PASSWORD"
#endif

// Hostname only: never a scheme, never a port, never a trailing slash. The
// scheme (https) and the path come from USE_HTTPS and the *_PATH macros.
#ifndef SERVER_HOST
#define SERVER_HOST "snf075nb-8000.asse.devtunnels.ms"
#endif

// HTTPS port for Dev Tunnel.
#ifndef SERVER_PORT
#define SERVER_PORT 443
#endif

#ifndef SERVER_PATH
#define SERVER_PATH "/api/iot/bicycle/1/rental"
#endif

// The three per-bicycle paths below must name the same bicycle as
// DEVICE_API_KEY. The fleet-wide paths (HEARTBEAT_PATH, ACCIDENT_REPORT_PATH)
// authenticate with PEDALYA_API_KEY instead.
#ifndef COMMANDS_PATH
#define COMMANDS_PATH "/api/iot/bicycle/1/commands"
#endif

#ifndef HEARTBEAT_PATH
#define HEARTBEAT_PATH "/api/iot/heartbeat"
#endif

#ifndef GPS_PATH
#define GPS_PATH "/api/iot/bicycle/1/gps"
#endif

// Accident reports go to the fleet-wide endpoint, which authenticates with the
// shared fleet key (PEDALYA_API_KEY below) rather than the per-bicycle one.
#ifndef ACCIDENT_REPORT_PATH
#define ACCIDENT_REPORT_PATH "/api/iot/accident-report"
#endif

// Public read-only connectivity probe. No API key is sent here: the backend's
// /api/health route sits outside every auth middleware, so this GET exercises
// modem attach, TLS and HTTP without touching rental, lock, GPS or accident
// state. Used only by PedalyaApi::checkHealth().
#ifndef HEALTH_PATH
#define HEALTH_PATH "/api/health"
#endif

// Connectivity modes:
// 1 = Diagnostic Wi-Fi health test mode (probes /api/health only, skips business requests)
// 2 = Normal IoT operational mode (Wi-Fi primary with EC20F cellular fallback)
// 3 = EC20F cellular-only operational mode (cellular only, Wi-Fi off)
#ifndef CONNECTIVITY_MODE_WIFI_HEALTH_TEST
#define CONNECTIVITY_MODE_WIFI_HEALTH_TEST 1
#endif

#ifndef CONNECTIVITY_MODE_NORMAL
#define CONNECTIVITY_MODE_NORMAL 2
#endif

#ifndef CONNECTIVITY_MODE_EC20F_SIM
#define CONNECTIVITY_MODE_EC20F_SIM 3
#endif

#ifndef CONNECTIVITY_MODE
#define CONNECTIVITY_MODE CONNECTIVITY_MODE_NORMAL
#endif

#if CONNECTIVITY_MODE != CONNECTIVITY_MODE_WIFI_HEALTH_TEST && \
    CONNECTIVITY_MODE != CONNECTIVITY_MODE_NORMAL && \
    CONNECTIVITY_MODE != CONNECTIVITY_MODE_EC20F_SIM
#error "CONNECTIVITY_MODE must select Wi-Fi health test, Normal (Wi-Fi + EC20F fallback), or EC20F SIM-only"
#endif

// The diagnostic health probe must exercise the same deployment as the normal
// API calls.  A separate default tunnel can return a valid TLS response but a
// misleading 404 from an unrelated service.  Units that deliberately need a
// different diagnostic target may still override these in ignored secrets.h.
#ifndef WIFI_TEST_SERVER_HOST
#define WIFI_TEST_SERVER_HOST SERVER_HOST
#endif

#ifndef WIFI_TEST_SERVER_PORT
#define WIFI_TEST_SERVER_PORT SERVER_PORT
#endif

#ifndef WIFI_TEST_HEALTH_PATH
#define WIFI_TEST_HEALTH_PATH HEALTH_PATH
#endif

// Kept in step with the "1" in the per-bicycle paths above.
#ifndef ACCIDENT_BICYCLE_ID
#define ACCIDENT_BICYCLE_ID 1
#endif

// The bicycle this unit is bolted to. Kept in step with the digit in the
// per-bicycle paths above and with the row the backend provisioned a
// per-bicycle key for.
#ifndef BICYCLE_ID
#define BICYCLE_ID 1
#endif

// Identifier this unit reports as in fleet-wide payloads (heartbeat,
// accident-report). This is a device name, never a key: the value is stored
// in the database and shown on the admin dashboard.
#ifndef DEVICE_ID
#define DEVICE_ID "pedalya-esp32-1"
#endif

// Recorded as the reporter on the accident row. This must be a device
// identifier, never DEVICE_API_KEY: the value is stored in the database and
// shown on the admin dashboard.
#ifndef ACCIDENT_DEVICE_ID
#define ACCIDENT_DEVICE_ID DEVICE_ID
#endif

#ifndef USE_HTTPS
#define USE_HTTPS 1
#endif

// Trusted Root CA certificates for TLS verification (Microsoft Root G2 for Dev Tunnels).
// Overridable in secrets.h if custom certificates are needed.
#ifndef SERVER_ROOT_CA
#define SERVER_ROOT_CA \
"-----BEGIN CERTIFICATE-----\n" \
"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\n" \
"eM+HJyMso/L+NgtC+eHXJLur5sg=\n" \
"-----END CERTIFICATE-----\n"
#endif

#ifndef SERVER_FINGERPRINT
#define SERVER_FINGERPRINT ""
#endif

// The per-bicycle key is a secret: it lives only in the gitignored secrets.h
// and stays empty here, so a copied or committed config.h cannot authenticate.
#ifndef DEVICE_API_KEY
#define DEVICE_API_KEY ""
#endif

// Second credential, for the endpoints that are shared by the whole fleet:
// /api/iot/heartbeat and /api/iot/accident-report sit behind the backend's
// device.auth middleware, which compares against services.device_api_key (the
// DEVICE_API_KEY entry of the backend's .env) instead of a bicycle's own key.
// Sending the bicycle key there is rejected as "Invalid API key"; putting the
// fleet value here is the configuration fix. The per-bicycle value above stays
// DEVICE_API_KEY for /rental, /commands and /gps.
#ifndef PEDALYA_API_KEY
#define PEDALYA_API_KEY ""
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
// GPIO2 is free now that the GPS moved off it onto GPIO25/GPIO26, but the LED
// stays off (-1) exactly as in the prototype; set it in secrets.h to use it.
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
#define SERVO_RELEASE_ANGLE 100
#endif

#ifndef SERVO_LOCK_ANGLE
#define SERVO_LOCK_ANGLE 0
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

/* ------------------------------------------------------------------------
 *  Gradual lock and the geofence it is judged against
 *
 *  With more than GRADUAL_LOCK_SECONDS left the wheel is simply released.
 *  Inside that window the servo walks from RELEASE toward LOCK in step with
 *  the countdown while the bicycle is still at the riding zone, and snaps to
 *  LOCK at once when it is not. Every write goes through the state-change
 *  guard in LockActuator::writePhysical, so a settled angle is never
 *  re-commanded and the serial log shows only real moves.
 * ------------------------------------------------------------------------ */

// Length of the graduated lock window, in seconds before the return deadline.
#ifndef GRADUAL_LOCK_SECONDS
#define GRADUAL_LOCK_SECONDS 10
#endif

// The active riding zone, mirroring the `geofences` row the backend enforces
// (circle zone "Azuela Cove Riding Zone"). Keep these in step with the
// database when the zone is edited - override them in secrets.h per bicycle
// rather than editing this fallback.
#ifndef GEOFENCE_CENTER_LAT
#define GEOFENCE_CENTER_LAT 7.1994902
#endif

#ifndef GEOFENCE_CENTER_LNG
#define GEOFENCE_CENTER_LNG 125.6247171
#endif

// Radius of that circle, in metres.
#ifndef GEOFENCE_RADIUS_M
#define GEOFENCE_RADIUS_M 110.0
#endif

// The zone's warning threshold, in metres: how far past the edge a bicycle
// still counts as NEAR the boundary rather than OUTSIDE it.
#ifndef GEOFENCE_NEAR_MARGIN_M
#define GEOFENCE_NEAR_MARGIN_M 35.0
#endif

#ifndef BUZZER_PIN
#define BUZZER_PIN 19
#endif

// NEO-6M on UART1: the module's TXD feeds ESP32 RX (GPIO25), the module's RXD
// is driven by ESP32 TX (GPIO26). GPIO1/GPIO3 stay reserved for the USB-UART0
// monitor, and GPIO4/GPIO2 are released by this move.
#ifndef GPS_RX_PIN
#define GPS_RX_PIN 25
#endif

#ifndef GPS_TX_PIN
#define GPS_TX_PIN 26
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
 *  Quectel EC20F cellular modem (UART2)
 *
 *  The modem is only a bearer. Every Pedalya decision, payload, endpoint and
 *  device key stays in PedalyaApi and the sketch; the cellular link is a route
 *  to the same HTTP API. In SIM-only mode (SIM_ONLY_MODE 1) it is the ONLY
 *  route: Wi-Fi is never associated and never consulted.
 *
 *  Wiring (never GPIO1/GPIO3, those are the USB-UART0 monitor pins):
 *      EC20F TXD -> ESP32 GPIO16 (SIM_RX_PIN)
 *      EC20F RXD -> ESP32 GPIO17 (SIM_TX_PIN)
 *      EC20F GND -> ESP32 GND
 *
 *  GPS lives on UART1 (GPIO25/26), so no pin is shared with the modem.
 *
 *  Every timeout below bounds one blocking AT exchange, so a modem that is
 *  absent or dead costs one bounded wait instead of parking the main loop.
 * ------------------------------------------------------------------------ */

#ifndef SIM_ENABLED
#define SIM_ENABLED 1
#endif

// 0 = Dual connectivity: Wi-Fi primary with cellular (EC20F) fallback.
// 1 = Cellular-only: Wi-Fi radio stays off.
#ifndef SIM_ONLY_MODE
#define SIM_ONLY_MODE (CONNECTIVITY_MODE == CONNECTIVITY_MODE_EC20F_SIM)
#endif

#ifndef SIM_RX_PIN
#define SIM_RX_PIN 16
#endif

#ifndef SIM_TX_PIN
#define SIM_TX_PIN 17
#endif

#ifndef SIM_BAUD_RATE
#define SIM_BAUD_RATE 115200
#endif

// Carrier APN of the SIM in the modem. Never guess: ask the carrier and put
// the real value in secrets.h. The placeholder is rejected on purpose so an
// unconfigured unit fails loudly instead of dialling the wrong network.
#ifndef SIM_APN
#define SIM_APN "internet.globe.com.ph"
#endif

// EC20F TLS verification uses a certificate file already provisioned in the
// modem's UFS.  Do not put a PEM or a secret here: upload the approved CA by
// the modem provisioning process, then set its UFS filename in ignored
// secrets.h.  Leaving this empty makes cellular HTTPS fail closed.
#ifndef SIM_TLS_CA_FILE
#define SIM_TLS_CA_FILE ""
#endif


// One ordinary AT exchange (AT, AT+CGDCONT, AT+QHTTPCFG, ...).
#ifndef SIM_CMD_TIMEOUT_MS
#define SIM_CMD_TIMEOUT_MS 2000UL
#endif

// How long simInit() keeps probing AT after UART2 comes up. A cold EC20F can
// emit its RDY boot banner (and only start answering AT) seconds after power,
// so the probe window must outlast the boot banner or the modem is wrongly
// declared dead. Bounded: simInit() never blocks longer than this.
#ifndef SIM_MODEM_READY_WINDOW_MS
#define SIM_MODEM_READY_WINDOW_MS 8000UL
#endif

// Network attach and PDP activation, which are genuinely slower than a local
// AT command.
#ifndef SIM_NET_TIMEOUT_MS
#define SIM_NET_TIMEOUT_MS 8000UL
#endif

// One HTTP round trip (AT+QHTTPGET / AT+QHTTPPOST) over LTE, bounded so a
// hung request cannot starve GPS, LCD, buzzer or accident detection for
// longer than this.
#ifndef SIM_HTTP_TIMEOUT_MS
#define SIM_HTTP_TIMEOUT_MS 10000UL
#endif

// Gap between background bearer reconnect attempts while the modem answers
// but the data link is down.
#ifndef SIM_RETRY_INTERVAL_MS
#define SIM_RETRY_INTERVAL_MS 30000UL
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

#ifndef ACCIDENT_STARTUP_SETTLE_MS
#define ACCIDENT_STARTUP_SETTLE_MS 2500UL
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
// expires. The timer runs down to zero through it; the servo is governed by
// GRADUAL_LOCK_SECONDS instead, which starts walking the wheel toward LOCK
// before this window opens when the bicycle is still at the geofence.
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
#define REQUEST_TIMEOUT_MS 12000
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

// -----------------------------------------------------------------------
//  EC20F UART Diagnostic (temporary, opt-in)
//
//  Set to 1 in secrets.h to activate a multi-baud AT probe at boot.
//  The diagnostic runs once in setup(), before simInit(), and logs results
//  to the Serial monitor.  It NEVER changes modem-persistent settings.
//  Set back to 0 and reflash to restore normal production firmware.
//  See EC20FDiagnostic.h for full documentation.
// -----------------------------------------------------------------------
#ifndef EC20F_UART_DIAGNOSTIC
#define EC20F_UART_DIAGNOSTIC 0
#endif

// Diagnostic per-baud-rate receive window (milliseconds).
#ifndef EC20F_DIAG_TIMEOUT_MS
#define EC20F_DIAG_TIMEOUT_MS 600UL
#endif

// Quiet-period threshold: stop reading once the modem has been silent this
// long after its last byte.  Must be >= 200 ms: EC20F replies can arrive in
// bursts with short inter-burst gaps; 80 ms cut replies too early.
// NOTE: config.h is included before EC20FDiagnostic.h so this value wins.
#ifndef EC20F_DIAG_QUIET_MS
#define EC20F_DIAG_QUIET_MS 200UL
#endif

// Maximum raw bytes to hex-dump per baud-rate attempt in the diagnostic log.
#ifndef EC20F_DIAG_HEX_LIMIT
#define EC20F_DIAG_HEX_LIMIT 32
#endif

// Settle time after Serial2.begin() before the diagnostic sends AT.
#ifndef EC20F_DIAG_SETTLE_MS
#define EC20F_DIAG_SETTLE_MS 150UL
#endif
