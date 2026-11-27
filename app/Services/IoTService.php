<?php

namespace App\Services;

use App\Models\Accident;
use App\Models\Bicycle;
use App\Models\DeviceCommand;
use App\Models\DeviceStatus;
use App\Models\Rental;
use App\Models\User;
use Carbon\Carbon;

class IoTService
{
    protected NotificationService $notificationService;

    protected GeofenceService $geofenceService;

    protected DeviceCommandService $deviceCommands;

    public function __construct(NotificationService $notificationService, GeofenceService $geofenceService, DeviceCommandService $deviceCommands)
    {
        $this->notificationService = $notificationService;
        $this->geofenceService = $geofenceService;
        $this->deviceCommands = $deviceCommands;
    }

    /**
     * Queue a command for a bicycle's device, or null when the bicycle is not
     * in service. See DeviceCommandService::sendCommand() for why that gate
     * lives there.
     */
    public function sendCommand(int $bicycleId, string $command, array $params, ?User $user = null): ?DeviceCommand
    {
        return $this->deviceCommands->sendCommand($bicycleId, $command, $params, $user);
    }

    public function acknowledgeDeviceCommand(
        int $deviceCommandId,
        ?string $result = null,
        string $status = 'executed'
    ): bool {
        return $this->deviceCommands->acknowledgeDeviceCommand($deviceCommandId, $result, $status);
    }

    public function getPendingCommands(int $bicycleId): array
    {
        return $this->deviceCommands->getPendingCommands($bicycleId);
    }

    public function processHeartbeat(array $data): array
    {
        $timestamp = Carbon::now();

        $bicycleId = $this->bicycleIdFrom($data);
        $bicycle = $bicycleId ? Bicycle::find($bicycleId) : null;

        if ($bicycle) {
            $this->handleHeartbeatForBicycle($bicycle, $data, $timestamp);
        }

        return [
            'received' => true,
            'timestamp' => $timestamp->toIso8601String(),
            'commands' => $this->getPendingCommands((int) $bicycleId),
        ];
    }

    private function bicycleIdFrom(array $data): mixed
    {
        return $data['bicycleId'] ?? $data['bicycle_id'] ?? $data['bicycle'] ?? null;
    }

    private function handleHeartbeatForBicycle(Bicycle $bicycle, array $data, Carbon $timestamp): void
    {
        $previousLockStatus = $bicycle->lockStatus;

        $bicycle->update($this->buildHeartbeatUpdateData($bicycle, $data, $timestamp));

        DeviceStatus::create([
            'bicycleId' => $bicycle->id,
            'gps' => $this->gpsPayload($data),
            ...$this->speedSensorPayload($bicycle, $data),
            'battery' => $this->batteryPayload($data),
            'lockStatus' => $bicycle->lockStatus,
            'deviceVersion' => $data['firmware'] ?? null,
            'uptime' => $data['uptime'] ?? null,
            'type' => 'heartbeat',
            'eventTimestamp' => $timestamp,
        ]);

        $this->detectParkedReturn($bicycle, $previousLockStatus, $timestamp);

        $impact = HelperService::valueOf($data, ['impact', 'impact_force', 'impactForce']);
        if ($impact !== null && (float) $impact > 0) {
            $this->handleImpactDetection($bicycle, (float) $impact, $data);
        }

        if (isset($data['lat'], $data['lng'])) {
            $this->handleGeofenceCheck($bicycle, (float) $data['lat'], (float) $data['lng']);
        }
    }

    /**
     * Open the automatic return-grace window as soon as the bicycle reports
     * itself parked.
     *
     * While a rental is running the firmware holds the servo released, so a
     * heartbeat that reports locked=true means the rider has physically
     * re-engaged the lock - the signal that they are back. The unlock -> lock
     * transition is what is required for a running rental, because a bicycle
     * still carrying its parked lock state when the rental starts would look
     * identical and would end a ride that has not begun yet.
     *
     * Rentals already in the return flow accept the level alone: markRideEnded()
     * records the lock on the server before the device ever confirms it, so no
     * transition is observable from here.
     *
     * RentalService is resolved lazily rather than injected: RentalService
     * itself depends on IoTService, and a constructor cycle would never finish
     * building either of them.
     */
    private function detectParkedReturn(Bicycle $bicycle, ?string $previousLockStatus, Carbon $timestamp): void
    {
        if ($bicycle->lockStatus !== Bicycle::LOCK_LOCKED || ! $bicycle->isInService()) {
            return;
        }

        $rental = Rental::query()
            ->where('bicycleId', $bicycle->id)
            ->whereIn('status', [
                Rental::STATUS_ACTIVE,
                Rental::STATUS_OVERDUE,
                Rental::STATUS_EXPIRED,
                Rental::STATUS_AWAITING_RETURN,
            ])
            ->whereNull('returnDeadline')
            ->latest('id')
            ->first();

        if ($rental === null) {
            return;
        }

        $alreadyReturning = in_array($rental->status, [
            Rental::STATUS_EXPIRED,
            Rental::STATUS_AWAITING_RETURN,
        ], true);

        if (! $alreadyReturning && $previousLockStatus !== Bicycle::LOCK_UNLOCKED) {
            return;
        }

        app(RentalService::class)->startAutomaticReturnGrace($rental, $timestamp);
    }

