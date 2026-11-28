#include "AccidentDetector.h"

#include "config.h"

#include <Wire.h>

namespace {

// Register addresses, from the MPU-6000/MPU-6050 register map.
constexpr uint8_t REG_SMPLRT_DIV = 0x19;
constexpr uint8_t REG_CONFIG = 0x1A;
constexpr uint8_t REG_ACCEL_CONFIG = 0x1C;
constexpr uint8_t REG_GYRO_CONFIG  = 0x1B;
constexpr uint8_t REG_ACCEL_XOUT_H = 0x3B;
constexpr uint8_t REG_PWR_MGMT_1 = 0x6B;
constexpr uint8_t REG_WHO_AM_I = 0x75;

// Accelelerometer at +-8 g, so 4096 counts/g. See config.h for why not the
// +-2 g default.
constexpr float ACCEL_LSB_PER_G = 4096.0f;
// Gyroscope at +-250 degrees/s, so 131 counts per degree/s.
constexpr float GYRO_LSB_PER_DPS = 131.0f;

constexpr uint8_t PWR_MGMT_1_RUN = 0x01;      // X gyro PLL; bit 0 also wakes the part
constexpr uint8_t SMPLRT_DIV_200HZ = 0x04;    // 1 kHz / (4 + 1)
constexpr uint8_t CONFIG_DLPF_44HZ = 0x03;    // 44 Hz accel, 42 Hz gyro
constexpr uint8_t ACCEL_CONFIG_8G = 0x10;     // AFS_SEL = 2
constexpr uint8_t GYRO_CONFIG_250DPS = 0x00;  // FS_SEL = 0

constexpr size_t BURST_BYTES = 14;  // 3 axes accel, temperature, 3 axes gyro

// How many times the range registers are written and read back before the part
// is accepted as it found itself.
constexpr uint8_t CONFIG_ATTEMPTS = 3;

inline float square(float value) { return value * value; }

/**
 * Counts per g for a given AFS_SEL field.
 *
 * The MPU-6050 divides its counts by the full-scale range, so a part left on the
 * +-2 g power-on default delivers four times the counts a +-8 g part does for
 * the same acceleration.
 */
float accelLsbForRange(uint8_t config) {
    switch (config & 0x18) {
        case 0x00: return 16384.0f;  // +-2 g
        case 0x08: return 8192.0f;   // +-4 g
        case 0x18: return 2048.0f;   // +-16 g
        case 0x10:
        default:  return 4096.0f;    // +-8 g
    }
}

/** Counts per deg/s for a given FS_SEL field. */
float gyroLsbForRange(uint8_t config) {
    switch (config & 0x18) {
        case 0x00: return 131.0f;   // +-250 deg/s
        case 0x08: return 65.5f;    // +-500 deg/s
        case 0x18: return 16.4f;    // +-2000 deg/s
        case 0x10:
        default:  return 32.8f;     // +-1000 deg/s
    }
}

}  // namespace

/**
 * Set the measurement range, then confirm the part agreed.
 *
 * The write is verified because a rejected configuration is silent: the I2C
 * transfer reports success while the accelerometer keeps its power-on default.
 * Left unchecked, a bike standing still reads 4 g and an ordinary kerb strike
 * sails past the hard-impact threshold, which is an admin being paged for
 * nothing. Whatever range the part ends up on is read back and used as the
 * scale, so a module that will not cooperate is still measured correctly.
 *
 * IMPORTANT: some GY-521 clone modules do not apply AFS_SEL changes from
 * a bare wake-from-sleep sequence. A full device reset (PWR_MGMT_1 bit 7)
 * followed by a 100 ms stabilization delay is required to ensure the range
 * register write reaches the ADC conversion unit, not just the register file.
 * After configuration the firmware reads one sample and verifies the raw
 * magnitude is in the plausible gravity band (0.5 g–2.5 g using the reported
 * scale). A 4 g reading at rest signals the ADC is still on the ±2 g
 * power-on default despite the register showing ±8 g; the sensor is then
 * rejected until a subsequent re-probe or board reset corrects it.
 */
