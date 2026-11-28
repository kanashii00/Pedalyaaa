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
        $pulseCount = isset($data['hallPulseCount']) ? (int) $data['hallPulseCount'] : null;
        $pulseAgeMs = isset($data['hallPulseAgeMs']) ? (int) $data['hallPulseAgeMs'] : null;
        $pulseIntervalMs = isset($data['pulseIntervalMs']) ? (int) $data['pulseIntervalMs'] : null;
        $configuredCircumference = $bicycle->wheelCircumferenceM !== null
            ? (float) $bicycle->wheelCircumferenceM
            : null;

        if ($status === null) {
            return [
                'speedKmh' => null,
                'wheelCircumferenceM' => null,
                'hallPulseCount' => null,
                'speedSensorStatus' => null,
                'lastHallPulseAt' => null,
                'pulseIntervalMs' => null,
                'estimatedDistanceKm' => null,
                'movementStatus' => null,
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

        $circumferenceMatches = $configuredCircumference !== null
            && $reportedCircumference !== null
            && abs($configuredCircumference - $reportedCircumference) <= 0.005;
        $estimatedDistanceKm = $circumferenceMatches && $pulseCount !== null
            ? $pulseCount * $configuredCircumference / 1000
            : null;

        return [
            'speedKmh' => $speed,
            'wheelCircumferenceM' => $reportedCircumference,
            'hallPulseCount' => $pulseCount,
            'speedSensorStatus' => $status,
            'lastHallPulseAt' => $pulseCount !== null && $pulseCount > 0 && $pulseAgeMs !== null
                ? now()->subMilliseconds($pulseAgeMs)
                : null,
            'pulseIntervalMs' => $pulseIntervalMs,
            'estimatedDistanceKm' => $estimatedDistanceKm,
            'movementStatus' => match ($status) {
                'active' => 'moving',
                'stopped' => 'stationary',
                default => 'unknown',
            },
        ];
    }
}
