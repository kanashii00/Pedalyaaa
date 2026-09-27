# Pedalya IoT Bicycle — Hardware Connection Diagram & Bench Simulation

**Revision:** 1.0
**Applies to:** Pedalya Laravel rental system, ESP32 DevKit V1 on-board controller
**Audience:** BSIT prototype assembly

---

## 0. Read this before powering anything

Six mistakes below will destroy hardware on first power-up. They are numbered
`⚠️ W1` … `⚠️ W6` and repeated next to the diagram where they apply.

| # | Warning | Why |
| --- | --- | --- |
| ⚠️ W1 | **Never connect 7.4 V to the ESP32 5V/VIN pin, and never to any GPIO.** | The DevKit V1's AMS1117-3.3 regulator is rated ~15 V but is only specified up to ~6 V input. Over 6 V it overheats and dies. |
| ⚠️ W2 | **Never power the 180° metal-gear servo from the ESP32 3.3 V pin.** | Stall current is 1.2–2.5 A. The ESP32's 3.3 V regulator (AMS1117) collapses around 300 mA and resets the chip. |
| ⚠️ W3 | **Never power the SIM7600 from the ESP32 3.3 V pin, and never share one regulator with it.** | LTE transmit bursts pull ~2 A for 100–500 ms. A shared regulator browns out mid-burst and the modem desynchronises from the network. It needs its own rail and a bulk capacitor. |
| ⚠️ W4 | **5 V signals must never touch an ESP32 GPIO.** | ESP32 GPIOs are 3.3 V and **not** 5 V tolerant. Feeding 5 V forward-biases the internal clamp diode and permanently damages the pin. This applies to the LCD backpack and the A3144 module if powered at 5 V. |
| ⚠️ W5 | **Check battery polarity before the first connection.** | 2S Li-ion at 8.4 V will instantly destroy any device wired in reverse. |
| ⚠️ W6 | **High-current power wiring must be physically separate from signal wiring.** | Servo and LTE burst currents induce noise that shows up as false accelerometer spikes, GPS corruption, and ESP32 brownouts. Route them apart and bond grounds at a single star point. |

---

## 1. System architecture

```
                        ┌────────────────────────────────┐
                        │      CLOUD / BACK-END         │
                        │                                │
                        │   Pedalya Laravel API  :80    │
                        │        │                       │
                        │   MySQL Database               │
                        │        │                       │
                        │   Admin Dashboard (web)        │
                        └────────▲───────────────────────┘
                                 │  HTTPS  JSON
                                 │  GET /api/iot/bicycle/{id}/rental
                                 │  POST /api/iot/heartbeat
                                 │  POST /api/iot/accident-report
                                 │
        ┌────────────────────────┴──────────────────────────┐
        │                   INTERNET (4G / LTE)              │
        └────────────────────────┬──────────────────────────┘
                                 │  AT commands over UART
                    ┌────────────┴────────────┐
                    │      SIM7600 4G/LTE     │
                    │   module + antennas     │
                    │  ⚠️ W3  own power rail   │
                    └────────────┬────────────┘
                                 │  UART2  (GPIO25 RX / GPIO26 TX)
                                 │  ⚠️ level shifting required, see §7.7
   ┌─────────────┐               │               ┌──────────────────┐
   │  NEO-6M GPS │               │               │                  │
   │  + antenna  │               │               │   ESP32 DevKit   │
   └──────┬──────┘               │               │        V1        │
          │  UART1 (GPIO16/17)   │               │                  │
          └───────────────────────┼──────────────▶│  master          │
                                  │               │  controller      │
   ┌─────────────┐               │               │                  │
   │  MPU6050    │ I2C ──────────┼──────────────▶│  GPIO21 SDA      │
   │  GY-521     │ I2C ──────────┼──────────────▶│  GPIO22 SCL      │
   │  accel+gyro │               │               │                  │
   └─────────────┘               │               │                  │
                                  │               │                  │
   ┌─────────────┐               │               │                  │
   │  A3144 Hall │ D0  ──────────┼──────────────▶│  GPIO27          │
   │  wheel RPM  │ A0  ──── ✗ unused              │                  │
   └─────────────┘               │               │                  │
                                  │               │                  │
   ┌─────────────┐               │               │                  │
   │ 16x2 LCD    │ I2C ──────────┼──────────────▶│  GPIO21 / 22     │
   │ + I2C bkpt  │               │               │  ⚠️ W4 verify    │
   └─────────────┘               │               └──────────────────┘
                                  │                       │
   ┌─────────────┐               │                       ├──▶ Buzzer
   │ Active      │ ──────────────┼──────────────────────▶│    GPIO19
   │ buzzer      │               │                       │
   └─────────────┘               │                       ├──▶ Servo 180°
                                  │                       │    GPIO18
                                  │                       │    ⚠️ W2 own rail
                                  │                       │         │
                                  │                       │         ▼
                                  │                       │   Mechanical
                                  │                       │   brake resistance
                                  │                       │   (cable + spring)
                                  │                       │
```

### 1.1 Data flow summary

| Source | Path | Purpose |
| --- | --- | --- |
| NEO-6M GPS | NEO-6M → ESP32 → SIM7600 → LTE → Laravel API → MySQL | Location trail, geofencing |
| MPU6050 | MPU6050 → ESP32 → accident detection → SIM7600 → Laravel API → Admin notification | Impact / crash alerts |
| A3144 | A3144 → ESP32 → wheel rotation → SIM7600 → Laravel API | Distance, speed, ride integrity |
| Server | Laravel API → SIM7600 → ESP32 → LCD | Rental time, countdown, penalty |
| Server | Laravel API → SIM7600 → ESP32 → Buzzer | Expiry warning, overdue alarm |
| Safety | ESP32 → Servo → brake resistance cable | Physical brake resistance on violation |

---

## 2. ESP32 GPIO pin assignment

```
                    ESP32 DevKit V1 — pin usage map
   ┌────────────────────────────────────────────────────────┐
   │                                                        │
   │  GPIO16  ●◄──── NEO-6M GPS  TX   (UART1 RX)            │
   │  GPIO17  ●────► NEO-6M GPS  RX   (UART1 TX)            │
   │                                                        │
   │  GPIO21  ●◄──── I2C SDA   ← MPU6050 + 16x2 LCD        │
   │  GPIO22  ●◄──── I2C SCL   ← MPU6050 + 16x2 LCD        │
   │                                                        │
   │  GPIO25  ●◄──── SIM7600 TX (UART2 RX)  ⚠️ level shift  │
   │  GPIO26  ●────► SIM7600 RX (UART2 TX)  ⚠️ level shift  │
   │                                                        │
   │  GPIO27  ●◄──── A3144 Hall D0   (digital)              │
   │                                                        │
   │  GPIO18  ●────► Servo PWM signal                       │
   │  GPIO19  ●────► Buzzer driver (via transistor)          │
   │                                                        │
   │  3V3    ●────► MPU6050 VCC, A3144 VCC, level shifter   │
   │  GND    ●────► common signal ground                     │
   │                                                        │
   │  ── NOT USED, keep free ──                              │
   │  GPIO1, GPIO3   USB / UART0 serial monitor — DO NOT    │
   │                  use, you lose your debug output       │
   │  GPIO6–GPIO11   Reserved for the SPI flash — NEVER      │
   │                  connect anything here                │
   │  GPIO34–39      Input only, no internal pull-up        │
   │  GPIO0,2,5,12,15  Strapping pins, leave alone at boot  │
   └────────────────────────────────────────────────────────┘
```

