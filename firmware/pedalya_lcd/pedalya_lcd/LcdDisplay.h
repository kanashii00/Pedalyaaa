#pragma once

#include <Arduino.h>

#include "PedalyaApi.h"

enum class SystemStatus {
    Starting,
    WifiConnecting,
    WifiConnected,
    ServerCheck,
    ServerOnline,
    ServerOffline,
    ModemConnecting,
    ModemOnline,
    ModemOffline,
    AuthFailed,
    NoNetwork,
    ModemInit,
    WaitingForService,
    CellularConnecting,
    CellularOnline,
    ApiOffline,
    Reconnecting
};

class LcdDisplay {
public:
    void begin();
    // Core truthful LCD screens per specification:
    // 1. Startup screen: PEDALYA branding, Device/bicycle identifier, Initializing system status
    void showStartup(const char* step, const char* bicycleId = nullptr);

    // 2. Ready screen: Bicycle ID, Wi-Fi/Modem/Server connectivity indicators, availability, lock state, battery
    void showReady(const char* bicycleId, bool wifiUp, bool simReady, bool simActive,
                   bool serverVerified, bool locked, int batteryLevel, const char* bikeStatus);

    // 3. Active rental screen: Rental status, remaining time (HH:MM:SS), lock state
    void showRental(const RentalState& state, bool locked = false);

    // 4. Overdue / Expired screen: grace countdown, alternating overdue time & penalty, lock state
    void showExpiredPhase(uint32_t secondsSinceExpiry, float penalty, bool locked = true);

    // 5. Connection / Error screen: Network & Server state, concise error message, recovery status
    void showConnectionError(const char* line1, const char* line2);

    // 6. Safety event screen: Accident alert, non-blocking delivery status
    void showAccidentAlert(bool reported, bool hasGps);

    // Compatibility methods for existing callers:
    void showStatus(SystemStatus status, const char* bicycleId = nullptr);
    void showServerState(bool serverVerified);
    void splash();
    void showIdle();
    void showWifiConnecting();
    void showDeviceStatus(const char* line2);
    void showNoServer(const String& reason);
    void showKeyRejected(const String& reason);
    void showExpiryWarning(const RentalState& state);
    void showExpired();

    void showMessage(const String& line1, const String& line2);
    void showMessageFor(uint32_t durationMs, const String& line1, const String& line2);
    bool messageActive() const;

    void setErrorLed(bool on);

private:
    void row(uint8_t index, const char* text);
    void rowf(uint8_t index, const char* format, ...);

    uint32_t messageUntilMs_ = 0;
};
