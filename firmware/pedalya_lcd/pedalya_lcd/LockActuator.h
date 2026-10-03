#pragma once

#include <Arduino.h>
#include <ESP32Servo.h>

/**
 * The wheel lock: one servo on SERVO_PIN, and the only code that drives it.
 *
 * GPIO18 carries the PWM signal alone. Power the servo from a separate regulated
 * 5-6 V supply and bond that supply's GND to ESP32 GND.
 */
class LockActuator {
public:
    /** Attaches the servo at the configured pin and pulse widths. */
    bool attach();

    /**
     * Attaches, but only once.
     *
     * Called after the first successful authenticated poll, so booting without a
     * server cannot move the physical lock.
     */
    void attachAfterServerSync();

    /**
     * The only normal-operation path that drives SERVO_PIN.
     *
     * This deliberately has no locked-state guard: the Serial line documents the
     * exact ESP32Servo command being issued to the physical pin, and enforcement
     * calls it on every pass so a loose horn or a brownout cannot survive.
     */
    void writePhysical(int angle, const char* reason);

    /** A commanded move with the settle delay a real linkage needs. */
    void move(int angle, bool locked, const char* message);

    void release();
    void lockAtConfiguredAngle();

    /**
     * One controlled mechanical stroke for an expiry transition.
     *
     * Normal enforcement calls this once, then keeps commanding the final angle
     * without restarting the sweep on every loop.
     */
    void performLockMovement();

    bool ready() const { return ready_; }
    bool locked() const { return locked_; }
    void setLocked(bool locked) { locked_ = locked; }

private:
    Servo servo_;
    bool ready_ = false;
    bool locked_ = false;
};