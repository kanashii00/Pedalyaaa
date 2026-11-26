<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bicycle;
use App\Services\GeofenceService;
use App\Services\GpsIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MonitoringController extends Controller
{
    /**
     * The Monitoring sub-views.
     *
     * `gps` is the landing view: it carries the live map and position feed, so
     * a bare /monitoring URL still resolves to something useful.
     */
    public const DEFAULT_SECTION = 'gps';

    public const SECTIONS = ['gps', 'locks', 'devices'];

    public function __construct(
        private GeofenceService $geofenceService
    ) {}

    public function index(Request $request): Response
    {
        // An unknown (or retired) section falls back to the default rather than
        // to a blank page, so old bookmarked links keep working.
        $section = $request->query('section', self::DEFAULT_SECTION);
        if (!in_array($section, self::SECTIONS, true)) {
            $section = self::DEFAULT_SECTION;
        }

        // Every non-removed row is listed (no records are ever hidden or
        // deleted here); the devices section itself decides per row whether a
        // genuinely registered device backs it. The latest lock command rides
        // along so the "last command result" tile reads real ack state.
        $bicycles = Bicycle::with(['latestTelemetry', 'latestHeartbeatTelemetry', 'latestGpsLog', 'currentRiderUser', 'currentRental', 'latestCommand'])

            ->where('status', '!=', 'removed')
            ->orderBy('id')
            ->get();

        $geofence = $this->geofenceService->getConfig();

        foreach ($bicycles as $bike) {
            $gps = $this->gpsSnapshot($bike);
            $bike->setAttribute('gps_data', $gps);
            $bike->setAttribute('speed_data', $this->speedSnapshot($bike, $gps));

            if ($gps['status'] === 'fix') {
                $bike->zone = $this->geofenceService->checkPoint($gps['latitude'], $gps['longitude'], $geofence);
            } else {
                $bike->zone = ['inside' => null, 'distance' => null, 'level' => 'unknown', 'warning' => false];
            }
        }

        return response()->view('admin.monitoring', compact('bicycles', 'geofence', 'section'));
    }

    public function bicycleStatusIndex(Request $request): Response
    {
        $query = Bicycle::with(['latestTelemetry', 'latestGpsLog', 'currentRiderUser'])
            ->where('status', '!=', 'removed');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('lock')) {
            $query->where('lockStatus', $request->input('lock') === 'locked' ? 'locked' : 'unlocked');
        }

        if ($request->filled('connectivity')) {
            $this->applyConnectivityFilter($query, $request->input('connectivity'));
        }

        if ($request->filled('search')) {
            $this->applySearchFilter($query, $request->input('search'));
        }

        $bicycles = $query->orderBy('id')->get();

        $summary = $this->buildStatusSummary($bicycles);

        return response()->view('admin.bicycles-status', compact('bicycles', 'summary'));
    }

    private function applyConnectivityFilter($query, string $connectivity): void
    {
        $cutoff = now()->subMinutes(5);

        if ($connectivity === 'online') {
            $query->where('lastHeartbeat', '>=', $cutoff);

            return;
        }

        $query->where(function ($q) use ($cutoff) {
            $q->whereNull('lastHeartbeat')->orWhere('lastHeartbeat', '<', $cutoff);
        });
    }

    private function applySearchFilter($query, string $search): void
    {
        $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('serialNumber', 'like', "%{$search}%");
        });
    }

    private function buildStatusSummary($bicycles): array
    {
        // Essential seven only. IoT Online/Offline count genuinely registered
        // devices (fitted hardware + stored credential, i.e. PDY-001) by fresh
        // vs stale heartbeat; bicycles without hardware are excluded from both
        // so they are never incorrectly marked offline.
        $summary = [
            'total'         => 0,
            'available'     => 0,
            'rented'        => 0,
            'maintenance'   => 0,
            'out_of_service'=> 0,
            'online'        => 0,
            'offline'       => 0,
        ];

        $cutoff = now()->subMinutes(5);
        foreach ($bicycles as $bike) {
            $equipped = $bike->hasRegisteredDevice();
            $online = $equipped && $bike->lastHeartbeat !== null && $bike->lastHeartbeat->gt($cutoff);
            $summary['total']++;
            $summary[$bike->status] = ($summary[$bike->status] ?? 0) + 1;

            if ($online) {
                $summary['online']++;
            } elseif ($equipped) {
                $summary['offline']++;
            }

            $bike->connectivity = ! $equipped ? 'none' : ($online ? 'online' : 'offline');
        }

        return $summary;
    }

    public function bicycleStatus(int $id): JsonResponse
    {
        $bicycle = Bicycle::with(['latestTelemetry', 'latestGpsLog'])->findOrFail($id);

        return response()->json([
            'bicycle'       => $bicycle,
            'device_status' => $bicycle->latestTelemetry,
            'gps'           => $bicycle->latestGpsLog,
        ]);
    }

    public function live(): JsonResponse
    {
        $bicycles = Bicycle::with(['latestGpsLog', 'latestTelemetry', 'latestHeartbeatTelemetry', 'currentRiderUser', 'currentRental'])
            ->where('status', '!=', 'removed')
            ->orderBy('id')
            ->get();

        // Latest lock command per bicycle in one query: backs the devices
        // "last command result" tile with real acknowledgement state.
        $lastCommands = \App\Models\DeviceCommand::whereIn('bicycleId', $bicycles->pluck('id'))
            ->orderBy('id', 'desc')
            ->get()
            ->groupBy('bicycleId')
            ->map(fn ($rows) => $rows->first());

        $geofence = $this->geofenceService->getConfig();

        $data = [];
        foreach ($bicycles as $bike) {
            $gps = $this->gpsSnapshot($bike);
            $speed = $this->speedSnapshot($bike, $gps);

            if ($gps['status'] === 'fix') {
                $zone = $this->geofenceService->checkPoint($gps['latitude'], $gps['longitude'], $geofence);
            } else {
                $zone = ['inside' => null, 'distance' => null, 'level' => 'unknown', 'warning' => false];
            }
            $lastCommand = $lastCommands->get($bike->id);
            $data[] = [
                'id' => $bike->id,
                'name' => $bike->name,
                'serialNumber' => $bike->serialNumber,
                'rider' => $bike->currentRiderUser?->name,
                'rental' => $bike->currentRental ? ('#' . ($bike->currentRental->rentalId ?? $bike->currentRental->id)) : null,
                'status' => $bike->status,
                'current_lat' => $gps['latitude'],
                'current_lng' => $gps['longitude'],
                'gps_status' => $gps['status'],
                'last_gps_update' => $gps['updated_at'],
                'gps_accuracy' => $gps['accuracy'],
                'gps_hdop' => $gps['hdop'],
                'gps_satellites' => $gps['satellites'],
                'gps_speed' => $gps['speed'],
                'speed_kmh' => $speed['speed'],
                'speed_source' => $speed['source'],
                'speed_sensor_status' => $speed['sensor_status'],
                'wheel_circumference_m' => $speed['wheel_circumference_m'],
                'hall_pulse_count' => $speed['hall_pulse_count'],
                'battery_level' => $bike->batteryLevel,
                'lock_status' => $bike->lockStatus,
                'last_lock_action' => $bike->lastLockAction?->toIso8601String(),
                'last_heartbeat' => $bike->lastHeartbeat?->toIso8601String(),
                'has_registered_device' => $bike->hasRegisteredDevice(),
                'device_version' => $bike->latestTelemetry?->deviceVersion,
                'device_uptime' => $bike->latestTelemetry?->uptime,
                'last_telemetry' => $bike->latestTelemetry?->eventTimestamp?->toIso8601String(),
                'last_command' => $lastCommand ? [
                    'command' => $lastCommand->command,
                    'status' => $lastCommand->status,
                    'response' => $lastCommand->response,
                    'at' => $lastCommand->updated_at?->toIso8601String(),
                ] : null,
                'zone_level' => $zone['level'],
                'zone_distance' => $zone['distance'],
            ];
        }

        return response()->json(['bicycles' => $data, 'geofence' => $geofence]);
    }

    /**
     * Use only a position explicitly recorded in the GPS log. currentLat and
     * currentLng can also be populated by non-GPS device telemetry or seed data.
     */
    private function gpsSnapshot(Bicycle $bicycle): array
    {
        $log = $bicycle->latestGpsLog;
        $latitude = $log?->lat;
        $longitude = $log?->lng;
        $hasPosition = $latitude !== null
            && $longitude !== null
            && is_numeric($latitude)
            && is_numeric($longitude)
            && (float) $latitude >= -90
            && (float) $latitude <= 90
            && (float) $longitude >= -180
            && (float) $longitude <= 180;
        $updatedAt = $log?->created_at;
        $isFresh = $hasPosition
            && $updatedAt !== null
            && $updatedAt->greaterThanOrEqualTo(now()->subSeconds(GpsIngestService::FIX_FRESH_SECONDS));

        return [
            'latitude' => $hasPosition ? (float) $latitude : null,
            'longitude' => $hasPosition ? (float) $longitude : null,
            'status' => ! $hasPosition ? 'unavailable' : ($isFresh ? 'fix' : 'last_known'),
            'updated_at' => $updatedAt?->toIso8601String(),
            'accuracy' => $log?->accuracy !== null ? (float) $log->accuracy : null,
            'hdop' => $log?->hdop !== null ? (float) $log->hdop : null,
            'satellites' => $log?->satellites,
            'speed' => $log?->speed !== null ? (float) $log->speed : null,
        ];
    }

    private function speedSnapshot(Bicycle $bicycle, array $gps): array
    {
        $circumference = $bicycle->wheelCircumferenceM !== null
            ? (float) $bicycle->wheelCircumferenceM
            : null;
        $telemetry = $bicycle->latestHeartbeatTelemetry;
        $telemetryIsFresh = $telemetry?->eventTimestamp !== null
            && $telemetry->eventTimestamp->greaterThanOrEqualTo(now()->subSeconds(45));

        if ($circumference !== null) {
            $status = $telemetryIsFresh
                ? ($telemetry->speedSensorStatus ?? 'waiting')
                : 'stale';
            $speed = match ($status) {
                'stopped' => 0.0,
                'active' => $telemetry->speedKmh !== null ? (float) $telemetry->speedKmh : null,
                default => null,
            };

            return [
                'speed' => $speed,
                'source' => 'hall',
                'sensor_status' => $status,
                'wheel_circumference_m' => $circumference,
                'hall_pulse_count' => $telemetryIsFresh ? $telemetry->hallPulseCount : null,
            ];
        }

        if ($telemetryIsFresh
            && ! in_array($telemetry->speedSensorStatus, [null, 'unconfigured'], true)) {
            return [
                'speed' => null,
                'source' => 'hall',
                'sensor_status' => $telemetry->speedSensorStatus,
                'wheel_circumference_m' => null,
                'hall_pulse_count' => $telemetry->hallPulseCount,
            ];
        }

        $hasFreshGpsSpeed = $gps['status'] === 'fix' && $gps['speed'] !== null;

        return [
            'speed' => $hasFreshGpsSpeed ? $gps['speed'] : null,
            'source' => $hasFreshGpsSpeed ? 'gps_fallback' : 'unavailable',
            'sensor_status' => $telemetryIsFresh ? $telemetry->speedSensorStatus : null,
            'wheel_circumference_m' => null,
            'hall_pulse_count' => $telemetryIsFresh ? $telemetry->hallPulseCount : null,
        ];
    }
}
