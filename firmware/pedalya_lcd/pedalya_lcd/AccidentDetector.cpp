#include "AccidentDetector.h"

#include "config.h"

#include <Wire.h>

namespace {

// Register addresses, from the MPU-6000/MPU-6050 register map.
constexpr uint8_t REG_SMPLRT_DIV = 0x19;
constexpr uint8_t REG_CONFIG = 0x1A;
constexpr uint8_t REG_ACCEL_CONFIG = 0x1B;
constexpr uint8_t REG_GYRO_CONFIG = 0x1C;
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
 */
bool AccidentDetector::configure(uint8_t address) {
    // Seeded with what was asked for, so a part whose registers cannot be read
    // at all falls back to the intended scale instead of a guessed one.
    uint8_t accelRange = ACCEL_CONFIG_8G;
    uint8_t gyroRange = GYRO_CONFIG_250DPS;
    bool rangeRead = false;

    for (uint8_t attempt = 0; attempt < CONFIG_ATTEMPTS; ++attempt) {
        writeRegister(address, REG_PWR_MGMT_1, PWR_MGMT_1_RUN);
        delay(10);
        writeRegister(address, REG_SMPLRT_DIV, SMPLRT_DIV_200HZ);
        writeRegister(address, REG_CONFIG, CONFIG_DLPF_44HZ);
        writeRegister(address, REG_ACCEL_CONFIG, ACCEL_CONFIG_8G);
        writeRegister(address, REG_GYRO_CONFIG, GYRO_CONFIG_250DPS);
        delay(10);

        accelRange = ACCEL_CONFIG_8G;
        gyroRange = GYRO_CONFIG_250DPS;
        if (readBlock(address, REG_ACCEL_CONFIG, &accelRange, 1) &&
            readBlock(address, REG_GYRO_CONFIG, &gyroRange, 1)) {
            rangeRead = true;
            if (accelRange == ACCEL_CONFIG_8G && gyroRange == GYRO_CONFIG_250DPS) {
                accelLsbPerG_ = ACCEL_LSB_PER_G;
                gyroLsbPerDps_ = GYRO_LSB_PER_DPS;
                return true;
            }
        }

        Serial.printf("[mpu] range not confirmed (accel 0x%02X, gyro 0x%02X), retry %u/%u\n",
                      (unsigned)accelRange, (unsigned)gyroRange,
                      (unsigned)(attempt + 1), (unsigned)CONFIG_ATTEMPTS);
        delay(20);
    }

    if (!rangeRead) {
        // Nothing could be read back, so the requested range is assumed to hold.
        accelLsbPerG_ = ACCEL_LSB_PER_G;
        gyroLsbPerDps_ = GYRO_LSB_PER_DPS;
        return true;
    }

    accelLsbPerG_ = accelLsbForRange(accelRange);
    gyroLsbPerDps_ = gyroLsbForRange(gyroRange);

    Serial.printf("[mpu] WARNING: the part would not accept +-8g/+-250dps "
                  "(accel 0x%02X, gyro 0x%02X).\n",
                  (unsigned)accelRange, (unsigned)gyroRange);
    Serial.printf("[mpu] Scaling to %.0f counts/g and %.1f counts/dps instead, so readings "
                  "stay accurate;\n",
                  (double)accelLsbPerG_, (double)gyroLsbPerDps_);
    Serial.println("[mpu] a crash beyond the fitted range will still be clipped by the sensor.");

    return true;
}

bool AccidentDetector::begin() {
    lastProbeMs_ = millis();
    return probe();
}

/**
 * Look for the IMU on both of its addresses and configure it.
 *
 * AD0 low is 0x68 and AD0 high is 0x69, so probing both means the module works
 * either way round on the bus. A part that is absent returns 0xFF and a
 * floating one returns 0x00, which is why WHO_AM_I is checked rather than
 * simply relying on the transfer succeeding.
 */
bool AccidentDetector::probe() {
    const uint8_t candidates[2] = {MPU6050_I2C_ADDRESS, 0x69};

    for (uint8_t index = 0; index < 2; ++index) {
        const uint8_t candidate = candidates[index];
        if (index == 1 && candidate == candidates[0]) continue;  // already tried

        uint8_t whoAmI = 0;
        if (!readBlock(candidate, REG_WHO_AM_I, &whoAmI, 1)) continue;
        if (whoAmI == 0x00 || whoAmI == 0xFF) continue;

        if (!configure(candidate)) continue;

        address_ = candidate;
        present_ = true;
        hasSample_ = false;
        Serial.printf("[mpu] MPU6050 at 0x%02X (id 0x%02X); %.0f counts/g, %.1f counts/dps\n",
                      (unsigned)address_, (unsigned)whoAmI,
                      (double)accelLsbPerG_, (double)gyroLsbPerDps_);
        return true;
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

    if ((uint32_t)(now - lastSampleMs_) >= ACCIDENT_SAMPLE_INTERVAL_MS) {
        lastSampleMs_ = now;

        uint8_t raw[BURST_BYTES];
        if (readBlock(address_, REG_ACCEL_XOUT_H, raw, sizeof(raw))) {
            evaluate(raw, now);
        }
        // A failed transfer leaves the previous sample standing. Dropping the
        // sample is the point: a garbled reading must never raise an alert.
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
    // Scaled by what the part reports it is set to, not by what was requested.
    sample.accelXG = (int16_t)((raw[0] << 8) | raw[1]) / accelLsbPerG_;
    sample.accelYG = (int16_t)((raw[2] << 8) | raw[3]) / accelLsbPerG_;
    sample.accelZG = (int16_t)((raw[4] << 8) | raw[5]) / accelLsbPerG_;
    // raw[6] and raw[7] are the temperature block; not used here.
    sample.gyroXDps = (int16_t)((raw[8] << 8) | raw[9]) / gyroLsbPerDps_;
    sample.gyroYDps = (int16_t)((raw[10] << 8) | raw[11]) / gyroLsbPerDps_;
    sample.gyroZDps = (int16_t)((raw[12] << 8) | raw[13]) / gyroLsbPerDps_;

    sample.magnitudeG = sqrtf(square(sample.accelXG) + square(sample.accelYG) +
                              square(sample.accelZG));
    sample.gyroMagnitudeDps = sqrtf(square(sample.gyroXDps) + square(sample.gyroYDps) +
                                    square(sample.gyroZDps));

    // The bike is upright while it is being ridden, so gravity lies along the
    // vertical axis and the lateral axes are near zero. atan2 turns that into
    // an angle that reads 0 upright and 90 on its side, which is what an admin
    // can picture; a raw per-axis threshold cannot do that.
    const float lateralG = sqrtf(square(sample.accelXG) + square(sample.accelYG));
    sample.tiltDegrees = atan2f(lateralG, fabsf(sample.accelZG)) * RAD_TO_DEG;

    if (hasSample_) sample.deltaMagnitudeG = fabsf(sample.magnitudeG - previousMagnitudeG_);

    // Three independent ways to be hit, because each one alone misses a case
    // the others catch: a hard upper bound, a fast change, and gravity lost.
    sample.impact = sample.magnitudeG >= ACCIDENT_IMPACT_G ||
                    sample.deltaMagnitudeG >= ACCIDENT_JERK_G ||
                    sample.magnitudeG <= ACCIDENT_FREEFALL_G;
    sample.rotation = sample.gyroMagnitudeDps >= ACCIDENT_ROTATION_DPS;
    sample.tilt = sample.tiltDegrees >= ACCIDENT_TILT_DEGREES;

    if (sample.tilt) {
        if (tiltHold_ < UINT8_MAX) ++tiltHold_;
    } else {
        tiltHold_ = 0;
    }

    reading_ = sample;
    previousMagnitudeG_ = sample.magnitudeG;
    hasSample_ = true;

    if (!armed_) return;

    if (sample.magnitudeG > peakMagnitudeG_) peakMagnitudeG_ = sample.magnitudeG;
    if (sample.gyroMagnitudeDps > peakRotationDps_) peakRotationDps_ = sample.gyroMagnitudeDps;
    if (sample.tiltDegrees > peakTiltDegrees_) peakTiltDegrees_ = sample.tiltDegrees;

    // A hard signal alone is never enough, because normal riding makes hard
    // signals constantly. Either a hit agrees with the bike's attitude, the two
    // agree with each other, the hit is violent enough to stand alone, or the
    // bike has simply been lying on its side for a while.
    const bool candidate =
        (sample.impact && (sample.rotation || sample.tilt)) ||
        (sample.rotation && sample.tilt) ||
        sample.magnitudeG >= ACCIDENT_HARD_IMPACT_G ||
        (sample.tilt && tiltHold_ >= (uint8_t)ACCIDENT_TILT_HOLD_SAMPLES);

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

    triggered_ = true;
    armed_ = false;
    triggeredAtMs_ = now;
    calmSinceMs_ = 0;
    hits_ = 0;
    misses_ = 0;
    tiltHold_ = 0;

    Serial.printf("[mpu] crash pattern: %.2f g peak, %.0f dps peak, %.0f deg from upright\n",
                  (double)peakMagnitudeG_, (double)peakRotationDps_, (double)peakTiltDegrees_);
}

/**
 * Upright, still, and not turning. Used only to decide when the detector may
 * arm again, so it is deliberately looser than the trigger thresholds.
 */
bool AccidentDetector::calm() const {
    return fabsf(reading_.magnitudeG - 1.0f) <= ACCIDENT_CALM_MARGIN_G &&
           reading_.gyroMagnitudeDps <= ACCIDENT_CALM_ROTATION_DPS &&
           reading_.tiltDegrees < ACCIDENT_TILT_DEGREES;
}

AccidentArmState AccidentDetector::armState() const {
    if (!present_) return AccidentArmState::SensorMissing;
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

    const uint32_t sinceTrigger = (uint32_t)(now - triggeredAtMs_);
    const uint32_t cooldownLeft =
        sinceTrigger >= ACCIDENT_REARM_COOLDOWN_MS ? 0 : ACCIDENT_REARM_COOLDOWN_MS - sinceTrigger;

    const uint32_t sinceCalm = calmSinceMs_ == 0 ? 0 : (uint32_t)(now - calmSinceMs_);
    const uint32_t calmLeft =
        sinceCalm >= ACCIDENT_REARM_CALM_MS ? 0 : ACCIDENT_REARM_CALM_MS - sinceCalm;

    return cooldownLeft > calmLeft ? cooldownLeft : calmLeft;
}

void AccidentDetector::rearm() {
    armed_ = true;
    triggered_ = false;
    calmSinceMs_ = 0;
    hasSample_ = false;
    hits_ = 0;
    misses_ = 0;
    tiltHold_ = 0;
    previousMagnitudeG_ = 0.0f;
    peakMagnitudeG_ = 0.0f;
    peakRotationDps_ = 0.0f;
    peakTiltDegrees_ = 0.0f;

    Serial.println("[mpu] detector re-armed");
}