bool AccidentDetector::configure(uint8_t address) {
    // --- Step 1: Full device reset ---
    // Write bit 7 (DEVICE_RESET). The chip clears this bit when it finishes.
    // Some GY-521 clones require this before AFS_SEL changes take effect.
    if (!writeRegister(address, REG_PWR_MGMT_1, 0x80)) return false;
    delay(100);  // datasheet: reset completes within 100 ms

    // --- Step 2: Wake and select clock source ---
    if (!writeRegister(address, REG_PWR_MGMT_1, 0x00)) return false;
    delay(30);
    if (!writeRegister(address, REG_PWR_MGMT_1, PWR_MGMT_1_RUN)) return false;
    delay(30);

    // --- Step 3: Configure sample rate, DLPF, accel and gyro range ---
    if (!writeRegister(address, REG_SMPLRT_DIV, SMPLRT_DIV_200HZ) ||
        !writeRegister(address, REG_CONFIG,      CONFIG_DLPF_44HZ) ||
        !writeRegister(address, REG_ACCEL_CONFIG, ACCEL_CONFIG_8G) ||
        !writeRegister(address, REG_GYRO_CONFIG,  GYRO_CONFIG_250DPS)) return false;
    delay(30);  // allow ADC to resync after range change

    // --- Step 4: Read back range registers to establish the actual scale ---
    uint8_t ranges[2] = {0};
    if (!readBlock(address, REG_ACCEL_CONFIG, &ranges[0], 1) ||
        !readBlock(address, REG_GYRO_CONFIG,  &ranges[1], 1)) return false;
    accelLsbPerG_  = accelLsbForRange(ranges[0]);
    gyroLsbPerDps_ = gyroLsbForRange(ranges[1]);
    Serial.printf("[mpu] configured ranges: accel 0x%02X (%.0f LSB/g), gyro 0x%02X (%.1f LSB/dps)\n",
                  (unsigned)ranges[0], (double)accelLsbPerG_,
                  (unsigned)ranges[1], (double)gyroLsbPerDps_);

    // --- Step 5: ADC self-check ---
    // Read one burst and verify the raw magnitude is physically plausible.
    // A sensor on its power-on ±2 g default while the register shows ±8 g
    // delivers 4× too many counts: a stationary sensor reads ~4 g, not ~1 g.
    // Detect this before the caller calibrates a baseline from corrupted data.
    delay(10);  // let the first post-config ADC sample settle
    uint8_t chk[BURST_BYTES];
    memset(chk, 0, sizeof(chk));
    if (readBlock(address, REG_ACCEL_XOUT_H, chk, sizeof(chk))) {
        const float ax = (int16_t)((chk[0] << 8) | chk[1]) / accelLsbPerG_;
        const float ay = (int16_t)((chk[2] << 8) | chk[3]) / accelLsbPerG_;
        const float az = (int16_t)((chk[4] << 8) | chk[5]) / accelLsbPerG_;
        const float selfCheckMag = sqrtf(ax * ax + ay * ay + az * az);
        Serial.printf("[mpu] self-check raw counts: ax=%d ay=%d az=%d "
                      "-> ax=%.3fg ay=%.3fg az=%.3fg |a|=%.3fg\n",
                      (int)(int16_t)((chk[0] << 8) | chk[1]),
                      (int)(int16_t)((chk[2] << 8) | chk[3]),
                      (int)(int16_t)((chk[4] << 8) | chk[5]),
                      (double)ax, (double)ay, (double)az,
                      (double)selfCheckMag);
        if (selfCheckMag > 2.5f) {
            // Magnitude far above 1 g on a stationary sensor means the ADC is
            // not using the programmed range. The most common cause on GY-521
            // clones is that the ACCEL_CONFIG write was accepted by the register
            // but not applied by the conversion unit, leaving ±2 g active.
            // Do NOT accept this sensor: the baseline calibration would embed
            // a 4× scale error and every reading would be meaningless.
            Serial.printf("[mpu] ERROR: self-check magnitude %.3f g >> 2.5 g threshold "
                          "on stationary sensor.\n", (double)selfCheckMag);
            Serial.println("[mpu]   Register shows AFS_SEL programmed, but ADC appears "
                           "to still use the +-2 g power-on default (16384 LSB/g).");
            Serial.println("[mpu]   Possible causes:");
            Serial.println("[mpu]     - GY-521 clone requires DEVICE_RESET before "
                           "AFS_SEL takes effect in ADC (now issued; retry may help).");
            Serial.println("[mpu]     - I2C bus glitch corrupted the ACCEL_CONFIG write.");
            Serial.println("[mpu]     - ACCEL_CONFIG register address mismatch on "
                           "this silicon variant.");
            Serial.println("[mpu]   Rejecting sensor until a fresh probe confirms "
                           "plausible 1 g magnitude.");
            return false;
        }
        if (selfCheckMag < 0.5f) {
            // Below 0.5 g: sensor is asleep, unpowered, or in freefall (impossible
            // on a bench). Reject for the same reason.
            Serial.printf("[mpu] ERROR: self-check magnitude %.3f g < 0.5 g "
                          "on a stationary sensor.\n", (double)selfCheckMag);
            Serial.println("[mpu]   Sensor may be in sleep mode or returning zeros.");
            return false;
        }
        Serial.printf("[mpu] self-check OK: |a| = %.3f g (within 0.5 g – 2.5 g band)\n",
                      (double)selfCheckMag);
    } else {
        // Self-check read failed: report and reject.
        Serial.println("[mpu] self-check burst read failed; rejecting sensor.");
        return false;
    }

    return true;
}

