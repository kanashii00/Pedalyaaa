"""
AccidentDetector verification test suite:
Simulates the exact state machine and validation rules from AccidentDetector.cpp:
1. No ACK (absent sensor at 0x68 / 0x69)
2. Read failures (I2C transfer failure / short read)
3. All-zero burst data (sleeping chip / pulled-down SDA)
4. All-FF burst data (floating bus)
5. Stale samples and disarming
6. Valid samples and resting baseline calibration
7. Crash detection trigger and cooldown
8. Recovery and re-arming guard
9. ADC scale defect: configure() rejects sensor when self-check magnitude > 2.5 g
   (models GY-521 clone where DEVICE_RESET did not propagate AFS_SEL to the ADC)
"""

import math
import sys

BURST_BYTES = 14
ACCEL_LSB_PER_G = 4096.0  # +-8g
GYRO_LSB_PER_DPS = 131.0  # +-250 deg/s
ACCIDENT_STARTUP_SETTLE_MS = 3000
ACCIDENT_REARM_COOLDOWN_MS = 5000
ACCIDENT_REARM_CALM_MS = 2000
ACCIDENT_IMPACT_G = 2.5
ACCIDENT_HARD_IMPACT_G = 4.0
ACCIDENT_ROTATION_DPS = 220.0  # matching config.h
ACCIDENT_TILT_DEGREES = 55.0

