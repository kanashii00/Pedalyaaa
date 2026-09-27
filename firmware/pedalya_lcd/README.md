# Pedalya on-board display (ESP32 + 16x2 I2C LCD)

Rides handlebars, stays in sync with the server.

The ESP32 owns no rental state. It polls the Pedalya API for the current rental,
the deadline in force, and the penalty accrued so far, then counts down locally
between polls. Laravel stays the only source of truth: the device never decides a
deadline, never charges anything, and every poll overwrites its local numbers
with the server's.

## Layout

Both IDEs build the same files, so there is only ever one copy of the code.

```
pedalya_lcd/
  platformio.ini            PlatformIO project
  .gitignore                ignores .pio/ and the live secrets.h
  pedalya_lcd/              Arduino IDE sketch folder
    pedalya_lcd.ino         setup() and loop()
    config.h                server, pins, and polling settings
    secrets.h               live WiFi password and device key (gitignored)
    secrets_example.h       committed template for secrets.h
    PedalyaApi.h/.cpp       WiFi, HTTP, JSON, clock
    LcdDisplay.h/.cpp       16x2 rendering
```

### Where the API key goes

`secrets.h`, never `config.h`. It holds the live device key and Wi-Fi password
and is listed in `.gitignore`, so `git add .` cannot commit a working
credential. `config.h` picks it up automatically:

```c
#if defined(__has_include)
#if __has_include("secrets.h")
#include "secrets.h"
#endif
#endif
```

Every setting in `config.h` is wrapped in `#ifndef`, so a value from
`secrets.h` always wins and the fallbacks in `config.h` are only used when
`secrets.h` is absent. On a new machine:

```
copy secrets_example.h secrets.h    # then paste in the real values
```

To see which key is loaded without printing it, the boot banner prints a
fingerprint, for example `[cfg] key     1d1e9e17... (64 chars)`.

## Opening in the Arduino IDE

### 1. Install the ESP32 board

Arduino IDE 2.x does not ship with ESP32 support, so add it once.

1. **File → Preferences** (or `Ctrl+,`)
2. **Additional boards manager URLs**, paste and click OK:
   ```
   https://espressif.github.io/arduino-esp32/package_esp32_index.json
   ```
3. **Tools → Board → Boards Manager**, search `esp32`
4. Install **"esp32 by Espressif Systems"**. It is a large download, several
   minutes, and the IDE looks frozen while it works. Let it finish.

### 2. Install the two libraries

**Tools → Manage Libraries** (or `Ctrl+Shift+I`), install each:

| Search for | Author | Version |
| --- | --- | --- |
| `ArduinoJson` | Benoit Blanchon | 7.x |
| `LiquidCrystal I2C` | Frank de Brabander | 1.1.2 |
| `ESP32Servo` | Kevin Harrington / John K. Bennett | 3.x |

The `LiquidCrystal I2C` author matters. Several libraries share that name, and the
Frank de Brabander one is the fork this sketch was written against.

### 3. Open the sketch

**File → Open**, pick `pedalya_lcd/pedalya_lcd/pedalya_lcd.ino`.

Open the `.ino`, not the parent folder. The Arduino IDE requires the sketch file
name to match its folder name, which is why the sources sit one level down.

### 4. Set the board and port

**Tools → Board → ESP32 Arduino → ESP32 Dev Module** (any board named
`ESP32 Dev Module` will do). **Tools → Port**, pick the COM port that appears
when you plug the board in. On macOS/Linux it is usually `/dev/cu.usbserial-*`;
if several appear, unplug and plug back in to see which one vanished.

**Tools → Upload Speed → 115200** matches the serial monitor.

### 5. Fill in `secrets.h`

From the sketch's tabs, open `pedalya_lcd/secrets.h` and set:

| Setting | What it is |
| --- | --- |
| `WIFI_SSID` / `WIFI_PASSWORD` | network the ESP32 joins |
| `DEVICE_API_KEY` | the key from step 1 below |

Everything else stays in `config.h`:

| Setting | What it is |
| --- | --- |
| `SERVER_HOST` | your machine's LAN IP, where Laravel runs |
| `SERVER_PORT` | `8000` for `php artisan serve`, or `443` with `USE_HTTPS 1` |
| `SERVER_PATH` | `/api/iot/bicycle/1/rental` for this bicycle |
| `I2C_ADDRESS` | `0x27`, or `0x3F` if the screen stays blank |

Save the file. Only `secrets.h` is ever edited per unit; the rest of the sketch
is shared.

### 6. Upload

Click the **right-arrow (Upload)** button. First compile is slow, and the IDE
prints the library paths and sketch size as it goes. A successful run ends with
`Hard resetting via RTS pin...`.

