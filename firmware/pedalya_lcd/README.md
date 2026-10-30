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
    pedalya_lcd.ino         wiring and loop() only
    config.h                server, pins, and polling settings
    secrets.h               live WiFi password and device key (gitignored)
    secrets_example.h       committed template for secrets.h
    BuzzerAlarm.h/.cpp      every sound; the only writer of BUZZER_PIN
    BuzzerCountdown.h       the accelerating beep pattern
    LockActuator.h/.cpp     the servo on SERVO_PIN
    RentalTimer.h/.cpp      overdue clock, return grace, projected penalty
    RentalController.h/.cpp expiry state machine and the actuator safety rule
    CommandHandler.h/.cpp   the server command queue
    LcdDisplay.h/.cpp       16x2 rendering
    GpsReceiver.h           checksummed NMEA RMC GPS reader
    Sim7600.h/.cpp          SIM7600CE modem on UART2, the cellular fallback
    PedalyaApi.h/.cpp       WiFi + cellular HTTP, JSON, clock
    Diagnostics.h/.cpp      the boot banner
```

### One part per tab

Each module owns one piece of hardware or one decision, and the sketch only
wires them together:

| Tab | Owns |
| --- | --- |
| `LcdDisplay` | the screen, including the whole expired sequence |
| `LockActuator` | the servo, and the only code that drives `SERVO_PIN` |
| `BuzzerAlarm` | the alarm shapes, and the only code that drives `BUZZER_PIN` |
| `RentalTimer` | seconds since expiry, the free window, the projected penalty |
| `RentalController` | the only place expiry is decided, and the safety rule |
| `CommandHandler` | the server queue; a command is a request, never a decision |
| `GpsReceiver` | the NMEA sentence reader |
| `Sim7600` | the SIM7600CE modem on UART2: a second route to the API and nothing else |
| `PedalyaApi` | the network and the JSON; Wi-Fi first, SIM7600 as the fallback |
| `Diagnostics` | the boot banner, including API-key presence checks that never reveal the key |

The Arduino IDE shows every one of these as its own tab next to
`pedalya_lcd.ino`. PlatformIO builds the same files from the same folder.

Single-part sketches live one level up in `firmware/tests/`, each its own sketch
folder so one part can be flashed on its own:

| Sketch | Answers |
| --- | --- |
| `firmware/tests/lcd_test` | is the screen, the backpack address or the wiring at fault? |
| `firmware/tests/buzzer_test` | what do the two alarm shapes sound like, at the real timings? |
| `firmware/tests/lock_stroke_test` | does the expiry stroke move the real linkage safely? |

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

The boot banner confirms whether `DEVICE_API_KEY` and the fleet-wide
`PEDALYA_API_KEY` are configured and whether the `X-API-Key` request header is
configured. It never prints any part of either key.

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
| `DEVICE_API_KEY` | the key from step 1 below; authenticates `/rental`, `/commands` and `/gps` |
| `PEDALYA_API_KEY` | the fleet key for `/iot/heartbeat` and `/iot/accident-report`: the backend's own `DEVICE_API_KEY` value from `.env`, not a per-bicycle key |
| `SIM_APN` | the carrier APN of the SIM in the SIM7600 modem; only needed for the cellular fallback. Ask the carrier for the value, never guess one |

Everything else stays in `config.h`:

| Setting | What it is |
| --- | --- |
| `SERVER_HOST` | your machine's LAN IP, where Laravel runs |
| `SERVER_PORT` | `8000` for `php artisan serve`, or `443` with `USE_HTTPS 1` |
| `SERVER_PATH` | `/api/iot/bicycle/1/rental` for this bicycle |
| `HEARTBEAT_PATH` | `/api/iot/heartbeat` — fleet-wide, so it authenticates with `PEDALYA_API_KEY` |
| `GPS_PATH` | `/api/iot/bicycle/1/gps` for this bicycle |
| `I2C_ADDRESS` | `0x27`, or `0x3F` if the screen stays blank |
| `GRADUAL_LOCK_SECONDS` | how long before the deadline the servo starts closing (10 s) |
| `GEOFENCE_CENTER_LAT` / `GEOFENCE_CENTER_LNG` / `GEOFENCE_RADIUS_M` / `GEOFENCE_NEAR_MARGIN_M` | the riding zone the gradual lock is judged against: inside/near it the lock is graduated, outside it is immediate |

Save the file. Only `secrets.h` is ever edited per unit; the rest of the sketch
is shared.

### Temporary Wi-Fi API health test

`config.h` defaults to `CONNECTIVITY_MODE_WIFI_HEALTH_TEST`. This mode joins
the configured Wi-Fi network and sends only an unauthenticated, certificate-
verified HTTPS `GET https://nfdfn4j8-8000.asse.devtunnels.ms:443/api/health`.
It does not poll rental state, process server commands, or send heartbeat, GPS,
or accident requests. The Serial Monitor reports Wi-Fi association, HTTP status,
and health-response success separately.