### 2.1 Pin notes

| Pin | Function | Notes |
| --- | --- | --- |
| GPIO16 | GPS RX | UART1 RX. The NEO-6M's TX is 3.3 V, so it drives the ESP32 directly. **No level shifter needed here.** |
| GPIO17 | GPS TX | UART1 TX to the NEO-6M's RX. The NEO-6M RX is 5 V tolerant, so 3.3 V out is fine. |
| GPIO21 | I2C SDA | Shared bus: MPU6050 **and** LCD, 4.7 kΩ pull-ups to 3.3 V only. |
| GPIO22 | I2C SCL | Same bus. Both devices need distinct I2C addresses (they do — MPU6050 = `0x68`, PCF8574 = `0x27`). |
| GPIO25 | SIM7600 RX | Receives the modem's TX. **See §7.7 — the bare module is 1.8 V logic.** |
| GPIO26 | SIM7600 TX | Drives the modem's RX. **Needs level shifting on most SIM7600 boards.** |
| GPIO27 | Hall D0 | A3144 is an open-collector output, so GPIO27's internal pull-up is a usable second option, but a discrete 10 kΩ to 3.3 V is more reliable. |
| GPIO18 | Servo PWM | 50 Hz servo pulse. Servo's own supply + its own ground return. |
| GPIO19 | Buzzer | Drives the base/gate of the driver transistor, not the buzzer directly. |

**A0 is intentionally left unconnected.** D0 is used for digital wheel-rotation
detection, and the A3144's analog output is left off the board entirely so it
cannot be back-powered through the A0 trimmer potentiometer.

---

## 3. Power architecture

```
   ┌──────────────────────────────────────────────────────────┐
   │  BATTERY PACK — 7.4 V nominal (2S2P 18650), BMS protected │
   │  8.4 V full · 6.0 V cutoff                              │
   │  XT60 / JST-PH connector                                  │
   └───────────────┬──────────────────────────────────────────┘
                   │  BATTERY  (+) red  →  [F1 FUSE]  (5 A slow-blow, ⚠️ W5 polarity)
                   │  BATTERY  (−) black ──────────────────────────────────┐
                   │                                                      │
                   │  ⚠️ W6  all power wiring 18–20 AWG, kept apart from   │
                   │         signal wiring                                 │
                   ▼                                                      │
          ┌─────────────────┐                                            │
          │  [SW1] MAIN     │  SPST ON/OFF, rated 10 A DC minimum         │
          │  ON/OFF SWITCH  │  (or automotive ignition-style rocker)       │
          └────────┬────────┘                                            │
                   │                                                      │
                   ▼                                                      │
   ╔═══════════════════════════════════════════════════════════════════╗  │
   ║   POWER DISTRIBUTION — 5-way screw terminal block               ║  │
   ║   (mount on perfboard, label each position)                      ║  │
   ╚═══════╤═══════════════╤════════════════╤═══════════════════════╤═══╝  │
           │               │                │                       │
           │ VIN +         │                │                       │
           │               │                │                       │
           ▼               ▼                ▼                       │
   ┌───────────────┐ ┌─────────────┐ ┌──────────────┐        ┌────────┴───┐
   │  BUCK 1       │ │  BUCK 2     │ │  BUCK 3      │        │  ALL GND  │
   │  5 V / 1 A    │ │  3.8 V / 2 A │ │  5–6 V / 3 A │        │  STAR     │
   │  (ESP32 VIN)  │ │  (SIM7600)   │ │  (SERVO)     │        │  POINT    │
   └───────┬───────┘ └──────┬──────┘ └──────┬───────┘        └───┬────┘
           │                │               │                    │
           ▼                ▼               ▼                    │
   ┌───────────────┐ ┌─────────────┐ ┌──────────────┐            │
   │ ESP32 DevKit  │ │  SIM7600    │ │  SERVO 180°  │            │
   │ VIN           │ │  VBAT / 5V  │ │  V+ signal    │            │
   │  ↓ onboard    │ │             │ │  GND (own)   │            │
   │ AMS1117-3.3   │ │ ⚠️ W3 own    │ │  ⚠️ W2 never  │            │
   │  ↓            │ │  rail +     │ │  from ESP32  │            │
   │  3.3 V rail   │ │  1000 µF    │ │              │            │
   │               │ │  bulk cap   │ │  1000 µF     │            │
   │ ├─ MPU6050    │ │             │ │  bulk cap    │            │
   │ ├─ A3144      │ │             │ │              │            │
   │ ├─ 4.7k pulls │ │             │ │              │            │
   │ └─ level shft │ │             │ │              │            │
   └───────────────┘ └─────────────┘ └──────────────┘            │
                                                              │
   ┌──────────────────────────────────────────────────────────┐│
   │  SIGNAL GROUND — 22–24 AWG                               ││
   │  ESP32 GND ─┬─ NEO-6M GND                                ││
   │             ├─ MPU6050 GND                                ││
   │             ├─ LCD GND                                    ││
   │             ├─ A3144 GND                                  ││
   │             └─ level shifter GND                          ││
   │                                                          ││
   │  POWER GROUND — 18 AWG, separate run                     ││
   │  BUCK1 GND ─┬─ BUCK2 GND                                 │◄┘
   │             └─ BUCK3 GND (servo)                         │
   └──────────────────────────────────────────────────────────┘
```

### 3.1 Power rail table