### 7. Watch it boot

**Tools → Serial Monitor**, **115200 baud**. Expect:

```
[cfg] server  http://192.168.1.112:8000/api/iot/bicycle/1/rental
[cfg] key     1d1e9e17... (64 chars)
[cfg] display I2C 0x27  SDA 21  SCL 22
[wifi] connecting to "PLDTHOMEFIBRZ47rS"
[ip] 192.168.1.115
[api] 200 OK from http://192.168.1.112:8000/api/iot/bicycle/1/rental
[api] bicycle idle
```

The LCD shows `PEDALYA / connecting...` until WiFi is up, then either
`READY TO RIDE` or the countdown.

## Reading a failure

The second LCD line names the fault; the Serial log explains it. The pair is
deliberate, because a 16x2 has no room for a diagnosis.

| LCD | Serial | Meaning |
| --- | --- | --- |
| `wifi down` | — | not associated with the access point |
| `no api key` | `DEVICE_API_KEY is empty` | `secrets.h` missing or key blank |
| `connect failed` | `cannot reach 192.168.1.112:8000` | PC unreachable: wrong subnet, `php artisan serve` still bound to `127.0.0.1`, or Windows Firewall blocking inbound TCP 8000 |
| `auth 401` | `server said: Invalid API key` | key is wrong, or belongs to a different bicycle than `SERVER_PATH` names |
| `auth 403` | `403 Forbidden` | key authenticated but not permitted |
| `404 not found` | `404 Not Found` | `SERVER_PATH` does not resolve, or the bicycle id does not exist. Not a key problem |
| `rate limited` | `429` | polling faster than `throttle:120,1` allows |
| `server error` | `500 from Laravel` | authenticated, but Laravel itself failed |

Two backend details worth knowing:

- The key must be the one issued **for the bicycle in `SERVER_PATH`**. A
  perfectly valid key for another bicycle is rejected, because that would let
  one unit read another unit's renter.
- A 404 comes back as an **HTML** page, not JSON. Implicit model binding rejects
  an unknown bicycle before the auth middleware runs, so there is no
  `{"message":...}` to show.

## Building with PlatformIO instead

```bash
export WIFI_SSID="your-ssid"
export WIFI_PASSWORD="your-password"

pio run -t upload \
  -DSERVER_HOST=192.168.1.112 \
  -DDEVICE_API_KEY="<the key you just issued>"
```

`platformio.ini` points `src_dir` at the sketch folder, so this compiles exactly
the same sources. `WIFI_SSID` and `WIFI_PASSWORD` come from the environment; the
`-D` flags override the defaults in `config.h` without editing it.

## Wiring

### Servo, buzzer, and safe calibration

The actuator pins are fixed by default: servo PWM is **GPIO18**, and GPIO19
drives the **gate/base of a buzzer driver**, not the buzzer itself. Power the
servo from its own regulated 5-6 V supply and connect that supply's GND to the
ESP32 GND. A passive buzzer receives a 2 kHz PWM tone on GPIO19 by default;
set `BUZZER_FREQUENCY_HZ` if its sound is too quiet. A high-current buzzer
still needs a transistor/MOSFET driver and its rated supply.

`config.h` exposes `SERVO_RELEASE_ANGLE`, `SERVO_LOCK_ANGLE`, warning timing,
and beep duration. Start with the servo horn disconnected, then adjust those
two angles for the actual linkage. To test the horn/linkage, set these in the
gitignored `secrets.h` temporarily:

```c
#define ACTUATOR_CALIBRATION_MODE 1
#define CALIBRATION_SERVO_ANGLE 45
#define CALIBRATION_TEST_BUZZER 1
```

Only perform calibration while the bicycle is stationary with its wheel lifted.
The firmware ignores automatic lock/release commands in that mode. Keep the
normal mechanical brake usable throughout calibration; set calibration back to
`0` before a rider uses the bicycle.

In normal mode, `active` releases the servo, the configured warning window
beeps without moving it, `expired` locks it, and a missing/settled rental
releases it. Failed API polls preserve the last servo position.

The PCF8574 backpack on the LCD is I2C only, so four wires:

| LCD | ESP32 | Note |
| --- | --- | --- |
| `GND` | `GND` | |
| `VCC` | `5V` | 5V on the ESP32 VIN, not 3V3 |
| `SDA` | `GPIO21` | default `I2C_SDA_PIN` |
| `SCL` | `GPIO22` | default `I2C_SCL_PIN` |

If the screen stays blank, the backpack address is usually `0x3F` rather than
`0x27`. An I2C scanner sketch will confirm it, then set `I2C_ADDRESS` to `0x3F`
in `config.h`.