For the EC20F/SIM deployment, change `CONNECTIVITY_MODE` in `config.h` to
`CONNECTIVITY_MODE_EC20F_SIM`. `SIM_ONLY_MODE` remains independently
configurable and keeps its existing SIM-only behavior.

### 6. Upload

Click the **right-arrow (Upload)** button. First compile is slow, and the IDE
prints the library paths and sketch size as it goes. A successful run ends with
`Hard resetting via RTS pin...`.

### 7. Watch it boot

**Tools → Serial Monitor**, **115200 baud**. Expect:

```
[cfg] server  http://192.168.1.112:8000/api/iot/bicycle/1/rental
[api] API key configured: YES
[api] fleet key configured: YES (heartbeat, accident-report)
[api] API key header configured: YES (X-API-Key)
[cfg] gps     UART1 (GPIO25, GPIO26)
[cfg] EC20F UART2 (GPIO16, GPIO17) APN: <not set>
[cfg] display I2C 0x27  SDA 21  SCL 22
[wifi] connecting to "PLDTHOMEFIBRZ47rS"
WiFi connected!
ESP32 IP address: 192.168.1.115
Gateway: 192.168.1.1
Subnet: 255.255.255.0
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
| `wifi down` | — | not associated with the access point, and no modem connected |
| `link down` | `cannot reach ... over the SIM7600 link` | a modem is present and answering, but no data link yet: `SIM_APN` unset or wrong, the SIM has no data plan, or no coverage |
| `no api key` | `DEVICE_API_KEY is empty` / `the API key for this endpoint is empty` | `secrets.h` missing or the key for that endpoint blank |
| `connect failed` | `cannot reach 192.168.1.112:8000` | PC unreachable: wrong subnet, `php artisan serve` still bound to `127.0.0.1`, or Windows Firewall blocking inbound TCP 8000 |
| `auth 401` | `server said: Invalid API key` | the key sent is not the one that endpoint expects: `SERVER_PATH`, `/commands` and `/gps` take the bicycle's `DEVICE_API_KEY`, while `/heartbeat` and `/accident-report` take the fleet `PEDALYA_API_KEY` |
| `auth 403` | `403 Forbidden` | key authenticated but not permitted |
| `404 not found` | `404 Not Found` | `SERVER_PATH` does not resolve, or the bicycle id does not exist. Not a key problem |
| `rate limited` | `429` | polling faster than `throttle:120,1` allows |
| `server error` | `500 from Laravel` | authenticated, but Laravel itself failed |

Two backend details worth knowing:

- The key must be the one issued **for the bicycle in `SERVER_PATH`**. A
  perfectly valid key for another bicycle is rejected, because that would let
  one unit read another unit's renter.
- **Two credentials, not one.** `/iot/heartbeat` and `/iot/accident-report` sit
  behind the fleet-wide device auth and only accept the backend's shared
  `DEVICE_API_KEY` (the `services.device_api_key` value in `.env`), which is
  what `PEDALYA_API_KEY` in `secrets.h` must hold. Sending the bicycle key
  there is rejected exactly like a wrong one.
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

The prototype uses servo PWM on **GPIO18** (`100°` released, `0°` locked) and an
active HIGH/LOW buzzer output on **GPIO19**. Power the servo from its own
regulated 5-6 V supply and connect that supply's GND to ESP32 GND. Use a
transistor/MOSFET driver if the buzzer current exceeds the GPIO rating.

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

In normal mode, `active` releases the servo. Inside the last
`GRADUAL_LOCK_SECONDS` (10 s) the behaviour splits on the geofence: while the
bicycle is inside or near the configured zone the servo walks from `100°`
toward `0°` in step with the countdown, and while it is outside the wheel is
locked outright. The configured warning window stays silent, `expired` locks it
(from wherever the servo already stands, so a graduated wheel is not swept back
out), and `awaiting_return` locks it without an expiry alarm. With no live
rental, the servo follows the bicycle's recorded `lockStatus`. Failed API polls
preserve the last servo position. On boot the servo waits for a successful
authenticated rental poll before it moves.

The zone is the backend's active `geofences` circle, mirrored into `config.h`
as `GEOFENCE_CENTER_LAT`, `GEOFENCE_CENTER_LNG`, `GEOFENCE_RADIUS_M` and
`GEOFENCE_NEAR_MARGIN_M` (override them in `secrets.h` if the zone moves).
Position comes from the live GPS fix; without a fresh fix the status is
`UNKNOWN` and the gradual lock is kept rather than forced. Every servo command
goes through a state-change guard, so the pin is only written when the target
angle changes and the serial log reports each real move once:

```
[SAFETY] GRADUAL LOCK remaining=9s boundary=Inside angle=90 -> SERVO.write(90)
[SAFETY] GRADUAL LOCK remaining=4s boundary=Near angle=40 -> SERVO.write(40)
[SAFETY] FORCED LOCK remaining=7s boundary=Outside angle=0 -> SERVO.write(0)
[SAFETY] EXPIRED -> SERVO.write(0)
```

### The two alarm sounds

The buzzer only ever sounds after a rental has actually expired, and it uses two
shapes so a rider can tell "your time is up" from "come back to the station"
without looking at the LCD:

| When | Sound | Meaning |
| --- | --- | --- |
| The instant time runs out | flat, unbroken tone for `EXPIRED_FLATLINE_MS` (4 s) | the rental expired |
| The free return window that follows, `RETURN_GRACE_SECONDS` (5 min) | separate beeps that speed up as the window runs out | return the bicycle, still no charge |
| The window lapses | flat, unbroken tone, held | the grace is gone and penalties apply |

The countdown gap shrinks linearly from `RETURN_COUNTDOWN_GAP_START_MS` (1.8 s,
one beep every ~2 s with the full window left) down to
`RETURN_COUNTDOWN_GAP_END_MS` (0.25 s, one every ~0.45 s on the final second),
so the pattern itself counts the window down. Each beep lasts
`RETURN_COUNTDOWN_TONE_MS`. Return the bicycle and the beeps stop with it.

Two deliberate limits:

- The countdown is only ever played while the link to the server is up. During a
  network outage the flatline is held instead, because an alarm that is mostly
  silent is least acceptable exactly when the connection is unreliable.
- A `buzzer` command from the server cannot start a sound before expiry, and
  cannot choose a different shape afterwards: it goes through the same decision
  as the timer does.

Set `RETURN_COUNTDOWN_ENABLED 0` in `secrets.h` to go back to one continuous
flatline for the whole expired state.

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

### GPS, SIM7600 and device telemetry

The controller reads checksummed NMEA RMC sentences on UART1 at 9600 baud. The
NEO-6M module's TXD feeds ESP32 RX **GPIO25**, and ESP32 TX **GPIO26** drives
the module's RXD (with common GND, power at 3V3):

| GPS module | ESP32 | Note |
| --- | --- | --- |
| `VCC` | `3V3` | |
| `GND` | `GND` | common ground |
| `TXD` | `GPIO25` | `GPS_RX_PIN` (ESP32 UART RX) |
| `RXD` | `GPIO26` | `GPS_TX_PIN` (ESP32 UART TX) |

It uploads a valid fix every 15 seconds to `GPS_PATH`; no GPS fix means no
location request. A fleet-authenticated heartbeat reports the commanded lock
state and uptime at the rental polling interval. `GPS_PATH` (with `SERVER_PATH`
and `/commands`) carries the bicycle's own `DEVICE_API_KEY`; the heartbeat and
the accident report carry the fleet `PEDALYA_API_KEY` instead.

The GPS endpoint records the fix in the existing `gps_logs` table and runs the
existing geofence checks. The heartbeat reports lock state only when the servo
is attached; this prototype has no lock-position feedback sensor.

Every accepted RMC sentence prints one `[GPS] Latitude: ...` / `[GPS]
Longitude: ...` pair (~1 Hz). Moving GPS off GPIO2 is deliberate: that pin is
also an ESP32 boot strapping pin, and TX GPIO4 was hiding under the LCD's I2C
address scan on some benches.

#### Cellular fallback (EC20F)

The EC20F is only a modem: the ESP32 keeps every Pedalya decision, every
endpoint, the API key and all JSON handling, and the SIM link is a second route
to the same server used whenever Wi-Fi is not associated. It is wired on UART2
and must not use GPIO1/GPIO3, which the USB-UART0 monitor owns:

| EC20F | ESP32 | Note |
| --- | --- | --- |
| `VCC` | regulated 3.7-4.2V | a supply sized for GSM bursts (peaks of ~2 A); not the ESP32 3V3 pin |
| `GND` | `GND` | common ground |
| `TXD` | `GPIO16` | `SIM_RX_PIN` (ESP32 UART2 RX) |
| `RXD` | `GPIO17` | `SIM_TX_PIN` (ESP32 UART2 TX) |

At boot the sketch probes the modem (`simInit`), and while Wi-Fi is down the
loop retries `simConnectInternet(SIM_APN)` every `SIM_RETRY_INTERVAL_MS`. A
working SIM link makes `connected()` true and every request — poll, commands,
heartbeat, GPS upload, accident report — automatically goes out over cellular in
the exact shape it would have over Wi-Fi. Wi-Fi always wins when associated, so
a bike in the shop keeps using the LAN.

Bring-up needs two things in `secrets.h`:

- `SIM_APN` — the carrier's access point name for the SIM in the modem. Ask the
  carrier rather than guessing; the placeholder is refused on purpose so an
  unconfigured unit fails loudly.
- `SERVER_HOST` must be reachable over the mobile network. The default
  `192.168.1.112` is a LAN address a SIM can never route to, so cellular testing
  needs a public hostname. Plain `http://` is used on the SIM path (the modem's
  HTTP stack cannot verify the TLS fingerprint), matching the existing
  `USE_HTTPS 0` stance; a `https://` URL is refused with a `[SIM]` log line.