bool AccidentDetector::begin() {
    lastProbeMs_ = millis();
    settling_ = true;
    armed_ = false;
    settlingStartMs_ = 0; // starts on first poll() in loop()
    settlingSampleCount_ = 0;
    settlingSumX_ = 0.0f;
    settlingSumY_ = 0.0f;
    settlingSumZ_ = 0.0f;
    uprightSet_ = false;
    return probe(true);  // boot probe reports per-address detail once
}

/**
 * Look for the IMU on both of its addresses and configure it.
 *
 * AD0 low is 0x68 and AD0 high is 0x69, so probing both means the module works
 * either way round on the bus. A part that is absent returns 0xFF and a
 * floating one returns 0x00, which is why WHO_AM_I is checked rather than
 * simply relying on the transfer succeeding.
 */
bool AccidentDetector::probe(bool logDetail) {
    const uint8_t candidates[2] = {MPU6050_I2C_ADDRESS, 0x69};
    char note[2][40] = {"not tried", "not tried"};

    for (uint8_t index = 0; index < 2; ++index) {
        const uint8_t candidate = candidates[index];
        if (index == 1 && candidate == candidates[0]) {
            snprintf(note[index], sizeof(note[index]), "same as configured");
            continue;  // already tried
        }

        uint8_t whoAmI = 0;
        if (!readBlock(candidate, REG_WHO_AM_I, &whoAmI, 1)) {
            snprintf(note[index], sizeof(note[index]), "no ACK (absent/unpowered)");
            continue;
        }
        if (whoAmI == 0x00 || whoAmI == 0xFF) {
            snprintf(note[index], sizeof(note[index]), "ACK but WHO_AM_I=0x%02X (no MPU6050)",
                     (unsigned)whoAmI);
            continue;
        }

        if (!configure(candidate)) {
            snprintf(note[index], sizeof(note[index]), "WHO_AM_I ok, configure failed");
            continue;
        }

        address_ = candidate;
        present_ = true;
        hasSample_ = false;
        consecutiveReadFailures_ = 0;
        Serial.printf("[mpu] MPU6050 at 0x%02X (id 0x%02X); %.0f counts/g, %.1f counts/dps\n",
                      (unsigned)address_, (unsigned)whoAmI,
                      (double)accelLsbPerG_, (double)gyroLsbPerDps_);

        // Calibrate resting baseline gravity vector immediately.
        // The configure() ADC self-check already verified the scale once.
        // Calibrate using averaged samples; log raw counts on every sample
        // so a scaling defect is visible in the serial log.
        float sumX = 0, sumY = 0, sumZ = 0;
        uint8_t count = 0;
        bool baselineDefectSeen = false;
        for (uint8_t i = 0; i < 15; ++i) {
            delay(10);
            uint8_t raw[BURST_BYTES];
            memset(raw, 0, sizeof(raw));
            if (readBlock(address_, REG_ACCEL_XOUT_H, raw, sizeof(raw))) {
                const int16_t rxc = (int16_t)((raw[0] << 8) | raw[1]);
                const int16_t ryc = (int16_t)((raw[2] << 8) | raw[3]);
                const int16_t rzc = (int16_t)((raw[4] << 8) | raw[5]);
                const float fx = rxc / accelLsbPerG_;
                const float fy = ryc / accelLsbPerG_;
                const float fz = rzc / accelLsbPerG_;
                const float fm = sqrtf(fx * fx + fy * fy + fz * fz);
                // Log every sample so scaling defects appear in the serial log.
                Serial.printf("[mpu] cal sample %u: raw ax=%d ay=%d az=%d "
                              "-> %.3fg %.3fg %.3fg |a|=%.3fg\n",
                              (unsigned)i, (int)rxc, (int)ryc, (int)rzc,
                              (double)fx, (double)fy, (double)fz, (double)fm);
                if (fm > 2.5f) {
                    // Per-sample plausibility guard. configure() should have
                    // caught this with the ADC self-check, but a delayed effect
                    // or I2C write race could still produce inflated values.
                    Serial.printf("[mpu] cal sample %u magnitude %.3f g > 2.5 g "
                                  "(possible scale defect); marking calibration invalid\n",
                                  (unsigned)i, (double)fm);
                    baselineDefectSeen = true;
                    // Do not accumulate; continue to collect the remaining
                    // samples for logging, but flag the baseline as unusable.
                } else if (!baselineDefectSeen) {
                    sumX += fx;
                    sumY += fy;
                    sumZ += fz;
                    count++;
                }
            } else {
                Serial.printf("[mpu] cal sample %u: I2C read failed\n", (unsigned)i);
            }
        }
        if (baselineDefectSeen) {
            // Sensor returned implausibly large magnitudes during calibration.
            // Reject the sensor: the baseline would encode a scale error and
            // the detector would raise false crashes immediately.
            Serial.println("[mpu] calibration rejected: one or more samples > 2.5 g "
                           "on a stationary sensor.");
            Serial.println("[mpu]   This is consistent with the ADC using +-2 g "
                           "sensitivity while the scale factor assumes +-8 g.");
            Serial.println("[mpu]   Accident detection will remain disabled.");
            present_ = false;
            return false;
        }
        if (count >= 5) {
            float avgX = sumX / count;
            float avgY = sumY / count;
            float avgZ = sumZ / count;
            float mag = sqrtf(avgX * avgX + avgY * avgY + avgZ * avgZ);
            if (mag > 0.4f && mag < 2.5f) {
                uprightX_ = avgX / mag;
                uprightY_ = avgY / mag;
                uprightZ_ = avgZ / mag;
                uprightSet_ = true;
                baselineGravityG_ = mag;
                Serial.printf("[mpu] Resting baseline calibrated: %.3f g, "
                              "upright vector (%.3f, %.3f, %.3f) from %u samples\n",
                              (double)baselineGravityG_,
                              (double)uprightX_, (double)uprightY_, (double)uprightZ_,
                              (unsigned)count);
            } else {
                Serial.printf("[mpu] baseline magnitude %.3f g out of 0.4–2.5 g range; "
                              "upright vector not set\n", (double)mag);
            }
        } else {
            Serial.printf("[mpu] baseline: only %u valid samples (need >=5); "
                          "upright vector not set\n", (unsigned)count);
        }
        return true;
    }

    // Boot/single-shot detail only: the 5 s re-probe in poll() stays silent.
    if (logDetail) {
        Serial.printf("[mpu] probe 0x%02X: %s; 0x%02X: %s\n",
                      (unsigned)candidates[0], note[0],
                      (unsigned)candidates[1], note[1]);
    }
    return false;
}

