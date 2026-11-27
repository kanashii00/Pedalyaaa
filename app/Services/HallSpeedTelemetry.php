<?php

namespace App\Services;

use App\Models\Bicycle;

class HallSpeedTelemetry
{
    public static function attributesFor(Bicycle $bicycle, array $data): array
    {
        $status = $data['speedSensorStatus'] ?? null;
        $reportedCircumference = isset($data['wheelCircumferenceM'])
            ? (float) $data['wheelCircumferenceM']
            : null;
        $speed = isset($data['speedKmh']) ? (float) $data['speedKmh'] : null;
        $configuredCircumference = $bicycle->wheelCircumferenceM !== null
            ? (float) $bicycle->wheelCircumferenceM
            : null;

        if ($status === null) {
            return [
                'speedKmh' => null,
                'wheelCircumferenceM' => null,
                'hallPulseCount' => null,
                'speedSensorStatus' => null,
            ];
        }

        if ($status !== 'unconfigured'
            && ($configuredCircumference === null
                || $reportedCircumference === null
                || abs($configuredCircumference - $reportedCircumference) > 0.005)) {
            $status = 'configuration_mismatch';
            $speed = null;
        } elseif ($status === 'stopped') {
            $speed = 0.0;
        } elseif ($status !== 'active') {
            $speed = null;
        }

        return [
            'speedKmh' => $speed,
            'wheelCircumferenceM' => $reportedCircumference,
            'hallPulseCount' => $data['hallPulseCount'] ?? null,
            'speedSensorStatus' => $status,
        ];
    }
}
