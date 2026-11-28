#pragma once

#include <stddef.h>
#include <stdint.h>

#include "AccidentDetector.h"
#include "GpsReceiver.h"
#include "HallSpeedSensor.h"

static constexpr uint8_t SENSOR_HISTORY_CAPACITY = 8;

struct SensorHistorySample {
    uint32_t capturedAtMs = 0;
    HallSpeedReading hall;
    GpsFix gps;
    bool gpsFresh = false;
    AccidentReading mpu;
    bool mpuFresh = false;
};

struct AccidentReportContext {
    char eventId[33] = {};
    char eventTimestamp[25] = {};
    AccidentEvent event;
    HallSpeedReading hallSpeed;
    HallSpeedReading hallSpeedBefore;
    bool hasHallSpeedBefore = false;
    GpsFix gpsFix;
    bool hasGpsFix = false;
    bool hasLastGpsFix = false;
    const char* gpsFixStatus = "unavailable";
    uint32_t eventAtMs = 0;
    uint8_t historyCount = 0;
    SensorHistorySample history[SENSOR_HISTORY_CAPACITY];
};

class SensorHistory {
public:
    void record(const SensorHistorySample& sample) {
        samples_[next_] = sample;
        next_ = (next_ + 1) % SENSOR_HISTORY_CAPACITY;
        if (count_ < SENSOR_HISTORY_CAPACITY) ++count_;
    }

    size_t copyRecent(SensorHistorySample* destination, size_t capacity) const {
        const size_t copied = count_ < capacity ? count_ : capacity;
        const size_t start = (next_ + SENSOR_HISTORY_CAPACITY - copied)
            % SENSOR_HISTORY_CAPACITY;
        for (size_t i = 0; i < copied; ++i) {
            destination[i] = samples_[(start + i) % SENSOR_HISTORY_CAPACITY];
        }
        return copied;
    }

private:
    SensorHistorySample samples_[SENSOR_HISTORY_CAPACITY];
    size_t next_ = 0;
    size_t count_ = 0;
};