bool AccidentDetector::writeRegister(uint8_t address, uint8_t reg, uint8_t value) {
    Wire.beginTransmission(address);
    Wire.write(reg);
    Wire.write(value);
    return Wire.endTransmission(true) == 0;
}

bool AccidentDetector::readBlock(uint8_t address, uint8_t reg, uint8_t* dest, size_t length) {
    Wire.beginTransmission(address);
    Wire.write(reg);
    if (Wire.endTransmission(false) != 0) return false;  // address phase, bus stays held

    const size_t received = Wire.requestFrom(address, (uint8_t)length, (uint8_t)true);
    if (received != length) {
        // Drain anything that did arrive, so the next transfer starts on a
        // clean bus instead of inheriting half a reading.
        //
        // Bounded on purpose. This runs on the I2C error path, where the read
        // buffer is exactly the thing known to be unreliable; an unbounded
        // while here would spin with the buzzer, the servo and expiry
        // enforcement all held up behind it. Asking for more than a burst could
        // possibly have returned is enough to empty it.
        for (size_t drained = 0; drained < length && Wire.available(); ++drained) {
            Wire.read();
        }
        return false;
    }

    for (size_t i = 0; i < length; ++i) dest[i] = (uint8_t)Wire.read();
    return true;
}

void AccidentDetector::poll() {
    if (!ACCIDENT_ENABLED) return;

    const uint32_t now = millis();

    if (!present_) {
        // Keep looking. A module added while the bike is running must be found
        // without resetting the board.
        if ((uint32_t)(now - lastProbeMs_) >= ACCIDENT_PROBE_INTERVAL_MS) {
            lastProbeMs_ = now;
            probe();
        }
        return;
    }

    bool gotNewSample = false;
    if ((uint32_t)(now - lastSampleMs_) >= ACCIDENT_SAMPLE_INTERVAL_MS) {
        lastSampleMs_ = now;

        uint8_t raw[BURST_BYTES];
        memset(raw, 0, sizeof(raw));
        const bool readOk = readBlock(address_, REG_ACCEL_XOUT_H, raw, sizeof(raw));

        // Validate raw data:
        // 1. I2C transfer must succeed.
        // 2. All 14 bytes cannot be 0x00 (ADC sleeping/unpowered or SDA held low).
        // 3. All 14 bytes cannot be 0xFF (bus floating/NACK).
        // 4. Accelerometer raw bytes (raw[0..5]) cannot all be zero: on Earth,
        //    gravitational acceleration is ~1g. All-zero counts indicate
        //    the sensor is sleeping (e.g. brownout reset into SLEEP mode).
        bool allZero = true;
        bool allFF = true;
        for (size_t i = 0; i < BURST_BYTES; i++) {
            if (raw[i] != 0x00) allZero = false;
            if (raw[i] != 0xFF) allFF = false;
        }
        const bool accelZero = (raw[0] == 0 && raw[1] == 0 && raw[2] == 0 &&
                                raw[3] == 0 && raw[4] == 0 && raw[5] == 0);

        if (readOk && !allZero && !allFF && !accelZero) {
            consecutiveReadFailures_ = 0;
            totalSamples_++;
            evaluate(raw, now);
            gotNewSample = true;
        } else {
            consecutiveReadFailures_++;
            totalReadFailures_++;
            if (readOk && (allZero || accelZero)) {
                Serial.printf("[mpu] read rejected: burst returned zero-counts (sleeping/reset? failure #%u)\n",
                              (unsigned)consecutiveReadFailures_);
                uint8_t pwr = 0;
                if (readBlock(address_, REG_PWR_MGMT_1, &pwr, 1)) {
                    if (pwr & 0x40) {
                        Serial.printf("[mpu] chip in sleep mode (PWR_MGMT_1=0x%02X); re-waking\n", (unsigned)pwr);
                        writeRegister(address_, REG_PWR_MGMT_1, 0x00);
                        delay(10);
                        writeRegister(address_, REG_PWR_MGMT_1, PWR_MGMT_1_RUN);
                    }
                }
            }
            if (consecutiveReadFailures_ >= 3) {
                present_ = false;
                armed_ = false;
                hasSample_ = false;
                settling_ = false;
                lastProbeMs_ = now;
                Serial.println("[mpu] three consecutive I2C read failures or invalid bursts; accident detection disabled pending re-probe");
                return;
            }
        }
    }

    if (settling_) {
        if (settlingStartMs_ == 0) settlingStartMs_ = now;
        // Collect stationary samples during startup settling to calibrate upright gravity
        if (gotNewSample) {
            if (reading_.gyroMagnitudeDps < 30.0f && reading_.magnitudeG > 0.4f) {
                settlingSumX_ += reading_.accelXG;
                settlingSumY_ += reading_.accelYG;
                settlingSumZ_ += reading_.accelZG;
                settlingSampleCount_++;
            }
        }
        if ((uint32_t)(now - settlingStartMs_) >= ACCIDENT_STARTUP_SETTLE_MS) {
            settling_ = false;
            if (settlingSampleCount_ >= 5) {
                float avgX = settlingSumX_ / settlingSampleCount_;
                float avgY = settlingSumY_ / settlingSampleCount_;
                float avgZ = settlingSumZ_ / settlingSampleCount_;
                float mag = sqrtf(avgX * avgX + avgY * avgY + avgZ * avgZ);
                if (mag > 0.4f && mag < 2.5f) {
                    uprightX_ = avgX / mag;
                    uprightY_ = avgY / mag;
                    uprightZ_ = avgZ / mag;
                    uprightSet_ = true;
                    baselineGravityG_ = mag;
                    Serial.printf("[mpu] Baseline gravity calibrated: %.2f g, upright vector (%.2f, %.2f, %.2f)\n",
                                  (double)baselineGravityG_, (double)uprightX_, (double)uprightY_, (double)uprightZ_);
                    rearm();
                    return;
                }
            }
            // Settling failed to acquire valid baseline gravity
            Serial.printf("[mpu] settling failed: insufficient valid stationary samples (%u < 5); accident detection disarmed pending re-probe\n",
                          (unsigned)settlingSampleCount_);
            armed_ = false;
            present_ = false;
            hasSample_ = false;
            lastProbeMs_ = now;
        }
        return;
    }

    if (armed_) return;

    // A latched event is waiting for the caller; nothing re-arms behind its back.
    if (triggered_) return;
    if ((uint32_t)(now - triggeredAtMs_) < ACCIDENT_REARM_COOLDOWN_MS) return;

    if (!calm()) {
        calmSinceMs_ = 0;
        return;
    }

    // calmSinceMs_ cannot legitimately be 0 here: the cooldown above has
    // already put us past ACCIDENT_REARM_COOLDOWN_MS.
    if (calmSinceMs_ == 0) {
        calmSinceMs_ = now;
        return;
    }
    if ((uint32_t)(now - calmSinceMs_) >= ACCIDENT_REARM_CALM_MS) rearm();
}