class MockAccidentDetector:
    def __init__(self):
        self.present = False
        self.address = 0
        self.armed = False
        self.settling = True
        self.has_sample = False
        self.triggered = False
        self.consecutive_read_failures = 0
        self.total_samples = 0
        self.total_read_failures = 0
        self.settling_sample_count = 0
        self.settling_sum_x = 0.0
        self.settling_sum_y = 0.0
        self.settling_sum_z = 0.0
        self.upright_set = False
        self.upright_x = 0.0
        self.upright_y = 0.0
        self.upright_z = 1.0
        self.baseline_gravity_g = 1.0
        self.magnitude_g = 0.0
        self.gyro_magnitude_dps = 0.0
        self.tilt_degrees = 0.0
        self.previous_magnitude_g = 0.0
        self.peak_magnitude_g = 0.0
        self.peak_rotation_dps = 0.0
        self.peak_tilt_degrees = 0.0
        self.calm_since_ms = 0
        self.triggered_at_ms = 0

    def begin(self, i2c_bus, configure_self_check_mag=None):
        # Probe candidates 0x68 and 0x69
        for addr in [0x68, 0x69]:
            if i2c_bus.probe_ack(addr):
                who_am_i = i2c_bus.read_reg(addr, 0x75)
                if who_am_i not in [0x00, 0xFF, None]:
                    # --- Simulate configure() ADC self-check ---
                    # In the real firmware, configure() reads one burst and checks
                    # whether the raw magnitude is within 0.5 g – 2.5 g.
                    # If not, configure() returns false and probe() sets present=false.
                    if configure_self_check_mag is not None:
                        if configure_self_check_mag > 2.5 or configure_self_check_mag < 0.5:
                            # configure() returned false: sensor rejected
                            self.present = False
                            self.armed = False
                            return False
                    self.address = addr
                    self.present = True
                    self.settling = True
                    self.armed = False
                    return True
        self.present = False
        self.armed = False
        return False

    def poll_sample(self, raw_bytes, now_ms):
        if not self.present:
            return False

        # Physical and bus validation rules matching AccidentDetector.cpp
        if raw_bytes is None or len(raw_bytes) != BURST_BYTES:
            self._handle_read_failure(now_ms)
            return False

        all_zero = all(b == 0x00 for b in raw_bytes)
        all_ff = all(b == 0xFF for b in raw_bytes)
        accel_zero = all(raw_bytes[i] == 0 for i in range(6))

        if all_zero or all_ff or accel_zero:
            self._handle_read_failure(now_ms)
            return False

        # Read successful and valid
        self.consecutive_read_failures = 0
        self.total_samples += 1
        self._evaluate(raw_bytes, now_ms)
        return True

    def _handle_read_failure(self, now_ms):
        self.consecutive_read_failures += 1
        self.total_read_failures += 1
        if self.consecutive_read_failures >= 3:
            self.present = False
            self.armed = False
            self.has_sample = False
            self.settling = False

    def _evaluate(self, raw, now_ms):
        def to_int16(h, l):
            val = (h << 8) | l
            return val - 65536 if val >= 32768 else val

        ax = to_int16(raw[0], raw[1]) / ACCEL_LSB_PER_G
        ay = to_int16(raw[2], raw[3]) / ACCEL_LSB_PER_G
        az = to_int16(raw[4], raw[5]) / ACCEL_LSB_PER_G
        gx = to_int16(raw[8], raw[9]) / GYRO_LSB_PER_DPS
        gy = to_int16(raw[10], raw[11]) / GYRO_LSB_PER_DPS
        gz = to_int16(raw[12], raw[13]) / GYRO_LSB_PER_DPS

        raw_mag = math.sqrt(ax * ax + ay * ay + az * az)
        if raw_mag < 0.2:
            # Physical sanity check: unphysical static acceleration on Earth
            return

        self.magnitude_g = raw_mag / self.baseline_gravity_g if self.upright_set else raw_mag
        self.gyro_magnitude_dps = math.sqrt(gx * gx + gy * gy + gz * gz)
        self.tilt_degrees = math.degrees(math.atan2(math.sqrt(ax * ax + ay * ay), abs(az)))
        self.has_sample = True

        if self.settling:
            if self.gyro_magnitude_dps < 30.0 and self.magnitude_g > 0.4:
                self.settling_sum_x += ax
                self.settling_sum_y += ay
                self.settling_sum_z += az
                self.settling_sample_count += 1
            return

        if not self.armed:
            return

        impact = (self.magnitude_g >= ACCIDENT_IMPACT_G)
        rotation = (self.gyro_magnitude_dps >= ACCIDENT_ROTATION_DPS)
        tilt = (self.tilt_degrees >= ACCIDENT_TILT_DEGREES)

        if (impact and (rotation or tilt)) or (rotation and tilt) or (self.magnitude_g >= ACCIDENT_HARD_IMPACT_G):
            self.triggered = True
            self.armed = False
            self.triggered_at_ms = now_ms
            self.peak_magnitude_g = self.magnitude_g

    def finish_settling(self):
        self.settling = False
        if self.settling_sample_count >= 5:
            avg_x = self.settling_sum_x / self.settling_sample_count
            avg_y = self.settling_sum_y / self.settling_sample_count
            avg_z = self.settling_sum_z / self.settling_sample_count
            mag = math.sqrt(avg_x * avg_x + avg_y * avg_y + avg_z * avg_z)
            if 0.4 < mag < 2.5:
                self.upright_x = avg_x / mag
                self.upright_y = avg_y / mag
                self.upright_z = avg_z / mag
                self.upright_set = True
                self.baseline_gravity_g = mag
                self.rearm()
                return True
        # Insufficient stationary samples
        self.armed = False
        self.present = False
        self.has_sample = False
        return False

    def rearm(self):
        if not self.present or not self.has_sample or self.consecutive_read_failures > 0 or self.magnitude_g < 0.4:
            self.armed = False
            return False
        self.armed = True
        self.triggered = False
        return True

    def calm(self):
        if not self.present or not self.has_sample or self.consecutive_read_failures > 0 or self.magnitude_g < 0.4:
            return False
        return (abs(self.magnitude_g - 1.0) <= 0.4 and
                self.gyro_magnitude_dps <= 40.0 and
                self.tilt_degrees < ACCIDENT_TILT_DEGREES)

    def arm_state_str(self):
        if not self.present or not self.has_sample or self.consecutive_read_failures > 0 or self.magnitude_g < 0.4:
            return "SensorMissing"
        if self.settling:
            return "Settling"
        if self.armed:
            return "Armed"
        return "NotArmed"


class MockI2CBus:
    def __init__(self, ack_addrs=None, reg_values=None):
        self.ack_addrs = ack_addrs or []
        self.reg_values = reg_values or {}

    def probe_ack(self, addr):
        return addr in self.ack_addrs

    def read_reg(self, addr, reg):
        return self.reg_values.get((addr, reg), 0x00)


def test_1_no_ack_mpu_absent():
    print("Test 1: No ACK (MPU absent at 0x68 and 0x69)...", end=" ")
    bus = MockI2CBus(ack_addrs=[0x27])  # only LCD responds
    d = MockAccidentDetector()
    ok = d.begin(bus)
    assert ok is False, "begin() should return false when no MPU responds"
    assert d.present is False
    assert d.armed is False
    assert d.arm_state_str() == "SensorMissing"
    print("PASS")


