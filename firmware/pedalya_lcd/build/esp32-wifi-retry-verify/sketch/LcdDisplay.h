#line 1 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\LcdDisplay.h"
#pragma once

#include <Arduino.h>

#include "PedalyaApi.h"

class LcdDisplay {
public:
    void begin();
    void splash();
    void showIdle();
    void showWifiConnecting();
    void showNoServer(const String& reason);
    void showRental(const RentalState& state);
    void showExpiryWarning(const RentalState& state);
    void showExpired();
    void showMessage(const String& line1, const String& line2);
    void setErrorLed(bool on);

private:
    void row(uint8_t index, const char* text);
    void rowf(uint8_t index, const char* format, ...);
};