void AccidentDetector::acknowledge() {
    triggered_ = false;
}

void AccidentDetector::evaluate(const uint8_t* raw, uint32_t now) {
    AccidentReading sample;
    sample.sampledAtMs = now;

    // Decode: big-endian signed 16-bit, divide by the verified LSB/g scale.
    const int16_t rawAxC = (int16_t)((raw[0] << 8) | raw[1]);
    const int16_t rawAyC = (int16_t)((raw[2] << 8) | raw[3]);
    const int16_t rawAzC = (int16_t)((raw[4] << 8) | raw[5]);
    // raw[6] and raw[7] are the temperature block; not used here.
    const int16_t rawGxC = (int16_t)((raw[8]  << 8) | raw[9]);
    const int16_t rawGyC = (int16_t)((raw[10] << 8) | raw[11]);
    const int16_t rawGzC = (int16_t)((raw[12] << 8) | raw[13]);

    sample.accelXG  = rawAxC / accelLsbPerG_;
    sample.accelYG  = rawAyC / accelLsbPerG_;
    sample.accelZG  = rawAzC / accelLsbPerG_;
    sample.gyroXDps = rawGxC / gyroLsbPerDps_;
    sample.gyroYDps = rawGyC / gyroLsbPerDps_;
    sample.gyroZDps = rawGzC / gyroLsbPerDps_;

    // Log raw counts for the first 20 samples so a scaling defect is
    // immediately visible in the serial monitor without needing a debugger.
    if (totalSamples_ < 20) {
        Serial.printf("[mpu] sample %lu raw counts: ax=%d ay=%d az=%d gx=%d gy=%d gz=%d "
                      "-> ax=%.3fg ay=%.3fg az=%.3fg gx=%.1fdps gy=%.1fdps gz=%.1fdps\n",
                      (unsigned long)totalSamples_,
                      (int)rawAxC, (int)rawAyC, (int)rawAzC,
                      (int)rawGxC, (int)rawGyC, (int)rawGzC,
                      (double)sample.accelXG, (double)sample.accelYG, (double)sample.accelZG,
                      (double)sample.gyroXDps, (double)sample.gyroYDps, (double)sample.gyroZDps);
    }

    const float rawMagG = sqrtf(square(sample.accelXG) + square(sample.accelYG) +
                                square(sample.accelZG));
    if (rawMagG < 0.2f) {
        // Physical sanity check: static acceleration < 0.2g is impossible on Earth
        // for an active sensor. Indicates sleeping ADC, unpowered sensor, or bus fault.
        return;
    }
    // Upper plausibility guard: >3.5g on a stationary sensor at 1g means the
    // ADC scale factor is wrong (e.g. chip still on +-2g while firmware uses 4096 LSB/g).
    // Do NOT feed this into settling/arming: a 4g "resting" baseline would normalize
    // every real crash to 1g and hide impacts entirely.
    if (rawMagG > 3.5f && settling_) {
        Serial.printf("[mpu] evaluate: rawMagG %.3f g > 3.5 g during settling "
                      "(possible ADC scale defect); sample rejected\n", (double)rawMagG);
        return;
    }
    if (uprightSet_ && baselineGravityG_ > 0.4f) {
        sample.magnitudeG = rawMagG / baselineGravityG_;
    } else {
        sample.magnitudeG = rawMagG;
    }

    sample.gyroMagnitudeDps = sqrtf(square(sample.gyroXDps) + square(sample.gyroYDps) +
                                    square(sample.gyroZDps));

    // Angle relative to calibrated upright orientation, or vertical Z if uncalibrated.
    // Dot product u · g yields cos(tilt), giving 0 deg when upright regardless of
    // how the sensor board is mounted on the bike.
    if (uprightSet_ && rawMagG > 0.1f) {
        float dot = (sample.accelXG * uprightX_ + sample.accelYG * uprightY_ + sample.accelZG * uprightZ_) / rawMagG;
        if (dot > 1.0f) dot = 1.0f;
        if (dot < -1.0f) dot = -1.0f;
        sample.tiltDegrees = acosf(dot) * RAD_TO_DEG;
    } else {
        const float lateralG = sqrtf(square(sample.accelXG) + square(sample.accelYG));
        sample.tiltDegrees = atan2f(lateralG, fabsf(sample.accelZG)) * RAD_TO_DEG;
    }

    if (hasSample_) sample.deltaMagnitudeG = fabsf(sample.magnitudeG - previousMagnitudeG_);

    // Three independent ways to be hit, because each one alone misses a case
    // the others catch: a hard upper bound, a fast change, and gravity lost.
    sample.impact = sample.magnitudeG >= ACCIDENT_IMPACT_G ||
                    sample.deltaMagnitudeG >= ACCIDENT_JERK_G ||
                    sample.magnitudeG <= ACCIDENT_FREEFALL_G;
    sample.rotation = sample.gyroMagnitudeDps >= ACCIDENT_ROTATION_DPS;
    sample.tilt = sample.tiltDegrees >= ACCIDENT_TILT_DEGREES;

    reading_ = sample;
    previousMagnitudeG_ = sample.magnitudeG;
    hasSample_ = true;

    // Never trigger while settling or disarmed!
    if (!armed_ || settling_) {
        fallTiltActive_ = false;
        fallHadImpact_ = false;
        fallHadRotation_ = false;
        return;
    }

    sample.fallTilt = sample.tiltDegrees >= ACCIDENT_FALL_TILT_DEGREES;
    if (sample.fallTilt) {
        if (!fallTiltActive_) {
            fallTiltActive_ = true;
            fallTiltStartedAtMs_ = now;
            fallHadImpact_ = false;
            fallHadRotation_ = false;
        }
        fallHadImpact_ = fallHadImpact_ || sample.impact;
        fallHadRotation_ = fallHadRotation_ || sample.rotation;
        sample.sustainedFallTilt =
            (uint32_t)(now - fallTiltStartedAtMs_) >= ACCIDENT_FALL_TILT_HOLD_MS;
    } else {
        fallTiltActive_ = false;
        fallTiltStartedAtMs_ = 0;
        fallHadImpact_ = false;
        fallHadRotation_ = false;
    }
    reading_ = sample;

    if (sample.magnitudeG > peakMagnitudeG_) peakMagnitudeG_ = sample.magnitudeG;
    if (sample.gyroMagnitudeDps > peakRotationDps_) peakRotationDps_ = sample.gyroMagnitudeDps;
    if (sample.tiltDegrees > peakTiltDegrees_) peakTiltDegrees_ = sample.tiltDegrees;

    // Keep impact evaluation independent from the sustained-tilt timer. Tilt
    // without impact must persist for the full fall-evaluation interval.
    const bool candidate =
        (sample.impact && (sample.rotation || sample.tilt)) ||
        sample.magnitudeG >= ACCIDENT_HARD_IMPACT_G ||
        sample.sustainedFallTilt;

    if (!candidate) {
        if (hits_ > 0 && ++misses_ > (uint8_t)ACCIDENT_TRIGGER_TOLERANCE) {
            hits_ = 0;
            misses_ = 0;
        }
        return;
    }

    if (++hits_ >= (uint8_t)ACCIDENT_TRIGGER_SAMPLES) fire(sample, now);
}

