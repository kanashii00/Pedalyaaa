<?php

namespace App\Services;

use App\Models\Bicycle;
use App\Models\Rental;
use App\Models\User;

/**
 * Builds the rider-facing operating-area payload for a single rental.
 *
 * Two things matter here and both are enforced in this one place:
 *
 * 1. The operating area a rider is shown is GeofenceService::getConfig(), the
 *    same shape the admin monitoring map draws, so the two can never disagree
 *    about where a bicycle may be ridden. No geofence is read from the request.
 *
 * 2. The payload describes exactly one bicycle, and that bicycle is reached
 *    through the authenticated rider's own rental rather than an id supplied by
 *    the caller. A rider therefore cannot ask this service about a bicycle they
 *    are not riding, so no other rider's location can be exposed by tampering
 *    with the request.
 */
class RiderOperatingAreaService
{
    /**
     * Rental states where the rider is still responsible for the bicycle. These
     * match the states the rider dashboard treats as an active rental, so the
     * map keeps tracking the same bicycle the countdown is counting down.
     */
    public const LIVE_RENTAL_STATUSES = [
        Rental::STATUS_ACTIVE,
        Rental::STATUS_OVERDUE,
        Rental::STATUS_EXPIRED,
        Rental::STATUS_AWAITING_RETURN,
        Rental::STATUS_OVERDUE_RETURN,
    ];

    public function __construct(
        private GeofenceService $geofenceService
    ) {}

    public function geofence(): array
    {
        return $this->geofenceService->getConfig();
    }

    /**
     * The rider's own rental that still needs their attention, if any.
     */
    public function liveRentalFor(User $rider): ?Rental
    {
        return $rider->rentals()
            ->with('bicycle')
            ->whereIn('status', self::LIVE_RENTAL_STATUSES)
            ->latest('id')
            ->first();
    }

    /**
     * Zone classification for a bicycle, mirroring what the admin monitoring
     * map computes so a rider and an admin read the same bicycle the same way.
     */
    public function zoneFor(?Bicycle $bicycle, array $geofence): array
    {
        if (! $bicycle || $bicycle->currentLat === null || $bicycle->currentLng === null) {
            return [
                'inside' => null,
                'distance' => null,
                'distanceToBoundary' => null,
                'level' => 'unknown',
                'warning' => false,
            ];
        }

        return $this->geofenceService->checkPoint(
            (float) $bicycle->currentLat,
            (float) $bicycle->currentLng,
            $geofence
        );
    }

    /**
     * Live-payload shape, matching the field names the shared map module reads
     * out of the admin monitoring endpoint.
     */
    public function bicyclePayload(?Bicycle $bicycle, array $geofence): ?array
    {
        if (! $bicycle) {
            return null;
        }

        $zone = $this->zoneFor($bicycle, $geofence);

        return [
            'id' => $bicycle->id,
            'name' => $bicycle->serialNumber . ' ' . $bicycle->name,
            'serialNumber' => $bicycle->serialNumber,
            'status' => $bicycle->status,
            'current_lat' => $bicycle->currentLat,
            'current_lng' => $bicycle->currentLng,
            'battery_level' => $bicycle->batteryLevel,
            'lock_status' => $bicycle->lockStatus,
            'last_heartbeat' => $bicycle->lastHeartbeat,
            'zone_level' => $zone['level'],
            'zone_distance' => $zone['distance'],
            'inside' => $zone['inside'],
            'distance_to_boundary' => $zone['distanceToBoundary'] ?? null,
        ];
    }

    /**
     * Initial-render shape, matching what the shared map module reads out of the
     * `bicycles` option it is initialised with.
     */
    public function bicycleSeed(?Bicycle $bicycle, array $geofence): ?array
    {
        if (! $bicycle) {
            return null;
        }

        $zone = $this->zoneFor($bicycle, $geofence);

        return [
            'id' => $bicycle->id,
            'name' => $bicycle->serialNumber . ' ' . $bicycle->name,
            'lat' => $bicycle->currentLat !== null ? (float) $bicycle->currentLat : null,
            'lng' => $bicycle->currentLng !== null ? (float) $bicycle->currentLng : null,
            'status' => $bicycle->status,
            'battery' => $bicycle->batteryLevel,
            'locked' => $bicycle->lockStatus === null
                ? null
                : $bicycle->lockStatus === Bicycle::LOCK_LOCKED,
            'lockStatus' => $bicycle->lockStatus,
            'heartbeat' => $bicycle->lastHeartbeat,
            'zone' => $zone['level'],
            'distance' => $zone['distance'],
            'distanceToBoundary' => $zone['distanceToBoundary'] ?? null,
        ];
    }

    public function rentalPayload(?Rental $rental): ?array
    {
        if (! $rental) {
            return null;
        }

        return [
            'id' => $rental->id,
            'rentalId' => $rental->rentalId,
            'status' => $rental->status,
            'endTime' => optional($rental->endTime)->toIso8601String(),
            'returnDeadline' => optional($rental->returnDeadline)->toIso8601String(),
        ];
    }

    /**
     * The complete live payload for a rider: the shared geofence plus at most
     * one bicycle, the rider's own.
     */
    public function livePayloadFor(User $rider): array
    {
        $geofence = $this->geofence();
        $rental = $this->liveRentalFor($rider);
        $bicycle = $rental?->bicycle;

        $payload = $this->bicyclePayload($bicycle, $geofence);

        return [
            'geofence' => $geofence,
            // Always a list, and always at most one entry: the map module reads
            // a `bicycles` array, and a rider can never populate a second slot.
            'bicycles' => $payload ? [$payload] : [],
            'rental' => $this->rentalPayload($rental),
        ];
    }
}
