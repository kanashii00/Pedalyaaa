<?php

namespace Database\Seeders;

use App\Models\Bicycle;
use Illuminate\Database\Seeder;

class BicycleSeeder extends Seeder
{
    public function run(): void
    {
        // Only PDY-001 is deployed: it is the single in-service, IoT-enabled unit
        // that riders may rent. Everything else in the inventory is a physical
        // bicycle parked out of service - no hardware, so nothing to command and
        // nothing to rent.
        //
        // Out-of-service rows are seeded as status=out_of_service / lockStatus=locked:
        // out_of_service says it is parked and not rentable, while lockStatus keeps
        // saying where the lock physically is. Lock position is not an operational
        // state, so it never appears in status.
        $bicycles = [
            ['name' => 'Beach Cruiser 001', 'serialNumber' => 'PDY-001', 'model' => 'Beach Cruiser', 'status' => Bicycle::STATUS_AVAILABLE, 'lockStatus' => Bicycle::LOCK_LOCKED, 'inService' => true, 'batteryLevel' => 100, 'hourlyRate' => 15.00, 'currentLat' => 14.5995, 'currentLng' => 120.9842],
            ['name' => 'Urban Cruiser 002', 'serialNumber' => 'PDY-002', 'model' => 'Urban Cruiser', 'status' => Bicycle::STATUS_OUT_OF_SERVICE, 'lockStatus' => Bicycle::LOCK_LOCKED, 'inService' => false, 'batteryLevel' => 85, 'hourlyRate' => 15.00, 'currentLat' => 14.6005, 'currentLng' => 120.9852],
            ['name' => 'Mountain Explorer 001', 'serialNumber' => 'PDY-003', 'model' => 'Mountain Explorer', 'status' => Bicycle::STATUS_OUT_OF_SERVICE, 'lockStatus' => Bicycle::LOCK_LOCKED, 'inService' => false, 'batteryLevel' => 72, 'hourlyRate' => 20.00, 'currentLat' => 14.5985, 'currentLng' => 120.9832],
            ['name' => 'City Rider 001', 'serialNumber' => 'PDY-004', 'model' => 'City Rider', 'status' => Bicycle::STATUS_OUT_OF_SERVICE, 'lockStatus' => Bicycle::LOCK_LOCKED, 'inService' => false, 'batteryLevel' => 30, 'hourlyRate' => 15.00, 'currentLat' => 14.5975, 'currentLng' => 120.9822],
            ['name' => 'City Rider 002', 'serialNumber' => 'PDY-005', 'model' => 'City Rider', 'status' => Bicycle::STATUS_OUT_OF_SERVICE, 'lockStatus' => Bicycle::LOCK_LOCKED, 'inService' => false, 'batteryLevel' => 95, 'hourlyRate' => 15.00, 'currentLat' => 14.6015, 'currentLng' => 120.9862],
            ['name' => 'Urban Cruiser 003', 'serialNumber' => 'PDY-006', 'model' => 'Urban Cruiser', 'status' => Bicycle::STATUS_OUT_OF_SERVICE, 'lockStatus' => Bicycle::LOCK_LOCKED, 'inService' => false, 'batteryLevel' => 60, 'hourlyRate' => 15.00, 'currentLat' => 14.6025, 'currentLng' => 120.9872],
            ['name' => 'Mountain Explorer 002', 'serialNumber' => 'PDY-007', 'model' => 'Mountain Explorer', 'status' => Bicycle::STATUS_OUT_OF_SERVICE, 'lockStatus' => Bicycle::LOCK_LOCKED, 'inService' => false, 'batteryLevel' => 88, 'hourlyRate' => 20.00, 'currentLat' => 14.5965, 'currentLng' => 120.9812],
            ['name' => 'City Rider 003', 'serialNumber' => 'PDY-008', 'model' => 'City Rider', 'status' => Bicycle::STATUS_OUT_OF_SERVICE, 'lockStatus' => Bicycle::LOCK_LOCKED, 'inService' => false, 'batteryLevel' => 45, 'hourlyRate' => 15.00, 'currentLat' => 14.5955, 'currentLng' => 120.9802],
        ];

        $inServiceSerial = config('pedalya.fleet.in_service_serial', 'PDY-001');

        foreach ($bicycles as $bike) {
            $attributes = [
                'name' => $bike['name'],
                'model' => $bike['model'],
                'status' => $bike['status'],
                'lockStatus' => $bike['lockStatus'],
                'batteryLevel' => $bike['batteryLevel'],
                'hourlyRate' => $bike['hourlyRate'],
                'currentLat' => $bike['currentLat'],
                'currentLng' => $bike['currentLng'],
                'condition' => 'good',
            ];

            $bicycle = Bicycle::updateOrCreate(
                ['serialNumber' => $bike['serialNumber']],
                $attributes
            );

            // iotEnabled is not mass-assignable, so provisioning goes through the
            // model method. The configured policy wins over the row above, so
            // pointing PEDALYA_IN_SERVICE_SERIAL at another unit re-provisions
            // without editing this file.
            $shouldBeInService = $bike['serialNumber'] === $inServiceSerial;

            if ($shouldBeInService && ! $bicycle->isInService()) {
                $bicycle->provisionForService();
            } elseif (! $shouldBeInService && $bicycle->isInService()) {
                $bicycle->sendOutOfService();
            }
        }
    }
}