#include "RentalTimer.h"

#include "config.h"

void RentalTimer::start(uint32_t serverOverdueSeconds) {
    active_ = true;
    startMs_ = millis() - serverOverdueSeconds * 1000UL;
    seconds_ = serverOverdueSeconds;

    Serial.println("[OVERDUE] Rental expired");
    Serial.printf("[OVERDUE] %lu-second grace period started\n",
                  (unsigned long)RETURN_GRACE_SECONDS);
}

void RentalTimer::reset() {
    active_ = false;
    startMs_ = 0;
    seconds_ = 0;
}

void RentalTimer::update() {
    if (!active_) return;

    seconds_ = (uint32_t)(millis() - startMs_) / 1000UL;
}

uint32_t RentalTimer::graceSecondsLeft() const {
    if (!active_ || seconds_ >= RETURN_GRACE_SECONDS) {
        return 0;
    }
    return RETURN_GRACE_SECONDS - seconds_;
}

uint32_t RentalTimer::elapsedMs() const {
    return active_ ? (uint32_t)(millis() - startMs_) : 0;
}