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

        $bicycles = Bicycle::with(['latestTelemetry', 'latestGpsLog', 'currentRiderUser'])

            ->where('status', '!=', 'removed')
            ->orderBy('id')
            ->get();

        $geofence = $this->geofenceService->getConfig();

        foreach ($bicycles as $bike) {
            $gps = $this->gpsSnapshot($bike);
            $bike->setAttribute('gps_data', $gps);

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
        $summary = [
            'total'         => 0,
            'available'     => 0,
            'rented'        => 0,
            'maintenance'   => 0,
            'out_of_service'=> 0,
            'locked'        => 0,
            'unlocked'      => 0,
            'online'        => 0,
            'offline'       => 0,
        ];

        $cutoff = now()->subMinutes(5);
        foreach ($bicycles as $bike) {
            $online = $bike->lastHeartbeat !== null && $bike->lastHeartbeat->gt($cutoff);
            $summary['total']++;
            $summary[$bike->status] = ($summary[$bike->status] ?? 0) + 1;

            if ($bike->lockStatus === 'locked') {
                $summary['locked']++;
            } else {
                $summary['unlocked']++;
            }

            if ($online) {
                $summary['online']++;
            } else {
                $summary['offline']++;
            }

            $bike->connectivity = $online ? 'online' : 'offline';
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
        $bicycles = Bicycle::with('latestGpsLog')
            ->where('status', '!=', 'removed')
            ->orderBy('id')
            ->get();

        $geofence = $this->geofenceService->getConfig();

        $data = [];
        foreach ($bicycles as $bike) {
            $gps = $this->gpsSnapshot($bike);

            if ($gps['status'] === 'fix') {
                $zone = $this->geofenceService->checkPoint($gps['latitude'], $gps['longitude'], $geofence);
            } else {
                $zone = ['inside' => null, 'distance' => null, 'level' => 'unknown', 'warning' => false];
            }
            $data[] = [
                'id' => $bike->id,
                'name' => $bike->name,
                'status' => $bike->status,
                'current_lat' => $gps['latitude'],
                'current_lng' => $gps['longitude'],
                'gps_status' => $gps['status'],
                'last_gps_update' => $gps['updated_at'],
                'gps_accuracy' => $gps['accuracy'],
                'gps_hdop' => $gps['hdop'],
                'gps_satellites' => $gps['satellites'],
                'gps_speed' => $gps['speed'],
                'battery_level' => $bike->batteryLevel,
                'lock_status' => $bike->lockStatus,
                'last_heartbeat' => $bike->lastHeartbeat,
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
}
