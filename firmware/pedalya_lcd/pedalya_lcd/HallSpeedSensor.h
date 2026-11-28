#pragma once

#include <Arduino.h>
#include <math.h>

#include "config.h"

struct HallSpeedReading {
    float speedKmh = 0.0f;
    float wheelCircumferenceM = 0.0f;
    uint32_t pulseCount = 0;
    bool speedAvailable = false;
    const char* status = "unconfigured";
};

class HallSpeedSensor {
public:
    explicit HallSpeedSensor(uint8_t pin = HALL_SENSOR_PIN) : pin_(pin) {}

    void begin();
    void setWheelCircumferenceM(float circumferenceM);
    bool configured() const;
    HallSpeedReading reading() const;

private:
    static void pulseInterrupt(void* context);
    void recordPulse();

    uint8_t pin_;
    float wheelCircumferenceM_ = 0.0f;
    uint32_t configuredAtUs_ = 0;
    mutable portMUX_TYPE mux_ = portMUX_INITIALIZER_UNLOCKED;
    volatile uint32_t pulseCount_ = 0;
    volatile uint32_t lastPulseUs_ = 0;
    volatile uint32_t lastIntervalUs_ = 0;
    volatile uint32_t minimumPulseIntervalUs_ = HALL_DEBOUNCE_US;
};

inline void HallSpeedSensor::begin()
{
    pinMode(pin_, INPUT_PULLUP);
    attachInterruptArg(digitalPinToInterrupt(pin_), pulseInterrupt, this, FALLING);
}

inline void HallSpeedSensor::setWheelCircumferenceM(float circumferenceM)
{
    const bool valid = isfinite(circumferenceM)
        && circumferenceM >= HALL_MIN_WHEEL_CIRCUMFERENCE_M
        && circumferenceM <= HALL_MAX_WHEEL_CIRCUMFERENCE_M;

    portENTER_CRITICAL(&mux_);
    const bool wasConfigured = wheelCircumferenceM_ > 0.0f;
    const bool circumferenceChanged =
        wasConfigured && fabsf(wheelCircumferenceM_ - circumferenceM) > 0.001f;
    wheelCircumferenceM_ = valid ? circumferenceM : 0.0f;
    if (valid) {
        const uint32_t speedLimitIntervalUs =
            (uint32_t)ceilf(
                circumferenceM * 3600000.0f
                / (HALL_MAX_SPEED_KMH * HALL_PULSES_PER_REVOLUTION));
        minimumPulseIntervalUs_ = max((uint32_t)HALL_DEBOUNCE_US, speedLimitIntervalUs);
        if (!wasConfigured || circumferenceChanged) {
            configuredAtUs_ = micros();
        }
    } else {
        minimumPulseIntervalUs_ = HALL_DEBOUNCE_US;
    }
    portEXIT_CRITICAL(&mux_);
}

inline bool HallSpeedSensor::configured() const
{
    return wheelCircumferenceM_ > 0.0f;
}

inline HallSpeedReading HallSpeedSensor::reading() const
{
    HallSpeedReading result;
    const uint32_t nowUs = micros();
    uint32_t pulseCount;
    uint32_t lastPulseUs;
    uint32_t lastIntervalUs;
    float circumferenceM;

    portENTER_CRITICAL(&mux_);
    pulseCount = pulseCount_;
    lastPulseUs = lastPulseUs_;
    lastIntervalUs = lastIntervalUs_;
    circumferenceM = wheelCircumferenceM_;
    portEXIT_CRITICAL(&mux_);

    result.pulseCount = pulseCount;
    result.wheelCircumferenceM = circumferenceM;

    if (circumferenceM <= 0.0f) {
        result.status = "unconfigured";
        return result;
    }

    const uint32_t lastMotionUs = pulseCount > 0 ? lastPulseUs : configuredAtUs_;
    const uint32_t maximumStopTimeoutUs = HALL_MAX_STOP_TIMEOUT_MS * 1000UL;
    const uint32_t intervalGraceUs = lastIntervalUs >= maximumStopTimeoutUs / 2UL
        ? maximumStopTimeoutUs
        : lastIntervalUs * 2UL;
    const uint32_t minimumStopTimeoutUs = HALL_STOP_TIMEOUT_MS * 1000UL;
    const uint32_t stopTimeoutUs = intervalGraceUs > minimumStopTimeoutUs
        ? intervalGraceUs
        : minimumStopTimeoutUs;
    if ((uint32_t)(nowUs - lastMotionUs) >= stopTimeoutUs) {
        result.speedKmh = 0.0f;
        result.speedAvailable = true;
        result.status = "stopped";
        return result;
    }

    if (pulseCount < 2 || lastIntervalUs == 0) {
        result.status = "waiting";
        return result;
    }

    const float pulseIntervalUs = static_cast<float>(lastIntervalUs)
        * static_cast<float>(HALL_PULSES_PER_REVOLUTION);
    result.speedKmh = circumferenceM * 3600000.0f / pulseIntervalUs;
    result.speedAvailable = isfinite(result.speedKmh)
        && result.speedKmh <= HALL_MAX_SPEED_KMH;
    result.status = result.speedAvailable ? "active" : "waiting";
    return result;
}

inline void HallSpeedSensor::pulseInterrupt(void* context)
{
    static_cast<HallSpeedSensor*>(context)->recordPulse();
}

inline void HallSpeedSensor::recordPulse()
{
    const uint32_t pulseAtUs = micros();
    portENTER_CRITICAL_ISR(&mux_);

    if (pulseCount_ == 0) {
        lastPulseUs_ = pulseAtUs;
        pulseCount_++;
    } else {
        const uint32_t intervalUs = pulseAtUs - lastPulseUs_;
        if (intervalUs >= minimumPulseIntervalUs_) {
            lastIntervalUs_ = intervalUs;
            lastPulseUs_ = pulseAtUs;
            pulseCount_++;
        }
    }

    portEXIT_CRITICAL_ISR(&mux_);
}