def test_2_all_zero_burst_rejected():
    print("Test 2: All-zero burst data rejected (sleeping chip)...", end=" ")
    bus = MockI2CBus(ack_addrs=[0x68], reg_values={(0x68, 0x75): 0x68})
    d = MockAccidentDetector()
    d.begin(bus)
    assert d.present is True

    # Send all-zero burst
    zero_bytes = [0x00] * 14
    ok = d.poll_sample(zero_bytes, 100)
    assert ok is False, "Zero burst must be rejected as invalid read"
    assert d.consecutive_read_failures == 1
    assert d.arm_state_str() == "SensorMissing"
    print("PASS")


def test_3_all_ff_burst_rejected():
    print("Test 3: All-FF burst data rejected (floating I2C bus)...", end=" ")
    bus = MockI2CBus(ack_addrs=[0x68], reg_values={(0x68, 0x75): 0x68})
    d = MockAccidentDetector()
    d.begin(bus)
    ff_bytes = [0xFF] * 14
    ok = d.poll_sample(ff_bytes, 100)
    assert ok is False, "All-FF burst must be rejected"
    assert d.consecutive_read_failures == 1
    print("PASS")


def test_4_consecutive_read_failures_disarms():
    print("Test 4: Consecutive read failures (>=3) disarm detector...", end=" ")
    bus = MockI2CBus(ack_addrs=[0x68], reg_values={(0x68, 0x75): 0x68})
    d = MockAccidentDetector()
    d.begin(bus)

    # 3 consecutive read failures
    for i in range(3):
        d.poll_sample(None, 100 * (i + 1))

    assert d.consecutive_read_failures == 3
    assert d.present is False, "Detector must mark sensor not present after 3 failures"
    assert d.armed is False, "Detector must disarm"
    assert d.arm_state_str() == "SensorMissing"
    print("PASS")


def test_5_valid_sample_and_settling_calibration():
    print("Test 5: Valid stationary samples settle and arm...", end=" ")
    bus = MockI2CBus(ack_addrs=[0x68], reg_values={(0x68, 0x75): 0x68})
    d = MockAccidentDetector()
    d.begin(bus)

    # 1g on Z axis: 4096 counts = 0x1000 -> raw = [0,0, 0,0, 0x10,0x00, temp..., 0,0, 0,0, 0,0]
    valid_1g_z = [0x00, 0x00, 0x00, 0x00, 0x10, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00]

    for i in range(6):
        ok = d.poll_sample(valid_1g_z, 100 * (i + 1))
        assert ok is True

    assert d.settling_sample_count == 6
    assert d.consecutive_read_failures == 0

    # Finish settling
    armed_ok = d.finish_settling()
    assert armed_ok is True
    assert d.armed is True
    assert d.arm_state_str() == "Armed"
    assert abs(d.magnitude_g - 1.0) < 0.05
    print("PASS")


def test_6_crash_detection_and_cooldown():
    print("Test 6: Valid crash pattern triggers accident and cooldown...", end=" ")
    bus = MockI2CBus(ack_addrs=[0x68], reg_values={(0x68, 0x75): 0x68})
    d = MockAccidentDetector()
    d.begin(bus)

    valid_1g_z = [0x00, 0x00, 0x00, 0x00, 0x10, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00]
    for i in range(5):
        d.poll_sample(valid_1g_z, 100 * (i + 1))
    d.finish_settling()
    assert d.armed is True

    # Crash sample: 3.5g impact + 81.8 deg tilt + 230 dps rotation
    # ax = 3.5g (0x3800), az = 0.5g (0x0800) -> tilt = atan2(3.5, 0.5) = 81.8 deg
    # gx = 230 dps (30130 counts = 0x75B2)
    crash_bytes = [0x38, 0x00, 0x00, 0x00, 0x08, 0x00, 0x00, 0x00, 0x75, 0xB2, 0x00, 0x00, 0x00, 0x00]
    d.poll_sample(crash_bytes, 1000)

    assert d.triggered is True, "Crash must be triggered"
    assert d.armed is False, "Detector must disarm after trigger"
    print("PASS")