| Rail | Converter | Output | Max current | Loads | Critical notes |
| --- | --- | --- | --- | --- | --- |
| **RAW** | Battery | 7.4 V (6.0–8.4 V) | 5 A | none | Only the fuse and the ON/OFF switch. Nothing else touches this. |
| **5 V** | Buck 1 (LM2596 or MP1584) | 5.0 V | 1 A | ESP32 VIN | Feeds VIN, not the 3V3 pin. ⚠️ W1 |
| **3.3 V** | ESP32 onboard AMS1117 | 3.3 V | ~500 mA real | MPU6050, A3144, I2C pull-ups, level shifter | Do not draw the servo or modem from here. |
| **3.8 V** | Buck 2, set to 3.8 V | 3.8 V | 2.5 A | SIM7600 only | ⚠️ W3. Add 1000 µF low-ESR at the modem's VBAT pin. |
| **5–6 V** | Buck 3 (high-current, ≥3 A) | servo rating | 3 A | Servo only | ⚠️ W2. Add 1000 µF at the servo connector. Heatsink the converter. |

### 3.2 Fuse sizing

Total worst case, if the servo stalls while the modem transmits:

```
Servo stall        2.5 A
SIM7600 TX peak    2.0 A
ESP32 + sensors    0.5 A
                   ------
Total              5.0 A
```

Use a **5 A slow-blow (time-delay) blade fuse**. It survives the servo/LTE
inrush that would blow a fast-acting fuse, but still opens on a genuine short.
If your servo's datasheet stall current exceeds 2.5 A, step up to 7.5 A and
match the wire gauge. Mount the fuse **on the positive lead, as close to the
battery as possible**, inside the project box.

### 3.3 Battery and charger

| Item | Spec |
| --- | --- |
| Battery | 2S2P 18650, 7.4 V nominal, 8.4 V full, with a protection/BMS board |
| Charger | 8.4 V CC/CV Li-ion, ≥2 A. **Never charge a 2S pack from a 12 V supply.** |
| Charge indicator | Some BMS boards have an LED. Do not leave the pack charging unattended overnight. |

---

## 4. Breadboard layout (bench prototyping only)

The large breadboard carries the ESP32 and every low-power module. The servo,
the SIM7600 and the battery stay off it.

```
   ╔═══════════════════════════════════════════════════════════════════════════════╗
   ║  LARGE BREADBOARD  —  signal side only,  NEVER high current                   ║
   ╟───────────────────────────────────────────────────────────────────────────────╢
   ║  ┌────────┐                                    ┌────────┐  ┌────────┐        ║
   ║  │ ESP32  │                                    │NEO-6M  │  │MPU6050 │        ║
   ║  │DevKit  │                                    │  GPS   │  │ GY-521 │        ║
   ║  │  V1    │                                    │        │  │        │        ║
   ║  │        │  16 17 21 22 25 26 27 18 19       │ TX RX  │  │SDA SCL │        ║
   ║  └────────┘                                    └───┬────┘  └───┬────┘        ║
   ║                                                    │           │             ║
   ║  ── red rail  ─────────────────────────────────────┴───────────┴──────●      ║
   ║       (3.3 V from ESP32 3V3 pin, only for pull-ups and level shifter)       ║
   ║                                                                               ║
   ║  ── blue rail ──────────────────────────────────────────────────────────●     ║
   ║       (common GND)                                                        │     ║
   ║  ┌────────┐   ┌────────┐   ┌──────────────┐   ┌─────────┐          │     ║
   ║  │ 16x2   │   │ A3144  │   │  BSS138 or   │   │ Buzzer  │          │     ║
   ║  │  LCD   │   │  Hall  │   │ TXS0108E    │   │driver   │          │     ║
   ║  │ I2C bkp│   │ module │   │ level shifter│   │ N-MOS   │          │     ║
   ║  └────────┘   └────────┘   └──────────────┘   └─────────┘          │     ║
   ╚═══════════════════════════════════════════════════════════════════════╝
           │                                                        │
           │  NOT on the breadboard:                                │
           │   • 7.4 V battery   • 3.8 V buck 2 (SIM7600)          │
           │   • 5–6 V buck 3 (servo)   • 180° servo                │
           │   • SIM7600 module          • fuse / switch / terminal block
```

### 4.1 Breadboard rules

| Do | Do not |
| --- | --- |
| Use 22–24 AWG for every signal jumper. | Never run servo or battery current through breadboard rails. Breadboard contacts are rated ~1 A and the rails degrade fast under surge. |
| Use the blue rail for common signal GND. | Never tie the servo ground return to the signal ground rail at the breadboard. Bond them at the terminal block star point only. |
| Put 4.7 kΩ pull-ups on SDA/SCL to the **3.3 V** red rail. | Never use the 5 V rail for I2C pull-ups. That is ⚠️ W4. |
| Add 100 nF decoupling at every module's VCC/GND. | Never leave the ESP32's VIN floating while USB is plugged in. Choose one power source. |
| Label jumper colours consistently. | Never mix JST-XH (low current) into a servo or battery run. Use XT60/JST-PH there. |

---

## 5. Interconnect list

Every wire, grouped by subsystem. This is the authoritative list for assembly.

### 5.1 NEO-6M GPS module

| NEO-6M pin | Connects to | Wire | Note |
| --- | --- | --- | --- |
| `VCC` | 3.3 V rail | 22–24 AWG | ⚠️ If your module has a board-mounted AMS1117 you may feed 5 V, but **3.3 V is always correct and preferred.** |
| `GND` | GND rail | 22–24 AWG | |
| `TXD` | ESP32 **GPIO16** | 22–24 AWG | 3.3 V logic, direct, no shifter |
| `RXD` | ESP32 **GPIO17** | 22–24 AWG | 3.3 V out, module RX is tolerant |
| Antenna | SMA screw-on | — | Mount on the handlebar stem, routed away from the LTE antennas. **A magnet-mount antenna on a rotating frame will lose lock** — use a fixed mount. |

### 5.2 MPU6050 / GY-521

| Pin | Connects to | Wire | Note |
| --- | --- | --- | --- |
| `VCC` | 3.3 V rail | 22–24 AWG | GY-521 has an onboard regulator, so 5 V also works, but 3.3 V is safest |
| `GND` | GND rail | 22–24 AWG | |
| `SDA` | ESP32 **GPIO21** | 22–24 AWG | I2C, I²C address `0x68` |
| `SCL` | ESP32 **GPIO22** | 22–24 AWG | I2C clock |
| `XDA` / `XCL` | leave open | — | Only for the auxiliary bus. Not needed. |

### 5.3 16×2 LCD with I2C backpack

| Backpack pin | Connects to | Wire | Note |
| --- | --- | --- | --- |
| `VCC` | 3.3 V rail **or** 5 V + level shifter | 22–24 AWG | **Read §5.3.1 before wiring** |
| `GND` | GND rail | 22–24 AWG | |
| `SDA` | ESP32 **GPIO21** | 22–24 AWG | Shared bus with the MPU6050 |
| `SCL` | ESP32 **GPIO22** | 22–24 AWG | Shared bus with the MPU6050 |
| Contrast pot | leave as fitted | — | Rotate with a small screwdriver for legibility |

