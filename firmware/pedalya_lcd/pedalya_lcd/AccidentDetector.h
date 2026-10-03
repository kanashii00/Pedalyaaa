#pragma once

#include <Arduino.h>

#include "config.h"

/**
 * One MPU6050 sample, already scaled out of raw counts and judged.
 *
 * The three flags are the independent questions "was it hit?", "is it
 * spinning?" and "is it down?". AccidentDetector decides what combination of
 * them is worth reporting.
 */
struct AccidentReading {
    float accelXG = 0.0f;
    float accelYG = 0.0f;
    float accelZG = 0.0f;
    float gyroXDps = 0.0f;
    float gyroYDps = 0.0f;
    float gyroZDps = 0.0f;

    // |a|. About 1 g for an upright bike that is merely rolling.
    float magnitudeG = 0.0f;
    // |w|, the spin rate regardless of which axis it is on.
    float gyroMagnitudeDps = 0.0f;
    // Change in |a| since the previous sample: the jerk term.
    float deltaMagnitudeG = 0.0f;
    // How far the bike is from upright, in degrees. 0 upright, 90 on its side.
    float tiltDegrees = 0.0f;

    bool impact = false;
    bool rotation = false;
    bool tilt = false;
};

/** Why the detector is or is not currently able to report a crash. */
enum class AccidentArmState : uint8_t {
    SensorMissing,  // no IMU answering, so nothing can be detected at all
    Armed,          // watching
    Cooldown,       // a crash was reported; serving out the quiet period
    WaitingForCalm, // quiet period over, but the bike is still being handled
    Settling,       // still and upright; counting down to usable again
};

/** The event that fired, with the peaks held over the samples that produced it. */
struct AccidentEvent {
    float peakMagnitudeG = 0.0f;
    float peakRotationDps = 0.0f;
    float peakTiltDegrees = 0.0f;
    float accelXG = 0.0f;
    float accelYG = 0.0f;
    float accelZG = 0.0f;
    float gyroXDps = 0.0f;
    float gyroYDps = 0.0f;
    float gyroZDps = 0.0f;
};

/**
 * MPU6050 accident detection.
 *
 * Owns the IMU on the shared I²C bus and nothing else. In particular it never
 * touches the servo or the buzzer: an accident is a *report*, and what happens
 * to the bike afterwards is the admin's decision, not the firmware's.
 *
 * Poll it as often as the loop runs; it samples on its own fixed cadence and
 * costs nothing in between.
 */
class AccidentDetector {
public:
    /** Probe and configure the IMU. False when nothing answers. */
    bool begin();

    /** True once a sensor has answered. The detector keeps re-probing while false. */
    bool present() const { return present_; }

    /** I²C address the sensor answered on, or 0 when none has. */
    uint8_t address() const { return address_; }

    /** Sample on cadence and re-evaluate. Safe to call every loop. */
    void poll();

    /** True once per event, until acknowledge() releases it. */
    bool triggered() const { return triggered_; }

    /** Peaks for the event that fired. Valid while triggered() is true. */
    const AccidentEvent& event() const { return event_; }

    /** Most recent sample and its verdict. */
    const AccidentReading& reading() const { return reading_; }

    /** Highest magnitude seen since the detector armed, in g. */
    float peakMagnitudeG() const { return peakMagnitudeG_; }

    /**
     * Counts per g the accelerometer is actually delivering.
     *
     * Verified against the part rather than assumed from the write, so a module
     * that stayed on its power-on range is still measured correctly.
     */
    float accelLsbPerG() const { return accelLsbPerG_; }

    /** Counts per deg/s the gyroscope is actually delivering. */
    float gyroLsbPerDps() const { return gyroLsbPerDps_; }

    /**
     * Whether the detector can report a crash right now, and if not, why.
     *
     * Exposed because "it fired once and then went quiet" is otherwise
     * indistinguishable from a dead sensor. The quiet period after a report is
     * deliberate, so it has to be visible to be believable.
     */
    AccidentArmState armState() const;

    /** Milliseconds until a disarmed detector is usable again. Zero when armed. */
    uint32_t rearmRemainingMs() const;

    /**
     * Release the latched event so the caller can report it.
     *
     * Leaves the detector in cooldown: the peaks stay readable until it
     * re-arms, so a caller may report them after acknowledging.
     */
    void acknowledge();

private:
    bool probe();
    bool configure(uint8_t address);
    bool writeRegister(uint8_t address, uint8_t reg, uint8_t value);
    bool readBlock(uint8_t address, uint8_t reg, uint8_t* dest, size_t length);
    void evaluate(const uint8_t* raw, uint32_t now);
    void fire(const AccidentReading& sample, uint32_t now);
    bool calm() const;
    void rearm();

    bool present_ = false;
    uint8_t address_ = 0;

    AccidentReading reading_;
    AccidentEvent event_;

    uint32_t lastSampleMs_ = 0;
    uint32_t lastProbeMs_ = 0;
    uint32_t triggeredAtMs_ = 0;
    uint32_t calmSinceMs_ = 0;

    bool triggered_ = false;
    bool armed_ = true;
    bool hasSample_ = false;
    uint8_t hits_ = 0;
    uint8_t misses_ = 0;
    uint8_t tiltHold_ = 0;

    float previousMagnitudeG_ = 0.0f;
    float peakMagnitudeG_ = 0.0f;
    float peakRotationDps_ = 0.0f;
    float peakTiltDegrees_ = 0.0f;

    // Scales read back off the part, not the values that were asked for.
    float accelLsbPerG_ = 4096.0f;
    float gyroLsbPerDps_ = 131.0f;
};