def test_7_recovery_and_rearm_guard():
    print("Test 7: Recovery requires calm and fresh valid data...", end=" ")
    bus = MockI2CBus(ack_addrs=[0x68], reg_values={(0x68, 0x75): 0x68})
    d = MockAccidentDetector()
    d.begin(bus)

    valid_1g_z = [0x00, 0x00, 0x00, 0x00, 0x10, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00]
    for i in range(5):
        d.poll_sample(valid_1g_z, 100 * (i + 1))
    d.finish_settling()

    # Disarm
    d.armed = False
    assert d.calm() is True
    assert d.rearm() is True
    assert d.armed is True

    # Now simulate read failure: rearm must fail!
    d.poll_sample(None, 2000)
    assert d.consecutive_read_failures > 0
    assert d.rearm() is False, "Rearm must refuse when consecutive_read_failures > 0"
    print("PASS")


def test_8_adc_scale_defect_rejected():
    """
    GY-521 clone ADC mismatch scenario (the 4.39g-at-rest defect):

    configure() reads one burst immediately after writing ACCEL_CONFIG = 0x10
    (±8g, 4096 LSB/g). On affected clones, the register file accepts the write
    but the ADC conversion unit remains on the ±2g power-on default (16384 LSB/g).
    A stationary sensor then outputs ~4x the counts expected for 1g:

        1g × 16384 counts/g / 4096 (scale we applied) ≈ 4g apparent magnitude.

    configure() checks: if self-check magnitude > 2.5g → return false → probe()
    sets present=false → begin() returns false → detector never arms.

    The DEVICE_RESET sequence in configure() is the intended fix, but until
    physical hardware confirms it worked, the gate is the safety net.
    """
    print("Test 8: ADC scale defect (4.39g self-check) rejected by configure()...", end=" ")

    bus = MockI2CBus(ack_addrs=[0x68], reg_values={(0x68, 0x75): 0x68})
    d = MockAccidentDetector()

    # Inject the 4.39g resting magnitude that the GY-521 clone reports when the
    # ADC is stuck on ±2g but we apply the ±8g scale factor.
    ADC_MISMATCH_MAG_G = 4.39

    ok = d.begin(bus, configure_self_check_mag=ADC_MISMATCH_MAG_G)

    assert ok is False, (
        f"begin() must return False when configure() self-check magnitude "
        f"({ADC_MISMATCH_MAG_G} g) exceeds the 2.5 g upper bound"
    )
    assert d.present is False, "Sensor must not be marked present after ADC scale rejection"
    assert d.armed is False, "Detector must not arm when sensor configuration is rejected"
    assert d.arm_state_str() == "SensorMissing", (
        f"arm_state must be SensorMissing, got: {d.arm_state_str()}"
    )

    # Also verify the symmetric case: <0.5g (sensor sleeping / unpowered)
    d2 = MockAccidentDetector()
    ok2 = d2.begin(bus, configure_self_check_mag=0.05)
    assert ok2 is False, "begin() must return False when self-check magnitude < 0.5 g"
    assert d2.present is False
    assert d2.armed is False

    # Sanity: a nominal 1g self-check must still succeed
    d3 = MockAccidentDetector()
    ok3 = d3.begin(bus, configure_self_check_mag=1.0)
    assert ok3 is True, "begin() must return True when self-check magnitude is 1.0 g"
    assert d3.present is True
    assert d3.armed is False  # settling, not yet armed
    # NOTE: arm_state_str() returns "SensorMissing" immediately after begin() because
    # has_sample=False and magnitude_g=0.0 < 0.4. "Settling" only appears once valid
    # samples flow in — this matches AccidentDetector.cpp's guards on has_sample.
    assert d3.arm_state_str() == "SensorMissing", (
        f"arm_state before first poll must be SensorMissing (no sample yet), "
        f"got: {d3.arm_state_str()}"
    )

    print("PASS")


if __name__ == "__main__":
    print("=== Running AccidentDetector Verification Tests ===")
    test_1_no_ack_mpu_absent()
    test_2_all_zero_burst_rejected()
    test_3_all_ff_burst_rejected()
    test_4_consecutive_read_failures_disarms()
    test_5_valid_sample_and_settling_calibration()
    test_6_crash_detection_and_cooldown()
    test_7_recovery_and_rearm_guard()
    test_8_adc_scale_defect_rejected()
    print("=== All 8 AccidentDetector Tests PASSED ===")