    private function buildHeartbeatUpdateData(Bicycle $bicycle, array $data, Carbon $timestamp): array
    {
        $updateData = ['lastHeartbeat' => $timestamp];

        if (isset($data['lat'], $data['lng'])) {
            $updateData['currentLat'] = $data['lat'];
            $updateData['currentLng'] = $data['lng'];
            $updateData['lastGpsUpdate'] = $timestamp;
        }

        $battery = HelperService::valueOf($data, ['batteryLevel', 'battery_level', 'battery']);
        if ($battery !== null) {
            $updateData['batteryLevel'] = (int) max(0, min(100, (float) $battery));
        }

        // Only a unit with hardware owns its lock state. A parked bicycle has no
        // servo to move, so a heartbeat claiming "unlocked" (or locked) for it is
        // noise from a device that should not be reporting at all, and must not
        // overwrite the recorded physical position. Location and battery are still
        // accepted - those are harmless even if stale.
        if (array_key_exists('locked', $data) && $bicycle->isInService()) {
            $updateData['lockStatus'] = $data['locked']
                ? Bicycle::LOCK_LOCKED
                : Bicycle::LOCK_UNLOCKED;
        }

        return $updateData;
    }

    private function gpsPayload(array $data): ?array
    {
        if (! isset($data['lat'], $data['lng'])) {
            return null;
        }

        return [
            'lat' => $data['lat'],
            'lng' => $data['lng'],
            'speed' => $data['speed'] ?? $data['velocity'] ?? 0,
        ];
    }

    private function speedSensorPayload(Bicycle $bicycle, array $data): array
    {
        return HallSpeedTelemetry::attributesFor($bicycle, $data);
    }

    private function batteryPayload(array $data): ?array
    {
        $battery = HelperService::valueOf($data, ['batteryLevel', 'battery_level', 'battery']);

        return $battery !== null ? ['level' => (float) $battery] : null;
    }

    public function processAccidentReport(array $data): array
    {
        $bicycleId = $data['bicycleId'] ?? $data['bicycle_id'] ?? null;
        $bicycle = $bicycleId ? Bicycle::find($bicycleId) : null;
        $impact = (float) ($data['impact'] ?? $data['impact_force'] ?? $data['impactForce'] ?? 0);
        $severity = $this->determineSeverity($impact);

        $accident = Accident::create([
            'bicycleId' => $bicycleId,
            'type' => $data['type'] ?? 'accident',
            'severity' => $severity,
            'gpsLocation' => isset($data['lat'], $data['lng']) ? ['lat' => $data['lat'], 'lng' => $data['lng']] : null,
            'accelerometerData' => $data['accelerometer'] ?? $data['accelerometerData'] ?? null,
            'impactForce' => $impact,
            'description' => $data['description'] ?? 'Automatic accident detection via IoT device',
            'status' => 'open',
            'acknowledged' => false,
            'alertSent' => true,
            'reportedBy' => $data['device_id'] ?? 'iot_device',
        ]);

        if ($bicycle) {
            $bicycle->update(['status' => Bicycle::STATUS_MAINTENANCE]);
        }

        $this->notifyAccident($accident, $severity);

        return [
            'accidentId' => $accident->id,
            'severity' => $severity,
            'status' => 'reported',
        ];
    }

    public function processGeofenceAlert(array $data): array
    {
        $bicycleId = $data['bicycleId'] ?? $data['bicycle_id'] ?? null;
        $riderId = $data['riderId'] ?? $data['rider_id'] ?? null;
        $distance = isset($data['distance']) ? (float) $data['distance'] : null;

        // Reuse the currently open theft alert for this bicycle to prevent
        // duplicate active alerts while it remains outside the geofence.
        $accident = Accident::where('bicycleId', $bicycleId)
            ->where('type', TheftDetectionService::TYPE_THEFT)
            ->where('status', 'open')
            ->latest('id')
            ->first();

        if (! $accident) {
            $accident = Accident::create([
                'bicycleId' => $bicycleId,
                'type' => TheftDetectionService::TYPE_THEFT,
                'severity' => 'moderate',
                'gpsLocation' => isset($data['lat'], $data['lng']) ? ['lat' => $data['lat'], 'lng' => $data['lng']] : null,
                'description' => $data['description'] ?? 'Geofence boundary breached',
                'status' => 'open',
                'acknowledged' => false,
                'alertSent' => true,
                'reportedBy' => $data['device_id'] ?? 'iot_device',
                'breachDistance' => $distance,
            ]);
        } else {
            $accident->update([
                'gpsLocation' => isset($data['lat'], $data['lng']) ? ['lat' => $data['lat'], 'lng' => $data['lng']] : $accident->gpsLocation,
                'breachDistance' => $distance,
                'description' => $data['description'] ?? 'Geofence boundary breached',
                'status' => 'open',
                'updated_at' => now(),
            ]);
        }

        if ($riderId) {
            $this->notificationService->create(
                $riderId,
                'Geofence Alert',
                'You have breached the geofence boundary. Please return to the designated area immediately.',
                'geofence_alert'
            );
        }

        $this->notifyAdmins(
            'Geofence Breach Alert',
            "Bicycle {$bicycleId} has breached its geofence boundary."
                .($distance !== null ? ' Distance outside boundary: '.round($distance, 1).'m.' : '')
        );

        return [
            'alertId' => $accident->id,
            'status' => 'logged',
        ];
    }