#### 5.3.1 ⚠️ Backpack logic level — required check

This is the one connection most likely to destroy an ESP32.

A typical PCF8574 backpack has **4.7 kΩ pull-up resistors on SDA and SCL tied
to the backpack's VCC**. Power the backpack at 5 V and those pull-ups drag SDA
and SCL up to 5 V. ESP32 GPIO21 and GPIO22 are 3.3 V and not 5 V tolerant, so
the pins sit above their absolute maximum continuously.

Pick one of these, and only one:

| Option | Wiring | Pros | Cons |
| --- | --- | --- | --- |
| **A — power at 3.3 V** *(simplest, recommended for a prototype)* | `VCC → 3.3 V rail` | One wire, no extra parts, safe by construction | HD44780 is specified at 5 V; contrast may be weak. If characters look faint, fine-turn the contrast pot, or feed 5 V to the LCD's own VCC pins directly while keeping the PCF8574 on 3.3 V. |
| **B — 5 V + level shifter** | `VCC → 5 V`, `SDA/SCL → BSS138 bidirectional shifter → ESP32` | Full 5 V display drive, sharp contrast | One extra module, one more thing to get wrong |

Do **not** power the backpack at 5 V and connect SDA/SCL straight to the ESP32.

Whichever option you choose, verify before power-up: put a multimeter on the SDA
and SCL rails with the ESP32 disconnected. If either reads above 3.5 V, the
wiring is wrong.

### 5.4 A3144 Hall-effect sensor module

| Pin | Connects to | Wire | Note |
| --- | --- | --- | --- |
| `VCC` | 3.3 V rail | 22–24 AWG | ⚠️ W4. The module's onboard 10 kΩ pull-up goes to VCC, so a 5 V supply pushes D0 to 5 V and damages GPIO27. **3.3 V only.** |
| `GND` | GND rail | 22–24 AWG | |
| `D0` | ESP32 **GPIO27** | 22–24 AWG | Digital pulse per magnet pass. Add a 10 kΩ pull-up to 3.3 V for a clean edge. |
| `A0` | **leave disconnected** | — | Intentionally unused, per spec. Tape the pin or shrink-wrap it so it cannot short to something. |

#### 5.4.1 ⚠️ Gap in the current BOM: the A3144 has no magnet

A3144 switches at roughly **44 gauss**. That is a firm magnetic field, and the
bill of materials for this revision **excludes the neodymium magnet**. As
specified, GPIO27 will sit at its pull-up level forever and wheel-rotation
monitoring, speed, and distance will all read zero.

This is a design gap, not a wiring error. Before you can validate the ride-
monitoring path you need one of:

1. **Add the magnet back** — a small 10 mm × 2 mm neodymium disc bonded to a
   wheel spoke, positioned so the A3144's marked face passes within 2–3 mm.
   This is the intended design and the only option that survives a real ride.
2. **Substitute a reed switch + magnet** for prototyping only. Cheaper, but
   it is mechanical, it fatigues, and it will not survive a BSIT demonstration
   on a moving bicycle.
3. **Substitute a photo-interrupter** (IR break-beam) for bench simulation
   only. Pass a card through the slot to generate pulses. Genuinely useful for
   testing the firmware's RPM maths without a magnet.

For §9 bench simulation, plan on option 3 so you can prove the firmware works
before committing a magnet to the build.

### 5.5 Active buzzer

| Item | Connects to | Wire | Note |
| --- | --- | --- | --- |
| Buzzer `+` | 3.3 V rail (or 5 V if the buzzer is rated 5 V) | 22–24 AWG | |
| Buzzer `−` | driver transistor drain | 22–24 AWG | |
| Driver `gate` | ESP32 **GPIO19** | 22–24 AWG | |
| Driver `gate` resistor | 100 Ω–1 kΩ in series, gate to GPIO19 | 22–24 AWG | Limits GPIO current |
| Driver gate pulldown | 100 kΩ gate to GND | 22–24 AWG | Holds the buzzer off while the ESP32 boots |
| Driver source | GND rail | 22–24 AWG | |

**Why a driver is mandatory:** a bare active buzzer draws 20–30 mA, above the
~12 mA an ESP32 GPIO should source continuously. A 2N7002 or AO3400 N-channel
MOSFET on the breadboard handles 200 mA+ with almost no drop, and costs nothing.

### 5.6 180° high-torque metal-gear servo

| Servo lead | Connects to | Wire | Note |
| --- | --- | --- | --- |
| Signal (white/orange) | ESP32 **GPIO18** | 22–24 AWG | |
| `V+` (red) | **Buck 3 output, 5–6 V** | 18 AWG | ⚠️ W2. Never the ESP32 3.3 V pin. |
| `GND` (brown/black) | **Buck 3 GND** | 18 AWG | Its own return, bonded to the star point |

At the servo connector, solder a **1000 µF / 16 V electrolytic** across `V+` and
`GND`, plus a 100 nF ceramic. The servo draws large current on every start and
direction change, and without bulk capacitance that sag browns out the ESP32 and
resets it mid-ride.

Set Buck 3 to the servo's own rating: **4.8 V servos → 5.0 V**, **6 V servos →
6.0 V**. Overvolting a 4.8 V servo degrades its gearbox and can strip the teeth.

### 5.7 SIM7600 4G/LTE module

| SIM7600 pin | Connects to | Wire | Note |
| --- | --- | --- | --- |
| `VBAT` / `5V` | **Buck 2 output, 3.8 V** | 18 AWG | ⚠️ W3. Own rail only. Verify your board's input range first — some SIM7600 breakout boards expect 5 V, the bare module expects 3.4–4.4 V. |
| `GND` | **Buck 2 GND** | 18 AWG | |
| `TXD` | ESP32 **GPIO25** **via level shifter** | 22–24 AWG | ⚠️ See §5.7.1 |
| `RXD` | ESP32 **GPIO26** **via level shifter** | 22–24 AWG | ⚠️ See §5.7.1 |
| `PWRKEY` | ESP32 GPIO (any free pin) or momentary to GND | 22–24 AWG | Pulse 1–2 s to power the modem on |
| Ant. main | antenna | — | Suits and antennas installed on the board |
| Ant. GNSS | antenna | — | Route away from the servo cable and the LTE antennas |
| SIM slot | empty in this revision | — | Excluded from BOM. Without a SIM the module will power up and attach to nothing. |

#### 5.7.1 ⚠️ SIM7600 logic level — required check

The bare SIM7600 module runs its UART at **1.8 V logic**, and its pins are
**not** 3.3 V tolerant. A bare module wired straight to the ESP32 will destroy
the modem's RX pin, and the modem's TX will over-drive the ESP32's input.

Breakout boards vary, and this is the single most important thing to confirm
before wiring:

1. **Read the marking on your board.** Many SIM7600E/GC214 carrier boards already
   include a level shifter and accept 3.3 V. Others do not.
2. **Check whether the board has a level shifter.** Look for a small IC near the
   UART header, or a `VCCIO` / `1V8` pin. If the board exposes a `1V8` pin, the
   logic is 1.8 V.
3. **Measure.** Power the board, measure the idle voltage on `TXD` with a
   multimeter. **About 3.3 V → connect directly to the ESP32.** About 1.8 V →
   you need shifting.

If shifting is required, use a **TXS0108E** (8-channel, auto-direction) or
**two 4-channel TXB0104 channels**:

```
   SIM7600 TXD (1.8 V)  ──▶ shifter IN 1 ──▶ shifter OUT 1 ──▶ ESP32 GPIO25
   SIM7600 RXD (1.8 V)  ◀── shifter IN 2 ◀── shifter OUT 2 ◀── ESP32 GPIO26
   shifter VCCA = 1.8 V (low-voltage side)
   shifter VCCB = 3.3 V (ESP32 side)
   shifter GND = common GND
```

> **Caution on auto-direction shifters:** the TXS0108E and TXB0104 are designed
> for low-speed, push-pull signals. They work acceptably for UART at 9600 baud.
> They do **not** work for I2C, and they are marginal for anything faster than
> about 100 kHz. If you need a robust UART direction, two 74AHCT1G125 buffers
> plus a 10 kΩ pull-down on the modem's RX is the more conservative choice for
> a prototype.

### 5.8 Power hardware

| Component | Connection | Wire gauge |
| --- | --- | --- |
| Battery `+` | → fuse holder `+` | 18 AWG |
| Battery `−` | → terminal block GND bus | 18 AWG |
| Fuse holder out | → ON/OFF switch `1` | 18 AWG |
| ON/OFF switch `2` | → terminal block `VIN +` | 18 AWG |
| Terminal block `VIN +` | → Buck 1 IN, Buck 2 IN, Buck 3 IN | 18 AWG |
| Terminal block `GND` | → Buck 1 GND, Buck 2 GND, Buck 3 GND | 18 AWG |

---

## 6. Complete ESP32 wiring diagram

```
              ┌───────────────────────────────────────────────────────────┐
              │                    ESP32 DevKit V1                        │
   ┌──────────┤                                                           │
   │          │   3V3 ●──┬──► MPU6050 VCC                                 │
   │          │         ├──► A3144 VCC  (⚠️ 3.3 V only, never 5 V)        │
   │          │         ├──► 4.7k ──► SDA rail                            │
   │          │         ├──► 4.7k ──► SCL rail                            │
   │          │         └──► level shifter VCCB                            │
   │          │                                                           │
   │          │  VIN ●◄── 5.0 V from BUCK 1        ⚠️ W1  never 7.4 V     │
   │          │  GND ●──┬──────────────────────────────────────────●      │
   │          │        │                                           │      │
   │          │        │  ░░░ SIGNAL GROUND, 22–24 AWG ░░░           │      │
   │          │        ├──► NEO-6M GND                              │      │
   │          │        ├──► MPU6050 GND                             │      │
   │          │        ├──► LCD GND                                 │      │
   │          │        ├──► A3144 GND                               │      │
   │          │        └──► level shifter GND                       │      │
   │          │                                                    │      │
   │          │  GPIO16 ●◄── NEO-6M TXD  (3.3 V direct)            │      │
   │          │  GPIO17 ●──► NEO-6M RXD                             │      │
   │          │                                                    │      │
   │          │  GPIO21 ●◄┬─ MPU6050 SDA                           │      │
   │          │           └─ LCD SDA          (shared I2C bus)     │      │
   │          │  GPIO22 ●◄┬─ MPU6050 SCL                           │      │
   │          │           └─ LCD SCL                              │      │
   │          │                                                    │      │
   │          │  GPIO25 ●◄── level shifter ◄── SIM7600 TXD  (1.8 V) │      │
   │          │  GPIO26 ●──► level shifter ──► SIM7600 RXD  (1.8 V) │      │
   │          │                                                    │      │
   │          │  GPIO27 ●◄── A3144 D0  (+10k pull-up to 3V3)        │      │
   │          │           A0 = NOT CONNECTED                       │      │
   │          │                                                    │      │
   │          │  GPIO18 ●──► Servo signal  (servo power is SEPARATE) │      │
   │          │  GPIO19 ●──► [1k] ──► MOSFET gate                   │      │
   │          │                     └── pull-down 100k to GND        │      │
   │          │                                                    │      │
   │          │  GPIO1/3 = USB SERIAL — leave for the monitor        │      │
   │          │  GPIO6–11 = SPI flash — do not touch                 │      │
   │          └────────────────────────────────────────────────────┘      │
              ║                    ║                          ║            │
              ║                    ║                          ║            │
   ┌──────────╨──────────┐ ┌───────╨────────┐ ┌───────────────╨──┐         │
   │      NEO-6M         │ │   16x2 LCD     │ │    SIM7600       │         │
   │  VCC  ◄── 3V3       │ │  VCC ◄─ 3V3    │ │  VBAT ◄─ 3.8V   │         │
   │  GND  ◄── GND       │ │  GND ◄─ GND    │ │   (BUCK 2)      │         │
   │  TXD  ──► GPIO16    │ │  SDA ◄─ GPIO21 │ │  GND  ◄─ BUCK 2 │         │
   │  RXD  ◄── GPIO17    │ │  SCL ◄─ GPIO22 │ │   own rail ⚠️W3 │         │
   │  [+ antenna]        │ │                │ │  TXD ◄─ shifter │         │
   └─────────────────────┘ └────────────────┘ │  RXD ─ shifter ─▶│         │
                                              │  PWRKEY ─ GPIO   │         │
   ┌─────────────────────┐                    │  [+ antennas]    │         │
   │  MPU6050 GY-521     │                    └──────────────────┘         │
   │  VCC  ◄── 3V3       │                                                 │
   │  GND  ◄── GND       │   ┌──────────────────────┐                      │
   │  SDA  ──► GPIO21    │   │   180° SERVO ⚠️ W2   │                      │
   │  SCL  ──► GPIO22    │   │  signal ◄─ GPIO18    │                      │
   └─────────────────────┘   │  V+     ◄─ BUCK 3   │                      │
                              │  GND    ◄─ BUCK 3   │                      │
   ┌─────────────────────┐   │          5–6 V/3A   │                      │
   │  A3144 HALL         │   │  ┌────┐             │                      │
   │  VCC  ◄── 3V3  ⚠️    │   │  │1mF │ across V+ GND│                      │
   │  GND  ◄── GND       │   │  └────┘             │                      │
   │  D0   ──► GPIO27    │   └──────────┬───────────┘                      │
   │  A0   ──✗ OPEN     │              │                                   │
   └─────────────────────┘              │                                   │
                              ┌───────╨────────┐                          │
   ┌─────────────────────┐    │  ACTIVE BUZZER  │                          │
   │  MOSFET DRIVER      │    │   + ◄─ 3.3V     │                          │
   │  gate ◄─ GPIO19     ├───▶│   − ◄─ drain    │                          │
   │  src  ──► GND       │    └────────────────┘                          │
   └─────────────────────┘                                               │
                                                                          │
   POWER RAIL BONDING — at the terminal block star point only             │
   BUCK 1 GND (5 V)  ┐                                                      │
   BUCK 2 GND (3.8 V)├────── all tied to battery −, and to ESP32 GND      │
   BUCK 3 GND (5-6 V)┘                                                      │
```