## Server side: issuing the device key

You need one before you can fill in `DEVICE_API_KEY`. Run from the project root:

```bash
php artisan devices:provision-key 1              # by bicycle id
php artisan devices:provision-key BIKE-7A3F2C   # or by serial number
```

Each bicycle authenticates with its own key, so a stolen unit can only read its
own rental and a key can be revoked without touching the rest of the fleet.

The key is printed once. Only a SHA-256 digest is stored, so it cannot be
retrieved later. If it is lost, rotate it; the old unit stops working
immediately:

```bash
php artisan devices:provision-key 1 --rotate
php artisan devices:revoke-key 1                 # lost or stolen unit
```

Behind TLS, set `USE_HTTPS 1` and either a `SERVER_FINGERPRINT` or accept any
certificate. The endpoint is authenticated per bicycle either way, but plain
HTTP puts the key and the rider's name on the wire in clear text, which matters
anywhere but a bench.

## What the display shows

| Screen | Row 1 | Row 2 |
| --- | --- | --- |
| Idle bicycle | `PEDALYA` | `READY TO RIDE` |
| Rented, before the deadline | `RIDE 00:12:35` | `LEFT 14:32:09` |
| Rented, past the deadline | `RIDE 00:12:35` | `LATE PHP250.00` |
| No deadline set | `RIDE 00:12:35` | `NO DEADLINE` |
| Cannot reach the server | `NO SERVER` | `http 401` |
| WiFi down | `PEDALYA` | `connecting...` |

Row 1 is always time on the clock. Row 2 is the countdown, and switches to the
running penalty the moment the deadline passes.

Which deadline the countdown targets follows the rental lifecycle. While a
rental is `active` no administrator deadline exists yet, so it counts down to
the expected end time the rider paid for. Once an administrator sets a return
deadline, the countdown retargets to that and the penalty rule issued with it
takes over. A `http 401` on screen almost always means the key belongs to a
different bicycle, or was rotated without reflashing the unit.

## Clock handling

The ESP32 has no battery-backed RTC, so it would drift badly over a long rental.
Instead the server sends its own clock with every response:

```json
"clock": { "unix": 1790409479, "utcOffsetSeconds": 28800, "timezone": "Asia/Manila" }
```

The device records `unix` at the moment of the poll and advances from
`millis()` afterwards, so the countdown keeps ticking smoothly even if the next
poll is late or WiFi drops. Every poll re-bases it, which means clock error
never accumulates beyond one poll interval. SNTP is attempted as well, purely
to make the serial log timestamps meaningful; the display does not depend on it.

## Penalty figure

Between polls the device projects the penalty locally so the amount visibly
climbs each second, mirroring `RentalService` exactly: whole minutes first, then
rounded up to whole hours for per-hour rules, minimum one hour. This is a
display convenience. The next poll replaces it with the server's own
calculation, so a projected total can never become the total that is charged.

## API

```
GET /api/iot/bicycle/{bicycle}/rental
X-API-Key: <per-bicycle device key>
```

The key must belong to `{bicycle}`. A key issued to another bicycle is rejected
as invalid. Rate limited to 120 requests per minute.

```json
{
  "data": {
    "bicycle": { "id": 1, "serialNumber": "BIKE-7A3F2C", "status": "rented",
                 "lockStatus": "unlocked", "batteryLevel": 90 },
    "rental": {
      "rentalId": "REN-20260926-8C4B",
      "status": "overdue_return",
      "riderName": "Juan Dela Cruz",
      "startTimestamp": 1790402279,
      "returnDeadlineTimestamp": 1790410979,
      "deadlineBasis": "return_deadline",
      "elapsedSeconds": 7200,
      "remainingSeconds": 1500,
      "overdueSeconds": 0,
      "isOverdue": false,
      "progressPercent": 82,
      "ratePerHour": 15,
      "projectedBaseFee": 30,
      "penalty": { "accrued": 0, "basis": "return_deadline", "rule": "per_minute",
                   "ruleValue": 10, "isDefaultRate": false, "projectedTotal": 30 }
    },
    "clock": { "serverTime": "2026-09-26T15:57:59+08:00", "unix": 1790409479,
               "utcOffsetSeconds": 28800, "timezone": "Asia/Manila" },
    "pollAfterSeconds": 15
  }
}
```

`rental` is `null` for an idle bicycle, which is a normal state rather than an
error. `returnDeadlineTimestamp` is `0` if the rental has no deadline of any
kind. Status transitions (`expired`, `overdue_return`) are applied before the
response is built, so a deadline that passes between scheduler runs shows up on
the display straight away.
