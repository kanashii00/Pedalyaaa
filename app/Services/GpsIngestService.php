<?php

namespace App\Services;

use App\Events\GeofenceAlert;
use App\Events\GpsUpdate;
use App\Models\Accident;
use App\Models\Bicycle;
use App\Models\DeviceStatus;
use App\Models\GpsLog;

/**
 * Single place where a live GPS fix becomes a stored position.
 *
 * Both GPS entry points funnel through here: the fleet-wide
 * POST /api/gps/location and the per-bicycle POST /api/iot/bicycle/{bicycle}/gps
 * that the ESP32 actually calls. They previously would have needed their own
 * copy of the geofence warning band, the theft alert lifecycle and the GpsUpdate
 * broadcast, and any divergence between the copies would show up as a
 * geofence that behaves differently depending on which URL the device used.
 */
class GpsIngestService
{
    /**
     * How old a stored fix may be before the NEO-6M is treated as having lost
     * its fix rather than as parked at that spot.
     *
     * The firmware reports every GPS_REPORT_INTERVAL_MS (15s) and the receiver
     * discards a fix older than GPS_FIX_STALE_MS (30s), so a bicycle that has
     * not reported for a minute has genuinely lost satellite lock. Without this
     * a bicycle that drove away hours ago would still be drawn at its last
     * known place.
     */
    public const FIX_FRESH_SECONDS = 60;

    public function __construct(
        private GeofenceService $geofenceService,
        private TheftDetectionService $theftDetectionService
    ) {}

    /**
     * Persist one reported fix and run the consequences of that position.
     *
     * `lat`/`lng` are already validated by the caller. Only a real fix reaches
     * this method: the firmware withholds the report entirely when the NEO-6M
     * has no lock, so there is no "no fix" branch here to record.
     *
     * @param  array{lat: float, lng: float, speed?: float|null, heading?: float|null,
     *               altitude?: float|null, accuracy?: float|null, hdop?: float|null,
     *               satellites?: int|null, battery?: int|null, timestamp?: string|null}  $data
     * @return array{gps_log: GpsLog, geofence: array|null}
     */
    public function record(Bicycle $bicycle, array $data): array
    {
        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];

        $gpsLog = GpsLog::create([
            'bicycleId' => $bicycle->id,
            'lat' => $lat,
            'lng' => $lng,
            'speed' => $data['speed'] ?? null,
            'heading' => $data['heading'] ?? null,
            'altitude' => $data['altitude'] ?? null,
            'accuracy' => $data['accuracy'] ?? null,
            'hdop' => $data['hdop'] ?? null,
            'satellites' => $data['satellites'] ?? null,
            'batteryLevel' => $data['battery'] ?? null,
            'timestamp' => $data['timestamp'] ?? now(),
            // Recorded as coming from the on-board module so an operator can
            // tell a real NEO-6M fix apart from any other writer of this table.
            'source' => 'gps',
        ]);

        if (array_key_exists('speedSensorStatus', $data)) {
            $this->recordHallTelemetry($bicycle, $data);
        }

        // lastGpsUpdate is written in the same statement as the coordinates on
        // purpose: it is what makes currentLat/currentLng trustworthy as a GPS
        // position rather than a seeded placeholder. Nothing else sets it.
        $bicycle->update([
            'currentLat' => $lat,
            'currentLng' => $lng,
            'batteryLevel' => $data['battery'] ?? $bicycle->batteryLevel,
            'lastGpsUpdate' => now(),
        ]);

        $geofence = $this->applyGeofenceConsequences($bicycle, $lat, $lng);

        event(new GpsUpdate(
            $bicycle,
            $lat,
            $lng,
            [
                'speed' => $data['speed'] ?? null,
                'battery' => $data['battery'] ?? $bicycle->batteryLevel,
                'lock_status' => $bicycle->lockStatus,
                'zone' => $geofence['level'] ?? null,
                'zone_distance' => $geofence['distance'] ?? null,
                'accuracy' => $data['accuracy'] ?? null,
                'hdop' => $data['hdop'] ?? null,
                'satellites' => $data['satellites'] ?? null,
            ]
        ));

        return [
            'gps_log' => $gpsLog,
            'geofence' => $geofence,
        ];
    }

    public function recordHallTelemetry(Bicycle $bicycle, array $data): DeviceStatus
    {
        return DeviceStatus::create([
            'bicycleId' => $bicycle->id,
            ...HallSpeedTelemetry::attributesFor($bicycle, $data),
            'type' => 'gps',
            'eventTimestamp' => now(),
        ]);
    }

    /**
     * Evaluate a fresh fix against the saved zone and keep the theft alert,
     * the warning log and the admin broadcast in step with it.
     */
    private function applyGeofenceConsequences(Bicycle $bicycle, float $lat, float $lng): ?array
    {
        $config = $this->geofenceService->getConfig();
        $result = $this->geofenceService->checkPoint($lat, $lng, $config);

        if ($result['inside']) {
            if (in_array($result['level'] ?? null, ['approaching', 'warning'], true)) {
                $this->recordWarningEvent($bicycle, $lat, $lng, $result);

                event(new GeofenceAlert(
                    $bicycle,
                    $result['level'],
                    (float) $result['distanceToBoundary'],
                    $lat,
                    $lng
                ));

                return $result;
            }

            // Returned inside the safe zone: resolve an open theft alert but
            // keep the record for history.
            $this->theftDetectionService->resolveAlertOnReturn($bicycle);

            return $result;
        }

        // Outside the zone: open or update the single active theft alert. This
        // runs regardless of alertEnabled, because a bicycle past the boundary
        // is a breach whether or not boundary warnings are switched on.
        $this->theftDetectionService->openOrUpdateTheftAlert(
            $bicycle,
            $lat,
            $lng,
            $result['distanceOutside'] ?? null,
            $result
        );

        return $result;
    }

    /**
     * Log a boundary-approach warning, rate limited so a bicycle loitering on
     * the threshold cannot flood the accident log.
     */
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
            'description' => 'Bicycle is approaching the geofence boundary ('.round($result['distanceToBoundary'], 1).'m from boundary).',
            'status' => 'open',
            'acknowledged' => false,
            'alertSent' => true,
            'reportedBy' => 'gps_service',
            'warningLevel' => $result['level'] ?? 'approaching',
            'distanceFromBoundary' => $result['distanceToBoundary'],
        ]);
    }
}