---

## 7. Brake-resistance mechanism

The servo applies mechanical drag to the brake cable, so a rental policy can be
enforced physically even if the rider ignores the LCD and buzzer.

### 7.1 How it works

```
                        ┌──────────────────────┐
    left brake lever ───┤  BRAKE CABLE         │
    (cables & housing)  │  (spare inner cable) │
                        └──────┬───────────────┘
                               │
                    ┌──────────┴───────────┐
                    │  CABLE CLAMP / STOP   │  ← fixed to the rigid bracket
                    └──────────┬───────────┘
                               │
                          ┌────┴────┐
                          │ BARREL  │  ← adjuster sets cable tension /
                          │ADJUSTER │     clamp point on the servo horn
                          └────┬────┘
                               │
                     ┌─────────┴─────────┐
                     │  SERVO HORN        │  ← metal horn on the servo shaft
                     │  (matching horn)   │
                     └─────────┬─────────┘
                               │ 90° rotation
                     ┌─────────┴─────────┐
                     │ LINKAGE PLATE     │  ← small plate joining horn to
                     │ (cable junction)  │    the cable junction point
                     └─────────┬─────────┘
                               │
                     ┌─────────┴─────────┐
                     │  RETURN SPRING     │  ← fail-release: pulls the
                     │  (tension)         │    horn back to "free" when
                     └─────────┬─────────┘     the servo is unpowered
                               │
                     ┌─────────┴─────────┐
                     │  BRAKE CABLE       │
                     │  HOUSING           │  ← routes along the frame
                     └─────────┬─────────┘
                               │
                     ┌─────────┴─────────┐
                     │  RIGID SERVO       │  ← bolted to the down tube /
                     │  MOUNTING BRACKET  │    seat stay cluster
                     └─────────┬─────────┘
                               │
                     ┌─────────┴─────────┐
                     │  MECHANICAL TRAVEL │  ← hard stop so the horn cannot
                     │  STOP              │    over-rotate into the frame
                     └───────────────────┘
```

### 7.2 Critical safety point: the fail-release spring

**The return spring is not optional.** If the servo, its supply, or the ESP32
fails, a brake with no spring is a brake that cannot be released — the rider
loses the ability to stop.

Design the linkage so that:

- **Servo unpowered or rotated away** → the spring pulls the horn to the
  **release** position and the cable goes slack.
- **Servo energised and rotated 90°** → the horn tensions the cable and applies
  drag.

Verify this physically before ever mounting the mechanism on a roadworthy
bicycle: **cut power and confirm the rider can brake normally.** If the cable
stays tight with the servo dead, the mechanism is inverted. Fix it on the
bench, not on the road.

### 7.3 Assembly notes

| Part | Job | Assembly note |
| --- | --- | --- |
| Servo mounting bracket | Bolts the servo body rigidly to the frame | Rigid. Any flex here becomes brake slop. Use at least two bolts, 2 M3, with lock nuts. |
| Metal flat bars | Spacers and gussets | Bolt *through* the frame's flat-mount bosses where they exist. |
| Rubber spacers | Vibration isolation between servo and bracket | Prevents the servo from transmitting its own vibration into the frame. |
| Cable clamp / stop | Anchors the inner cable to the bracket | The anchor must not move when the horn pulls. |
| Barrel adjuster | Sets cable slack and the clamp point | Adjust with the spring at its natural length. |
| Travel stop | Physical hard stop for the horn | Prevents the horn rotating into the frame or the wheel. Also gives the firmware a known 0°/90° datum. |
| Linkage plate | Joins the horn to the cable junction | Keep it short. Every millimetre of extra length multiplies the horn's leverage. |
| Return spring | Fail-release | See §7.2. This is a safety component. |
| Zip ties, cable clips | Retains cable and the new harness along the frame | Clip the new 18 AWG power run away from the brake cable and the wheel path. |
| Rubber/foam padding | Protects the rider and the frame | Wrap the bracket where it could rub a knee or a trouser leg. |

### 7.4 Servo control