Serial diagnostics use the `[SIM]` prefix and the request header (`X-API-Key`)
is never printed in the clear: `simSendCommand` logs every AT command, the boot
banner shows the APN only as `<not set>` while the placeholder is in place, and
HTTP results print as one `[SIM] GET <url> -> 200 (1234 bytes)` line per request.

Every AT exchange is bounded by a `SIM_*` timeout in `config.h`, so a missing or
dead modem costs one bounded wait instead of parking the main loop: the worst
bearer attempt is a few seconds every `SIM_RETRY_INTERVAL_MS`, comparable to the
existing HTTP timeouts.

## Server side: issuing the device key

You need one before you can fill in `DEVICE_API_KEY`. Run from the project root:

```bash
php artisan devices:provision-key 1              # by bicycle id
php artisan devices:provision-key BIKE-7A3F2C   # or by serial number
```

Each bicycle authenticates with its own key, so a stolen unit can only read its
own rental and a key can be revoked without touching the rest of the fleet.

That key covers `SERVER_PATH`, `/commands` and `/gps` only. The heartbeat and
accident report are fleet-wide routes and instead take the shared key from the
backend's `.env` (`DEVICE_API_KEY` → `services.device_api_key`). Copy that value
into `PEDALYA_API_KEY` in `secrets.h`; it is not issued per bicycle and
`devices:provision-key` never changes it.

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
| Last `EXPIRY_WARNING_SECONDS` before expiry | `RENTAL ENDING` | `LEFT 00:05` |
| First 3 seconds after expiry | `RENTAL EXPIRED` | `LOCK ENGAGED` |
| Return grace, counting down | `RETURN BICYCLE` | `RETURN IN 04:57` |
| Overdue, counting up | `RETURN BICYCLE` | `OVERDUE 00:01` |
| Overdue, alternating every 2s | `RETURN BICYCLE` | `PENALTY P30` |
| Cannot reach the server | `NO SERVER` | `http 401` |
| No link (Wi-Fi or cellular) | `PEDALYA` | `connecting...` |

Row 1 is always time on the clock. Row 2 is the countdown, and switches to the
running penalty the moment the deadline passes.

Once the deadline passes the rider is given `RETURN_GRACE_SECONDS` (five minutes
by default) to get the bicycle back to the station, and that window has its own
screen because it is the only part of the expired state where returning is still
free. `RETURN IN mm:ss` counts down to `00:00` and is the number the rider
watches; it does not alternate, because a single falling figure is what makes
the remaining time legible at a glance.

After the grace expires the screen alternates `OVERDUE mm:ss` with
`PENALTY Pxx` every two seconds, as a 16x2 display cannot hold both at once.
That overdue counter starts at `00:01`, measured from the end of the grace
rather than from the deadline, so the first penalised second reads as one second
late instead of jumping straight to five minutes. It keeps counting up for as
long as the rental is open, and the peso figure steps up every
`PENALTY_INTERVAL_SECONDS` in step with `updateOverdueTimer()`.

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