void AccidentDetector::fire(const AccidentReading& sample, uint32_t now) {
    event_.peakMagnitudeG = peakMagnitudeG_;
    event_.peakRotationDps = peakRotationDps_;
    event_.peakTiltDegrees = peakTiltDegrees_;
    event_.accelXG = sample.accelXG;
    event_.accelYG = sample.accelYG;
    event_.accelZG = sample.accelZG;
    event_.gyroXDps = sample.gyroXDps;
    event_.gyroYDps = sample.gyroYDps;
    event_.gyroZDps = sample.gyroZDps;
    event_.impactDetected = sample.impact || sample.magnitudeG >= ACCIDENT_HARD_IMPACT_G ||
        (sample.sustainedFallTilt && fallHadImpact_);
    event_.rotationDetected = sample.rotation ||
        (sample.sustainedFallTilt && fallHadRotation_);
    event_.sustainedFallTilt = sample.sustainedFallTilt;
    event_.detectedAtMs = now;
    if (sample.sustainedFallTilt) {
        event_.classification = (event_.impactDetected || event_.rotationDetected)
            ? "POTENTIAL_ACCIDENT"
            : "POTENTIAL_FALL";
        event_.detectionTrigger = "sustained_tilt";
    } else if (sample.impact || sample.magnitudeG >= ACCIDENT_HARD_IMPACT_G) {
        event_.classification = "IMPACT_DETECTED";
        event_.detectionTrigger = "impact_threshold";
    } else {
        event_.classification = "POTENTIAL_ACCIDENT";
        event_.detectionTrigger = "rotation_and_tilt";
    }

    if (sample.magnitudeG >= ACCIDENT_HARD_IMPACT_G) {
        event_.detectionReason = "hard_impact";
    } else if (sample.sustainedFallTilt && event_.impactDetected && event_.rotationDetected) {
        event_.detectionReason = "sustained_tilt_with_impact_and_rotation";
    } else if (sample.sustainedFallTilt && event_.impactDetected) {
        event_.detectionReason = "sustained_tilt_with_impact";
    } else if (sample.sustainedFallTilt && event_.rotationDetected) {
        event_.detectionReason = "sustained_tilt_with_rotation";
    } else if (sample.sustainedFallTilt) {
        event_.detectionReason = "sustained_tilt";
    } else if (sample.impact && sample.rotation && sample.tilt) {
        event_.detectionReason = "impact_rotation_and_tilt";
    } else if (sample.impact && sample.rotation) {
        event_.detectionReason = "impact_and_rotation";
    } else if (sample.impact && sample.tilt) {
        event_.detectionReason = "impact_and_tilt";
    } else if (sample.rotation && sample.tilt) {
        event_.detectionReason = "rotation_and_tilt";
    } else {
        event_.detectionReason = "sustained_tilt";
    }

    triggered_ = true;
    armed_ = false;
    triggeredAtMs_ = now;
    calmSinceMs_ = 0;
    hits_ = 0;
    misses_ = 0;
    fallTiltActive_ = false;
    fallTiltStartedAtMs_ = 0;
    fallHadImpact_ = false;
    fallHadRotation_ = false;

    Serial.printf("[mpu] crash pattern: %.2f g peak, %.0f dps peak, %.0f deg from upright\n",
                  (double)peakMagnitudeG_, (double)peakRotationDps_, (double)peakTiltDegrees_);
}

