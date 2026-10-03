#pragma once

#include <Arduino.h>
#include <stdlib.h>
#include <string.h>

#include "config.h"

struct GpsFix {
    double latitude = 0;
    double longitude = 0;
    float speedKph = 0;
    float heading = 0;
    uint32_t receivedAtMs = 0;
};

/** Minimal checksummed NMEA RMC reader; no additional Arduino library needed. */
class GpsReceiver {
public:
    void begin() {
        serial_.begin(GPS_BAUD_RATE, SERIAL_8N1, GPS_RX_PIN, GPS_TX_PIN);
    }

    void update() {
        while (serial_.available()) {
            const char c = (char)serial_.read();
            if (c == '\n' || c == '\r') {
                if (lineLength_ > 0) {
                    line_[lineLength_] = '\0';
                    parseRmc(line_);
                    lineLength_ = 0;
                }
            } else if (lineLength_ < sizeof(line_) - 1) {
                line_[lineLength_++] = c;
            } else {
                lineLength_ = 0;
            }
        }

        if (fixValid_ && (uint32_t)(millis() - fix_.receivedAtMs) > GPS_FIX_STALE_MS) {
            fixValid_ = false;
        }
    }

    bool hasFreshFix() const { return fixValid_; }
    const GpsFix& fix() const { return fix_; }

private:
    HardwareSerial serial_{1};
    char line_[128] = {};
    size_t lineLength_ = 0;
    GpsFix fix_;
    bool fixValid_ = false;

    static bool coordinate(const char* value, const char* hemisphere, double& result) {
        if (!value || !*value || !hemisphere || !*hemisphere) return false;
        const double raw = strtod(value, nullptr);
        const double degrees = (double)((int)(raw / 100.0));
        const double minutes = raw - degrees * 100.0;
        if (minutes < 0 || minutes >= 60) return false;
        result = degrees + minutes / 60.0;
        if (hemisphere[0] == 'S' || hemisphere[0] == 'W') result = -result;
        return true;
    }

    void parseRmc(char* sentence) {
        if (sentence[0] != '$') return;
        char* checksum = strchr(sentence, '*');
        if (!checksum || strlen(checksum + 1) < 2) return;

        uint8_t computed = 0;
        for (char* p = sentence + 1; p < checksum; ++p) computed ^= (uint8_t)*p;
        char* end = nullptr;
        const unsigned long expected = strtoul(checksum + 1, &end, 16);
        if (end == checksum + 1 || computed != (uint8_t)expected) return;

        *checksum = '\0';
        char* fields[10] = {};
        size_t count = 0;
        char* field = sentence;
        while (field && count < 10) {
            fields[count++] = field;
            char* comma = strchr(field, ',');
            if (!comma) break;
            *comma = '\0';
            field = comma + 1;
        }

        if (count < 9 || (strcmp(fields[0], "$GPRMC") != 0 && strcmp(fields[0], "$GNRMC") != 0)) return;
        if (fields[2][0] != 'A') {
            fixValid_ = false;
            return;
        }
        if ((fields[4][0] != 'N' && fields[4][0] != 'S') ||
            (fields[6][0] != 'E' && fields[6][0] != 'W')) return;

        GpsFix next;
        if (!coordinate(fields[3], fields[4], next.latitude) ||
            !coordinate(fields[5], fields[6], next.longitude) ||
            next.latitude < -90 || next.latitude > 90 ||
            next.longitude < -180 || next.longitude > 180) return;

        next.speedKph = strtof(fields[7], nullptr) * 1.852f;
        next.heading = strtof(fields[8], nullptr);
        next.receivedAtMs = millis();
        fix_ = next;
        fixValid_ = true;
    }
};