    public function getDeviceStatus(int $bicycleId): ?DeviceStatus
    {
        return DeviceStatus::where('bicycleId', $bicycleId)
            ->latest('eventTimestamp')
            ->first();
    }

    private function handleImpactDetection(Bicycle $bicycle, float $impact, array $data): void
    {
        if ($impact < 2.0) {
            return;
        }

        $severity = $this->determineSeverity($impact);

        Accident::create([
            'bicycleId' => $bicycle->id,
            'type' => 'impact_detected',
            'severity' => $severity,
            'gpsLocation' => isset($data['lat'], $data['lng']) ? ['lat' => $data['lat'], 'lng' => $data['lng']] : null,
            'accelerometerData' => $data['accelerometer'] ?? null,
            'impactForce' => $impact,
            'description' => 'Impact detected with force: '.$impact.'g',
            'status' => 'open',
            'acknowledged' => false,
            'reportedBy' => $data['device_id'] ?? 'iot_device',
        ]);

        $this->notifyAdmins(
            'Impact / Accident Detected',
            "Abnormal movement or impact detected on bicycle {$bicycle->id} (force: {$impact}g, severity: {$severity})."
        );
    }

    private function handleGeofenceCheck(Bicycle $bicycle, float $lat, float $lng): void
    {
        $geofenceService = app(GeofenceService::class);
        $result = $geofenceService->checkPointInGeofence($lat, $lng);

        $theftService = app(TheftDetectionService::class);

        if (! $result['inside']) {
            // Open (or update) the single active theft alert for this bicycle.
            $theftService->openOrUpdateTheftAlert(
                $bicycle,
                $lat,
                $lng,
                $result['distanceOutside'] ?? null,
                $result
            );
        } elseif (in_array($result['level'] ?? null, ['approaching', 'warning'], true)) {
            $this->recordWarningEvent($bicycle, $lat, $lng, $result);
        } else {
            // Bicycle returned inside the safe zone → resolve alert, keep history.
            $theftService->resolveAlertOnReturn($bicycle);
        }
    }

    private function recordWarningEvent(Bicycle $bicycle, float $lat, float $lng, array $result): void
    {
        $lastWarning = Accident::where('bicycleId', $bicycle->id)
            ->where('type', 'geofence_alert')
            ->where('warningLevel', '!=', 'breach')
            ->latest('id')
            ->first();

        if ($lastWarning && $lastWarning->created_at->diffInMinutes(now()) < 15) {
            return;
        }

        Accident::create([
            'bicycleId' => $bicycle->id,
            'type' => 'geofence_alert',
            'severity' => 'minor',
            'gpsLocation' => ['lat' => $lat, 'lng' => $lng],
            'description' => 'Bicycle is approaching the geofence boundary ('.round($result['distanceToBoundary'] ?? 0, 1).'m from boundary).',
            'status' => 'open',
            'acknowledged' => false,
            'alertSent' => true,
            'reportedBy' => 'iot_device',
            'warningLevel' => $result['level'] ?? 'approaching',
            'distanceFromBoundary' => $result['distanceToBoundary'] ?? 0,
        ]);

        $this->notifyAdmins(
            'Geofence Warning',
            "Bicycle {$bicycle->id} is approaching the geofence boundary (".round($result['distanceToBoundary'] ?? 0, 1).'m from boundary).'
        );
    }

    private function determineSeverity(float $impact): string
    {
        return match (true) {
            $impact >= 8.0 => 'critical',
            $impact >= 5.0 => 'major',
            $impact >= 2.0 => 'moderate',
            default => 'minor',
        };
    }

    private function notifyAccident(Accident $accident, string $severity): void
    {
        $this->notifyAdmins(
            'Accident Report',
            "Accident detected on bicycle {$accident->bicycleId}. Severity: {$severity}. Immediate attention required."
        );
    }

    private function notifyAdmins(string $title, string $message): void
    {
        $admins = User::where('role', User::ROLE_ADMIN)->pluck('id')->all();

        if (! empty($admins)) {
            $this->notificationService->createForUsers($admins, $title, $message, 'system');
        }
    }
}