/**
 * Upright, still, and not turning. Used only to decide when the detector may
 * arm again, so it is deliberately looser than the trigger thresholds.
 */
bool AccidentDetector::calm() const {
    if (!present_ || !hasSample_ || consecutiveReadFailures_ > 0 || reading_.magnitudeG < 0.4f) {
        return false;
    }
    return fabsf(reading_.magnitudeG - 1.0f) <= ACCIDENT_CALM_MARGIN_G &&
           reading_.gyroMagnitudeDps <= ACCIDENT_CALM_ROTATION_DPS &&
    reading_.tiltDegrees < ACCIDENT_FALL_TILT_DEGREES;
}

AccidentArmState AccidentDetector::armState() const {
    if (!present_ || !hasSample_ || consecutiveReadFailures_ > 0 || reading_.magnitudeG < 0.4f) {
        return AccidentArmState::SensorMissing;
    }
    if (settling_) return AccidentArmState::Settling;
    if (armed_) return AccidentArmState::Armed;

    const uint32_t now = millis();
    if ((uint32_t)(now - triggeredAtMs_) < ACCIDENT_REARM_COOLDOWN_MS) {
        return AccidentArmState::Cooldown;
    }
    if (!calm() || calmSinceMs_ == 0) return AccidentArmState::WaitingForCalm;
    return AccidentArmState::Settling;
}