| Parameter | Value |
| --- | --- |
| Signal pin | GPIO18 |
| Pulse frequency | 50 Hz (the ESP32 `ledc` peripheral's default) |
| Pulse width | 500–2400 µs. Roughly **1000 µs = released, 2000 µs = applied.** |
| Travel | 180° with a mechanical hard stop at each end |
| Startup | Move to the release position **before** enabling the ride, so a boot-time glitch never applies drag to a stationary bicycle. |

---

## 8. Enclosure and cable management

| Item | Purpose |
| --- | --- |
| Water-resistant project box | Houses the perfboard, terminal block, and all splices. Mount on the down tube. |
| Cable glands | Every entry/exit through the box wall. Never leave an open hole. |
| Heat-shrink tubing | Covers every solder joint. No bare joints inside a vibration environment. |
| JST connectors | Small current only: sensors, modules, servos. Use XT60 or JST-PH for the battery. |
| Terminal blocks | Every power distribution joint, so it is inspectable and serviceable. |
| Zip ties and cable clips | Route the harness along the frame. |
| Perfboard | Soldered carrier. Not the breadboard — the breadboard is for bench work only. |

**Vibration is the real enemy here.** A bicycle frame is a resonance chamber.
Every connection on this bike is a potential intermittent fault. Solder, strain
relieve each wire at the board, and secure the harness so nothing can chafe.

---

## 9. Bench simulation plan

Staged testing. **Do not skip to a later stage.** Each stage proves one thing, and
stage 5 alone will not find a fault in stage 2.

### Prerequisites

- [ ] Multimeter (continuity + voltage)
- [ ] USB cable for the ESP32
- [ ] A USB-serial adapter or a spare powered port for the SIM7600
- [ ] Option 3 from §5.4.1: an IR break-beam or a loose magnet, to simulate wheel pulses
- [ ] A second bicycle, or stand the bike on a stand, for §9.8

### Stage 1 — Power tree, no logic (30 min)

Disconnect the ESP32 and all modules. Test the power architecture alone.

| Step | Action | Pass |
| --- | --- | --- |
| 1.1 | Measure battery. 2S Li-ion should read 6.0–8.4 V. | In range, ⚠️ W5 polarity confirmed |
| 1.2 | Battery → fuse → switch → terminal block. Measure at the block. | Close to battery voltage, less than 0.2 V drop |
| 1.3 | Set Buck 1 to 5.0 V with output **disconnected**. Reconnect, measure. | 5.0 V ± 0.2 V |
| 1.4 | Set Buck 2 to 3.8 V with output **disconnected**. Reconnect, measure. | 3.8 V ± 0.1 V. **This is the one people get wrong — verify with a meter, never by assumption.** |
| 1.5 | Set Buck 3 to the servo rating with output **disconnected**. Reconnect, measure. | 5.0 V or 6.0 V, matching the servo |
| 1.6 | Confirm the three ground rails are all bonded at the terminal block, and that Buck 3's ground is a **separate return** to the star point. | Continuity across all three grounds |
| 1.7 | Confirm **no** 5 V or 7.4 V node is shorted to any ESP32 pin. | Continuity check to each GPIO |

**Record every rail voltage. You will refer back to this table during every later fault hunt.**

### Stage 2 — ESP32 and I2C devices on the breadboard (30 min)

| Step | Action | Pass |
| --- | --- | --- |
| 2.1 | Power the ESP32 from **USB only** (belt and braces, ⚠️ W1). Buck 1 disconnected. | Boots, serial monitor at 115200 shows boot log |
| 2.2 | Flash an I2C scanner sketch. | Finds **`0x27`** (LCD backpack) |
| 2.3 | | Also finds **`0x68`** (MPU6050) |
| 2.4 | Flash `MPU6050_basic`. | WHO_AM_I reads `0x68`. Values near 0 for accel on a flat desk, ~±16384 for gyro |
| 2.5 | Flash `LiquidCrystal_I2C` example. | Text renders, contrast pot adjusted for legibility |
| 2.6 | If the display is faint, and you chose option A, switch the LCD to 5 V + level shifter, or feed 5 V to the LCD's own VCC while keeping the PCF8574 at 3.3 V. | Crisp text |
| 2.7 | **Measure SDA and SCL with a meter** while both are idle-high. | **Below 3.5 V.** Anything higher is ⚠️ W4 — stop and rewire |
| 2.8 | Twist the MPU6050 to 90° and back. | Raw accel values change coherently, no NaN, no I2C errors in the log |

### Stage 3 — Hall sensor and wheel simulation (20 min)

| Step | Action | Pass |
| --- | --- | --- |
| 3.1 | Power the A3144 at 3.3 V. Meter GPIO27 with the sensor far from any magnet. | Reads ~3.3 V (pull-up) |
| 3.2 | Pass your magnet, or interrupt the photo-interrupter slot, repeatedly. | GPIO27 pulses low, once per pass |
| 3.3 | Flash a sketch counting interrupts on GPIO27 and reporting counts per second. | Counts track your hand speed |
| 3.4 | Sweep the interrupt source from slow to fast. | No missed counts, no count spikes, no reset |
| 3.5 | Wave a magnet slowly near the A3144 and back away. | Clean transition, no chatter-induced double counts |

### Stage 4 — Buzzer, servo, brake mechanism (45 min)

Do stages 4.3 onward **off the bicycle**, on the bench.

| Step | Action | Pass |
| --- | --- | --- |
| 4.1 | Buzzer alone. Drive GPIO19 low then high. | Audible beep, clean toggle, no ESP32 reset |
| 4.2 | Measure servo rail voltage while the servo sweeps 0–180° repeatedly. | **No dip below 4.8 V** at the servo connector. Any dip is ⚠️ W2 — bulk capacitance is insufficient |
| 4.3 | Servo horn on, no cable. Sweep 90°. | Smooth, no buzzing, no reset |
| 4.4 | Fit the barrel adjuster, cable clamp, and linkage plate. Link the inner cable to the return spring. | Mechanism moves freely through its travel |
| 4.5 | Install the mechanical travel stop. | Horn cannot over-rotate into its own wiring |
| 4.6 | Energise the servo, command 90°. | Cable tensions, brake applies drag |
| 4.7 | **Cut servo power immediately.** | ⚠️ **Spring releases the cable and the brake frees itself.** This is the fail-release proof from §7.2. If it binds, the mechanism is inverted — stop and fix it. |
| 4.8 | Run the sweep 50 times, watching the ESP32 reset line / serial log. | Zero resets |

### Stage 5 — NEO-6M GPS (20 min)

| Step | Action | Pass |
| --- | --- | --- |
| 5.1 | GPS outdoors, with a clear sky view. Serial monitor at 9600 baud. | NMEA sentences `$GPGGA`, `$GPRMC` within 30–90 s |
| 5.2 | Parse position. | Lat/long plausible for your location |
| 5.3 | Carrying the bike indoors or under cover. | Fix degrades to no-fix; the parser must **not** print garbage coordinates or crash |
| 5.4 | Check fix quality against the geofence centre in `config/geofence.php` (Davao: 7.0990, 125.6470). | Consistent within a few hundred metres |

### Stage 6 — SIM7600 power, then AT commands (45 min)

Split this. Power first, logic second, because ⚠️ W3 is where units get destroyed.

| Step | Action | Pass |
| --- | --- | --- |
| 6.1 | **SIM7600 powered with the ESP32 powered DOWN.** Buck 2 to 3.8 V, current limit set low. Power up. | Modem boots, does not reset repeatedly |
| 6.2 | Measure the voltage on the modem's `TXD` pin at idle. | **3.3 V → connect directly. ~1.8 V → fit the level shifter per §5.7.1.** |
| 6.3 | Attach the level shifter if needed. | Rail-to-rail: shifter VCCA at 1.8 V, VCCB at 3.3 V |
| 6.4 | **Attach the SIM7600 to the ESP32's UART with the modem still unpowered.** | — (ordering matters: it removes the risk of a hot-plug mismatch) |
| 6.5 | Power the ESP32. Serial monitor. | No boot damage, ESP32 alive |
| 6.6 | Send `AT` on GPIO25/26. | `OK` |
| 6.7 | `ATE0`, then `AT+CPIN?` | `+CPIN: READY` — **will report `+CPIN: SIM NOT INSERTED` in this revision, as the SIM is excluded from the BOM. That is expected.** |
| 6.8 | `AT+CSQ` | Signal quality reported as 99 (unknown) with no SIM/network |
| 6.9 | **Trigger the modem transmit burst while measuring Buck 2's output.** | No dip below 3.6 V. A dip means the bulk capacitor is missing or too small |
| 6.10 | Transmit continuously for 5 minutes while the ESP32 runs. | Zero ESP32 resets. This is the ⚠️ W3 / ⚠️ W6 interference test |

### Stage 7 — Full breadboard integration (30 min)

| Step | Action | Pass |
| --- | --- | --- |
| 7.1 | All modules on the breadboard, servo still on its own supply. | — |
| 7.2 | Run the firmware for 10 minutes, cycling every peripheral. | Zero resets, zero I2C errors, zero dropped parses |
| 7.3 | Poke the antenna connectors, wiggle every jumper. | No faults. Any fault here becomes a fault on the road |
| 7.4 | Pull the WiFi/access point away so uploads fail. | Firmware keeps the countdown running from its own clock and raises an offline flag. It must **not** freeze the timer |

### Stage 8 — Pedalya API end-to-end (45 min)

Run the backend locally, then expose it to the bench. On the same LAN as the
bicycle, or with a tunnel.

| Step | Action | Pass |
| --- | --- | --- |
| 8.1 | `php artisan devices:provision-key 1` | Key printed, stored hashed |
| 8.2 | Put the key, `SERVER_HOST`, and `SERVER_PATH` in `firmware/pedalya_lcd/pedalya_lcd/config.h`, flash. | `READY TO RIDE` on the LCD |
| 8.3 | **Curl the endpoint from a laptop first**, before involving the ESP32. Far easier to debug. | JSON with `data.clock` and `data.rental: null` |
| 8.4 | Start a rental from the admin dashboard. | LCD shows `RIDE hh:mm:ss` / `LEFT hh:mm:ss`, counting down once per second |
| 8.5 | Compare the LCD countdown against the server's `remainingSeconds`. | Agreement within a couple of seconds |
| 8.6 | Have an admin set a return deadline shorter than the remaining time. | Within one poll (15 s) the LCD retargets to the new deadline |
| 8.7 | Let the deadline pass. | Row 2 switches from `LEFT …` to `LATE PHP…` and the figure climbs once per second |
| 8.8 | Compare the LCD penalty against the server's `penalty.accrued`. | The local projection is a display convenience; after each poll it must **snap to the server's figure** |
| 8.9 | Confirm the return. | `READY TO RIDE` again |
| 8.10 | **Trigger the accident path** (tilt or jerk the MPU6050 hard). | `accident-report` posts, admin notification fires |
| 8.11 | Sweep the wheel simulation source. | `gps/logs` and distance in the dashboard advance |

### Stage 9 — Breadboard to soldered perfboard (60 min)

Do not skip this stage. The breadboard is for simulation only.

- [ ] Photograph every breadboard connection **before** dismantling
- [ ] Transfer to perfboard, one subsystem at a time
- [ ] Re-run stages 2, 3, 4, and 7 after each subsystem lands
- [ ] Fit the project box, glands, and heat-shrink
- [ ] Mount to the frame with the brake mechanism
- [ ] Full road test with the fail-release check from §7.2
- [ ] Re-run stage 8 against the deployed server

### 9.10 Pre-power checklist

Run this every time, before the first energise:

```
[ ] Battery polarity verified with a meter                     ⚠️ W5
[ ] Fuse installed, correct rating, in the positive lead
[ ] All buck converter OUTPUTS measured at the correct voltage
[ ] No 7.4 V present at the ESP32 VIN or any GPIO             ⚠️ W1
[ ] No 5 V present at any ESP32 GPIO                           ⚠️ W4
[ ] Servo powered from Buck 3 only, never from the ESP32       ⚠️ W2
[ ] SIM7600 powered from Buck 2 only, with 1000 uF bulk        ⚠️ W3
[ ] I2C SDA/SCL measured below 3.5 V
[ ] SIM7600 TXD logic level measured and confirmed
[ ] A3144 powered at 3.3 V, not 5 V
[ ] All ground rails bonded at the terminal block star point
[ ] 1000 uF bulk capacitor soldered across the servo V+/GND
[ ] USB power OR buck power, never both
[ ] Brake fail-release verified with servo power cut
[ ] A0 pin of the A3144 left open and insulated
```

---

## 10. Troubleshooting

| Symptom | Likely cause | Check |
| --- | --- | --- |
| ESP32 resets whenever the servo moves | Servo sag on the shared supply | Buck 3 set too low, or bulk cap missing. Measure at the **servo**, not the converter. |
| ESP32 resets when the modem transmits | ⚠️ W3 violation | Modem must be on its own rail with bulk capacitance. Also check grounds are bonded. |
| LCD shows nothing | Backpack address, or SDA/SCL not pulled high | Run the I2C scanner. Confirm 4.7 kΩ pull-ups to **3.3 V**, not 5 V. |
| LCD text faint or garbled | Backlight too dim for the ambient light, or VDD set to 3.3 V | 3.3 V backlighting is dim by design; shift the module to 5 V for contrast. |
| LCD works, then freezes | Brownout from servo current | 1000 µF cap across servo V+/GND. |
| `http 401` on the LCD | Wrong or rotated key | `php artisan devices:provision-key 1 --rotate`, then re-flash. |
| Hall sensor never triggers | No magnet in the BOM | See §5.4.1. |
| Modem silent on the UART | 1.8 V vs 3.3 V level mismatch | Measure `TXD` idle voltage; see §5.7.1. |
| Modem reboots in a loop | Backed off or undervolted | Confirm 3.8 V and current-limit headroom. |
| No GPS fix indoors | Normal | Test outdoors with a clear sky view. |
| Counting fails at speed | Sampling too slow | Raise the interrupt rate / use hardware PCNT instead of software interrupts. |
| I2C errors under vibration | Long unprotected jumper runs | Move to perfboard, solder, strain relief, and anchor the harness. |

---

## 11. Revision notes

### Excluded from this revision, and what it costs

| Excluded | Consequence |
| --- | --- |
| Nano SIM / mobile data | The SIM7600 is wired, powered, and expected to boot, but it cannot attach to a network. `AT+CPIN?` returns `SIM NOT INSERTED`, and all LTE-dependent firmware paths are untestable end-to-end. Everything up to and including the UART link is fully verifiable. |
| Neodymium magnet | The A3144 has nothing to switch on. See §5.4.1 — this is a real gap for wheel monitoring, and it needs resolving before the ride-monitoring path can be validated. |

### Not modelled here

The 16×2 LCD countdown firmware already exists and is documented in
`firmware/pedalya_lcd/README.md`. This document covers the wider hardware
system: GPS, cellular, sensing, and the brake actuator.
