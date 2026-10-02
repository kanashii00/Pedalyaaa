<?php

namespace Tests\Concerns;

use App\Models\Bicycle;
use App\Models\MaintenanceRecord;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\User;

trait CreatesTestData
{
    protected function makeAdmin(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Admin Test',
            'email' => 'admin-' . uniqid() . '@pedalya.test',
            'password' => 'password',
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'verified' => true,
            'totalRentals' => 0,
            'totalSpent' => 0,
        ], $overrides));
    }

    protected function makeRider(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Rider Test',
            'email' => 'rider-' . uniqid() . '@pedalya.test',
            'password' => 'password',
            'role' => User::ROLE_RIDER,
            'status' => User::STATUS_ACTIVE,
            'verified' => true,
            'idUploaded' => false,
            'totalRentals' => 0,
            'totalSpent' => 0,
        ], $overrides));
    }

/**
 * Test bicycles are provisioned as in service by default.
 *
 * iotEnabled is deliberately kept out of Bicycle::$fillable, so it is applied
 * through the model method. Defaulting it to true keeps these fixtures
 * representative of the deployed unit (PDY-001) - the production policy where
 * everything else is parked is exercised explicitly by FleetServicePolicyTest
 * via `['iotEnabled' => false]` overrides.
 */
protected function makeBicycle(array $overrides = []): Bicycle
{
    $serial = 'BIKE-' . strtoupper(substr(uniqid(), -6));

    $bicycle = Bicycle::create(array_merge([
        'name' => 'Test Bike',
        'model' => 'City',
        'serialNumber' => $serial,
        'qrCode' => 'QR-' . $serial,
        'status' => Bicycle::STATUS_AVAILABLE,
        'hourlyRate' => 15.00,
        'batteryLevel' => 90,
        'lockStatus' => Bicycle::LOCK_LOCKED,
        'currentLat' => 14.5995,
        'currentLng' => 120.9842,
        'condition' => 'good',
        'totalRentals' => 0,
    ], $overrides));

    if ($overrides['iotEnabled'] ?? true) {
        $bicycle->provisionForService();
    }

    return $bicycle->refresh();
}

/**
 * Create a bicycle from an explicit attribute set, provisioned as in service
 * unless the caller says otherwise.
 *
 * iotEnabled is deliberately kept out of Bicycle::$fillable so it can never be
 * mass-assigned, which means a plain Bicycle::create() yields an out-of-service
 * bicycle. Tests that build their own rows use this instead of reaching for the
 * constructor directly.
 */
protected function createBicycle(array $attributes = []): Bicycle
{
    $bicycle = Bicycle::create($attributes);

    if ($attributes['iotEnabled'] ?? true) {
        $bicycle->provisionForService();
    }

    return $bicycle->refresh();
}

/**
 * A bicycle parked out of service: physically present, no controllable
 * hardware. Its recorded lockStatus is intentional and must survive every
 * status transition untouched.
 */
protected function makeOutOfServiceBicycle(array $overrides = []): Bicycle
{
    return $this->makeBicycle(array_merge([
        'status' => Bicycle::STATUS_OUT_OF_SERVICE,
        'iotEnabled' => false,
    ], $overrides));
}

    protected function makeRental(array $overrides = []): Rental
    {
        $rider = ($overrides['riderId'] ?? null) ? null : $this->makeRider();
        $bike = ($overrides['bicycleId'] ?? null) ? null : $this->makeBicycle();

        return Rental::create(array_merge([
            'rentalId' => 'REN-' . strtoupper(date('Ymd')) . '-' . strtoupper(substr(uniqid(), -4)),
            'riderId' => $overrides['riderId'] ?? $rider->id,
            'bicycleId' => $overrides['bicycleId'] ?? $bike->id,
            'status' => Rental::STATUS_PENDING,
            'startTime' => now(),
            'expectedEndTime' => now()->addHours(1),
            'ratePerHour' => 15.00,
            'totalFee' => 15.00,
            'durationMinutes' => 60,
            'durationFormatted' => '1h 0m',
            'chargedHours' => 1,
            'paymentMethod' => 'cash',
            'paymentStatus' => 'pending',
        ], $overrides));
    }

    protected function makePayment(array $overrides = []): Payment
    {
        $user = ($overrides['userId'] ?? null) ? null : $this->makeRider();

        return Payment::create(array_merge([
            'userId' => $overrides['userId'] ?? $user->id,
            'paymentReference' => 'PMT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -8)),
            'paymentMethod' => 'gcash',
            'amount' => 100.00,
            'convenienceFee' => 0,
            'totalAmount' => 100.00,
            'currency' => 'PHP',
            'status' => 'pending',
        ], $overrides));
    }

    protected function makeMaintenanceRecord(array $overrides = []): MaintenanceRecord
    {
        return MaintenanceRecord::create(array_merge([
            'type' => 'routine',
            'description' => 'Routine maintenance',
            'severity' => 'low',
            'status' => MaintenanceRecord::STATUS_SCHEDULED,
        ], $overrides));
    }
}
