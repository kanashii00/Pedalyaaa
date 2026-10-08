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
     * A state-change guard sits in front of the pin: the same target angle that
     * is already being held is not re-commanded, because ESP32Servo keeps the
     * PWM running continuously and rewriting it only re-prints the Serial line.
     * The caller therefore gets `true` exactly when a new angle was commanded,
     * which is also when the physical-write log line is emitted.
     *
     * @return true when this call actually changed the commanded servo state.
     */
    bool writePhysical(int angle, const char* reason);

    /** A commanded move with the settle delay a real linkage needs (skipped when the angle is already held). */
    void move(int angle, bool locked, const char* message);

    void release();
    void lockAtConfiguredAngle();

    /**
     * One controlled mechanical stroke for an expiry transition.
     *
     * Normal enforcement runs this once per expiry; afterwards the same final
     * angle is simply re-requested each pass and the state-change guard in
     * writePhysical() keeps it from restarting the sweep or re-printing the
     * log on every loop. The stroke starts from the servo's current commanded
     * angle when it lies on the path, so a wheel that was already graduated
     * (or forced) to LOCK is never swept back out to RELEASE.
     */
    void performLockMovement();

    bool ready() const { return ready_; }
    bool locked() const { return locked_; }
    void setLocked(bool locked) { locked_ = locked; }

private:
    /** Sentinel: no angle commanded yet, so the next write always goes out. */
    static constexpr int kUncommandedAngle = -1;

    Servo servo_;
    bool ready_ = false;
    bool locked_ = false;
    /** Last angle handed to the servo; the state-change guard lives here. */
    int lastCommandedAngle_ = kUncommandedAngle;
};