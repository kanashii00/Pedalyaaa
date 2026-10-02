<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Fleet service policy
    |---------------------------------------------------------------------------
    |
    | Which physical unit is actually deployed and controllable.
    |
    | The inventory holds real bicycles that are parked and not in service. They
    | have no ESP32, no servo and no smart lock, so their recorded lockStatus is
    | just the state they happen to be sitting in - there is nothing to command.
    | Exactly one bicycle is in service: PDY-001 - Beach Cruiser 001.
    |
    | Everything that decides rentability or queues a hardware command derives
    | from bicycles.iotEnabled, which this policy provisions. The serial number
    | is used rather than the primary key so the policy survives a reseed that
    | changes ids.
    |
    */

    'fleet' => [
        'in_service_serial' => env('PEDALYA_IN_SERVICE_SERIAL', 'PDY-001'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Single-bike display mode
    |---------------------------------------------------------------------------
    |
    | The fleet is being demoed on one physical bicycle, so the rider-facing
    | rent page offers only the featured bike and renders the rest of the
    | available fleet greyed out and unselectable.
    |
    | This is presentation only. It never hides rows from the admin screens and
    | never changes a bicycle's status: startRental() still validates whatever
    | id the rider submits, so an out-of-band POST for another bike is rejected
    | by the server exactly as before.
    |
    | Set enabled to false, or bicycle_id to null, to get the full fleet back
    | without touching a view.
    |
    */

    'single_bike_mode' => [
        'enabled' => env('PEDALYA_SINGLE_BIKE_MODE', true),

        // Primary key of the bicycle that stays selectable. PDY-001 is id 1.
        'bicycle_id' => env('PEDALYA_SINGLE_BIKE_ID', 1),
    ],

];