uint32_t AccidentDetector::rearmRemainingMs() const {
    if (!present_ || armed_) return 0;

    const uint32_t now = millis();
    if (settling_) {
        if (settlingStartMs_ == 0) return ACCIDENT_STARTUP_SETTLE_MS;
        const uint32_t elapsed = (uint32_t)(now - settlingStartMs_);
        return elapsed >= ACCIDENT_STARTUP_SETTLE_MS ? 0 : (ACCIDENT_STARTUP_SETTLE_MS - elapsed);
    }

    const uint32_t sinceTrigger = (uint32_t)(now - triggeredAtMs_);
    const uint32_t cooldownLeft =
        sinceTrigger >= ACCIDENT_REARM_COOLDOWN_MS ? 0 : ACCIDENT_REARM_COOLDOWN_MS - sinceTrigger;

    const uint32_t sinceCalm = calmSinceMs_ == 0 ? 0 : (uint32_t)(now - calmSinceMs_);
    const uint32_t calmLeft =
        sinceCalm >= ACCIDENT_REARM_CALM_MS ? 0 : ACCIDENT_REARM_CALM_MS - sinceCalm;

    return cooldownLeft > calmLeft ? cooldownLeft : calmLeft;
}

void AccidentDetector::rearm() {
    if (!present_ || !hasSample_ || consecutiveReadFailures_ > 0 || reading_.magnitudeG < 0.4f) {
        armed_ = false;
        Serial.println("[mpu] cannot re-arm: sensor missing, stale, or invalid readings");
        return;
    }
    armed_ = true;
    triggered_ = false;
    calmSinceMs_ = 0;
    hits_ = 0;
    misses_ = 0;
    fallTiltActive_ = false;
    fallTiltStartedAtMs_ = 0;
    fallHadImpact_ = false;
    fallHadRotation_ = false;
    previousMagnitudeG_ = reading_.magnitudeG;
    peakMagnitudeG_ = 0.0f;
    peakRotationDps_ = 0.0f;
    peakTiltDegrees_ = 0.0f;

    Serial.println("[mpu] detector re-armed");
}
