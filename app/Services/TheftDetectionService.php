<?php

namespace App\Services;

use App\Events\GeofenceAlert;
use App\Models\Accident;
use App\Models\Bicycle;
use App\Models\GeofenceBreach;
use App\Models\Rental;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Centralises the theft / boundary-breach detection pipeline that ties the
 * live GPS feed and the shared location map & saved geofence logic together.
 *
 * Responsibilities:
 *  - Professional security incident management with clear severity levels:
 *    critical, warning, informational.
 *  - Accurate dynamic rider identification and rental association.
 *  - Create a single open theft alert per bicycle (never duplicates while the
 *    bike remains outside the geofence).
 *  - Update the existing alert (location / distance / timestamp) until it is
 *    resolved or the bicycle returns inside.
 *  - Prevent duplicate notifications for continuing incidents.
 *  - Support unacknowledged alert notification escalation at configurable intervals.
 *  - When the bicycle returns inside the safe zone, update the alert status
 *    while keeping the historical record.
 *  - Keep the map markers, notifications, incident reports, dashboard counters
 *    and the smart lock in sync (events + real persisted state).
 */
class TheftDetectionService
{
    public const TYPE_THEFT = 'theft';

    // Severities
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_INFORMATIONAL = 'informational';

    // Statuses
    public const STATUS_OPEN = 'open';
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_FALSE_ALARM = 'false_alarm';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public function __construct(
        private NotificationService $notificationService
    ) {}

    /**
     * Evaluate a single live GPS point against the saved geofence and
     * synchronise the theft alert for the bicycle.
     *
     * @param  array  $geofenceResult  result of GeofenceService::checkPoint()
     * @return array{inside: bool, level: string, distance: float|null, alert: Accident|null}
     */
    public function processLocation(Bicycle $bicycle, float $lat, float $lng, array $geofenceResult): array
    {
        $inside = (bool) ($geofenceResult['inside'] ?? false);
        $level = $geofenceResult['level'] ?? 'safe';
        $distanceOutside = $geofenceResult['distanceOutside'] ?? null;
        $distanceToBoundary = $geofenceResult['distanceToBoundary'] ?? null;

        if (! $inside) {
            $alert = $this->openOrUpdateTheftAlert($bicycle, $lat, $lng, $distanceOutside, $geofenceResult);

            return [
                'inside' => false,
                'level' => $level,
                'distance' => $distanceOutside,
                'alert' => $alert,
            ];
        }

        // Bicycle is back inside (or has no live GPS breach) — resolve any
        // still-open theft alert but keep the record for history.
        $resolved = $this->resolveAlertOnReturn($bicycle);

        return [
            'inside' => true,
            'level' => $level,
            'distance' => $distanceToBoundary,
            'alert' => $resolved,
        ];
    }

    /**
     * Resolve the active rental for a bicycle dynamically.
     */
    public function resolveActiveRental(Bicycle $bicycle): ?Rental
    {
        if ($bicycle->currentRentalId) {
            $rental = Rental::with('rider')->find($bicycle->currentRentalId);
            if ($rental && in_array($rental->status, Rental::LIVE_STATUSES, true)) {
                return $rental;
            }
        }

        return Rental::with('rider')
            ->where('bicycleId', $bicycle->id)
            ->whereIn('status', Rental::LIVE_STATUSES)
            ->latest('startTime')
            ->first();
    }

    /**
     * Resolve rider information dynamically from the database.
     * Never hardcodes, invents, or guesses names.
     *
     * @return array{
     *   rental: Rental|null,
     *   rentalId: int|null,
     *   rider: User|null,
     *   riderId: int|null,
     *   riderName: string|null,
     *   displayName: string
     * }
     */
    public function resolveRiderDetails(Bicycle $bicycle, ?Rental $rental = null): array
    {
        $rental = $rental ?? $this->resolveActiveRental($bicycle);

        if (! $rental) {
            // Check bicycle currentRider if available as fallback
            $fallbackUser = $bicycle->currentRider ? User::find($bicycle->currentRider) : null;
            if ($fallbackUser) {
                $name = trim((string) $fallbackUser->name);
                return [
                    'rental' => null,
                    'rentalId' => null,
                    'rider' => $fallbackUser,
                    'riderId' => $fallbackUser->id,
                    'riderName' => $name !== '' ? $name : $fallbackUser->email,
                    'displayName' => $name !== '' ? $name : ($fallbackUser->email ?: 'No Active Rental'),
                ];
            }

            return [
                'rental' => null,
                'rentalId' => null,
                'rider' => null,
                'riderId' => null,
                'riderName' => null,
                'displayName' => 'No Active Rental',
            ];
        }

        $riderUser = $rental->rider ?? ($rental->riderId ? User::find($rental->riderId) : null);
        $name = null;
        if ($riderUser && ! empty(trim((string) $riderUser->name))) {
            $name = trim($riderUser->name);
        } elseif (! empty(trim((string) $rental->riderName))) {
            $name = trim($rental->riderName);
        } elseif ($riderUser && ! empty($riderUser->email)) {
            $name = $riderUser->email;
        }

        return [
            'rental' => $rental,
            'rentalId' => $rental->id,
            'rider' => $riderUser,
            'riderId' => $riderUser?->id ?? $rental->riderId,
            'riderName' => $name,
            'displayName' => $name ?: 'No Active Rental',
        ];
    }

    /**
     * Evaluate alert severity based on physical state, rental status, lock position,
     * distance, and movement.
     *
     * - Critical: suspected theft or unauthorized movement (locked bike moving, unrented bike outside, or distance > 25m).
     * - Warning: bicycle approaching or crossing boundary within small grace buffer.
     * - Informational: general monitoring status.
     */
    public function evaluateSeverity(
        Bicycle $bicycle,
        float $lat,
        float $lng,
        ?float $distanceOutside,
        array $geofenceResult
    ): string {
        $inside = (bool) ($geofenceResult['inside'] ?? false);
        $isLocked = $bicycle->lockStatus === Bicycle::LOCK_LOCKED;
        $hasActiveRental = $this->resolveActiveRental($bicycle) !== null;
        $distance = (float) ($distanceOutside ?? 0);

        if (! $inside) {
            // Locked bicycle moving outside the geofence -> unauthorized movement / critical theft
            if ($isLocked) {
                return self::SEVERITY_CRITICAL;
            }

            // Bicycle without an active rental outside the geofence -> unauthorized movement / critical theft
            if (! $hasActiveRental) {
                return self::SEVERITY_CRITICAL;
            }

            // Active rental that moved significantly outside the boundary (> 25m) -> critical suspected theft
            if ($distance > 25.0) {
                return self::SEVERITY_CRITICAL;
            }

            // Active rental crossing boundary slightly within grace buffer (<= 25m) -> warning
            return self::SEVERITY_WARNING;
        }

        if (in_array($geofenceResult['level'] ?? null, ['approaching', 'warning'], true)) {
            return self::SEVERITY_WARNING;
        }

        return self::SEVERITY_INFORMATIONAL;
    }

    /**
     * Open a theft alert for the bicycle, or update the existing active alert
     * with the latest live position so it never duplicates.
     */
    public function openOrUpdateTheftAlert(
        Bicycle $bicycle,
        float $lat,
        float $lng,
        ?float $distanceOutside,
        array $geofenceResult
    ): Accident {
        // Reuse currently active (open or investigating) theft alert for this bicycle
        $alert = $this->currentActiveAlert($bicycle);

        if (! $alert) {
            $severity = $this->evaluateSeverity($bicycle, $lat, $lng, $distanceOutside, $geofenceResult);
            $riderInfo = $this->resolveRiderDetails($bicycle);

            Log::debug('[TheftDetection] CREATING theft alert', [
                'bicycleId' => $bicycle->id,
                'lat' => $lat,
                'lng' => $lng,
                'severity' => $severity,
                'rider' => $riderInfo['displayName'],
                'distanceOutside' => $distanceOutside,
                'level' => $geofenceResult['level'] ?? null,
                'source' => 'geofence_branch',
            ]);

            $alert = $this->createAlertRow($bicycle, $lat, $lng, $distanceOutside, $severity, $geofenceResult, $riderInfo);

            // Immediate in-app notification for administrators
            $adminTitle = $severity === self::SEVERITY_CRITICAL ? 'Critical Security Alert' : 'Geofence Security Warning';
            $adminMessage = $severity === self::SEVERITY_CRITICAL
                ? "Critical Security Alert: {$bicycle->name} has moved outside its designated geofence."
                : "Geofence Warning: {$bicycle->name} is crossing the riding zone boundary (" . round((float) ($distanceOutside ?? 0), 1) . "m outside).";

            if ($riderInfo['displayName'] !== 'No Active Rental') {
                $adminMessage .= " Renter: {$riderInfo['displayName']}.";
            } else {
                $adminMessage .= " Status: No Active Rental.";
            }

            $this->notificationService->createForUsers(
                $this->adminIds(),
                $adminTitle,
                $adminMessage,
                'theft',
                [
                    'bicycleId' => $bicycle->id,
                    'rentalId' => $riderInfo['rentalId'] ? (string) $riderInfo['rentalId'] : null,
                    'incidentId' => (string) $alert->id,
                ]
            );

            // Notify active rider if present
            $targetRiderId = $riderInfo['riderId'] ?? $bicycle->currentRider;
            if ($targetRiderId) {
                $this->notificationService->create(
                    (int) $targetRiderId,
                    'Geofence Alert',
                    'You have been flagged for leaving the designated riding zone. Please return immediately or the smart lock will be engaged.',
                    'geofence_alert'
                );
            }

            if ($severity === self::SEVERITY_CRITICAL) {
                $this->autoLockOnTheft($bicycle);
            }

            event(new GeofenceAlert(
                $bicycle,
                'breach',
                (float) ($distanceOutside ?? 0),
                $lat,
                $lng
            ));
        } else {
            // Update the existing active alert with latest live position without sending duplicate notifications
            $distanceFormatted = round((float) ($distanceOutside ?? 0), 1);
            $newSeverity = $this->evaluateSeverity($bicycle, $lat, $lng, $distanceOutside, $geofenceResult);

            $updates = [
                'gpsLocation' => ['lat' => $lat, 'lng' => $lng],
                'breachDistance' => $distanceOutside,
                'distanceFromBoundary' => $distanceOutside,
                'description' => "Bicycle remains outside the riding zone ({$distanceFormatted}m outside boundary).",
                'updated_at' => now(),
            ];

            // If severity escalated to critical and alert was warning, escalate
            if ($newSeverity === self::SEVERITY_CRITICAL && $alert->severity !== self::SEVERITY_CRITICAL) {
                $updates['severity'] = self::SEVERITY_CRITICAL;
                $this->autoLockOnTheft($bicycle);
            }

            $alert->update($updates);

            event(new GeofenceAlert(
                $bicycle,
                'breach',
                (float) ($distanceOutside ?? 0),
                $lat,
                $lng
            ));
        }

        $this->syncBreachRecord($bicycle, $lat, $lng, $distanceOutside, $geofenceResult);

        return $alert->fresh();
    }

    /**
     * Reconcile a map bicycle that is currently outside the geofence so that it
     * ALWAYS has a single active, database-backed theft alert.
     */
    public function ensureActiveAlertForOutside(
        Bicycle $bicycle,
        float $lat,
        float $lng,
        ?float $distanceOutside,
        array $geofenceResult
    ): Accident {
        $alert = $this->currentActiveAlert($bicycle);

        if (! $alert) {
            Log::debug('[TheftDetection] RECONCILE creating missing open alert', [
                'bicycleId' => $bicycle->id,
                'lat' => $lat,
                'lng' => $lng,
                'distanceOutside' => $distanceOutside,
                'level' => $geofenceResult['level'] ?? null,
                'source' => 'read_path_reconcile',
            ]);

            $severity = $this->evaluateSeverity($bicycle, $lat, $lng, $distanceOutside, $geofenceResult);
            $riderInfo = $this->resolveRiderDetails($bicycle);
            $alert = $this->createAlertRow($bicycle, $lat, $lng, $distanceOutside, $severity, $geofenceResult, $riderInfo);
        } else {
            $alert->update([
                'gpsLocation' => ['lat' => $lat, 'lng' => $lng],
                'breachDistance' => $distanceOutside,
                'distanceFromBoundary' => $distanceOutside,
                'updated_at' => now(),
            ]);
        }

        return $alert->fresh();
    }

    private function createAlertRow(
        Bicycle $bicycle,
        float $lat,
        float $lng,
        ?float $distanceOutside,
        ?string $severity = null,
        array $geofenceResult = [],
        ?array $riderInfo = null
    ): Accident {
        $riderInfo = $riderInfo ?? $this->resolveRiderDetails($bicycle);
        $severity = $severity ?? $this->evaluateSeverity($bicycle, $lat, $lng, $distanceOutside, $geofenceResult);

        $distFormatted = round((float) ($distanceOutside ?? 0), 1);
        $desc = "Bicycle exited the designated riding zone ({$distFormatted}m outside boundary).";
        if ($riderInfo['displayName'] !== 'No Active Rental') {
            $desc .= " Rented by {$riderInfo['displayName']}.";
        } else {
            $desc .= " No active rental associated.";
        }

        return Accident::create([
            'bicycleId' => $bicycle->id,
            'rentalId' => $riderInfo['rentalId'],
            'riderId' => $riderInfo['riderId'],
            'riderName' => $riderInfo['riderName'],
            'type' => self::TYPE_THEFT,
            'severity' => $severity,
            'gpsLocation' => ['lat' => $lat, 'lng' => $lng],
            'description' => $desc,
            'status' => self::STATUS_OPEN,
            'investigationStatus' => 'pending',
            'acknowledged' => false,
            'alertSent' => true,
            'reportedBy' => 'gps_service',
            'breachDistance' => $distanceOutside,
            'breachDirection' => 'outside',
            'warningLevel' => $severity === self::SEVERITY_CRITICAL ? 'breach' : 'warning',
            'distanceFromBoundary' => $distanceOutside,
            'actionTaken' => 'Incident logged. Sent for review. Smart lock engaged per policy.',
        ]);
    }

    /**
     * When the bicycle returns inside the safe zone, mark the open theft alert
     * as returned/acknowledged for history but keep the record.
     */
    public function resolveAlertOnReturn(Bicycle $bicycle): ?Accident
    {
        $alert = $this->currentActiveAlert($bicycle);

        if (! $alert) {
            return null;
        }

        Log::debug('[TheftDetection] RESOLVING alert on return', [
            'bicycleId' => $bicycle->id,
            'alertId' => $alert->id,
            'status' => self::STATUS_RETURNED,
        ]);

        $alert->update([
            'status' => self::STATUS_RETURNED,
            'investigationStatus' => 'returned',
            'actionTaken' => 'Bicycle returned inside the riding zone. Incident preserved for historical audit.',
            'acknowledged' => true,
            'resolvedAt' => now(),
            'updated_at' => now(),
        ]);

        GeofenceBreach::where('bicycleId', $bicycle->id)
            ->whereNull('resolvedAt')
            ->update(['resolvedAt' => now()]);

        return $alert->fresh();
    }

    /**
     * Find the currently active theft alert for a bicycle (open or investigating).
     * This prevents duplicate active alerts while an incident is ongoing.
     */
    public function currentActiveAlert(Bicycle $bicycle): ?Accident
    {
        return Accident::where('bicycleId', $bicycle->id)
            ->where('type', self::TYPE_THEFT)
            ->whereIn('status', [self::STATUS_OPEN, self::STATUS_INVESTIGATING])
            ->with(['bicycle', 'riderUser', 'rental'])
            ->latest('id')
            ->first();
    }

    /**
     * Escalate unacknowledged critical theft alerts based on configurable interval.
     * Avoids sending repeated notifications after acknowledgment or resolution.
     */
    public function escalateUnacknowledgedAlerts(?int $intervalMinutes = null): int
    {
        $interval = $intervalMinutes ?? (int) SystemSetting::getValue('theft_escalation_interval_minutes', 15);
        if ($interval <= 0) {
            $interval = 15;
        }

        $cutoff = now()->subMinutes($interval);
        $alerts = Accident::with(['bicycle', 'riderUser', 'rental'])
            ->where('type', self::TYPE_THEFT)
            ->where('severity', self::SEVERITY_CRITICAL)
            ->where('acknowledged', false)
            ->whereIn('status', [self::STATUS_OPEN, self::STATUS_INVESTIGATING])
            ->where(function ($q) use ($cutoff) {
                $q->where(function ($sub) use ($cutoff) {
                    $sub->whereNull('lastEscalatedAt')
                        ->where('created_at', '<=', $cutoff);
                })->orWhere(function ($sub) use ($cutoff) {
                    $sub->whereNotNull('lastEscalatedAt')
                        ->where('lastEscalatedAt', '<=', $cutoff);
                });
            })
            ->get();

        $escalatedCount = 0;
        foreach ($alerts as $alert) {
            $minutesOpen = (int) $alert->created_at->diffInMinutes(now());
            $this->notificationService->createForUsers(
                $this->adminIds(),
                'Escalation: Unacknowledged Theft Alert',
                "Critical Security Alert for bicycle {$alert->bicycle?->name} (#{$alert->bicycleId}) has remained unacknowledged for {$minutesOpen} minutes. Renter: {$alert->riderDisplayName}.",
                'theft',
                ['bicycleId' => $alert->bicycleId, 'incidentId' => (string) $alert->id]
            );

            $alert->update(['lastEscalatedAt' => now()]);
            $escalatedCount++;
        }

        return $escalatedCount;
    }

    /**
     * Keep the physical GeofenceBreach record in sync with the alert lifecycle.
     */
    private function syncBreachRecord(
        Bicycle $bicycle,
        float $lat,
        float $lng,
        ?float $distanceOutside,
        array $geofenceResult
    ): void {
        $breach = GeofenceBreach::where('bicycleId', $bicycle->id)
            ->whereNull('resolvedAt')
            ->first();

        $geofenceId = $geofenceResult['id'] ?? ($geofenceResult['geofenceId'] ?? null);
        $geofenceId = is_scalar($geofenceId) ? $geofenceId : null;

        if (! $breach) {
            GeofenceBreach::create([
                'bicycleId' => $bicycle->id,
                'geofenceId' => $geofenceId,
                'lat' => $lat,
                'lng' => $lng,
                'distance' => $distanceOutside,
                'acknowledged' => false,
                'resolvedAt' => null,
            ]);
        } else {
            $breach->update([
                'lat' => $lat,
                'lng' => $lng,
                'distance' => $distanceOutside,
            ]);
        }
    }

    private function autoLockOnTheft(Bicycle $bicycle): void
    {
        $enabled = filter_var(SystemSetting::getValue('auto_lock_on_theft', true), FILTER_VALIDATE_BOOLEAN);

        if (! $enabled) {
            return;
        }

        try {
            app(IoTService::class)->sendCommand($bicycle->id, 'lock', ['reason' => 'geofence_breach']);
        } catch (\Throwable $e) {
            Log::warning('[TheftDetection] Failed to send lock command: ' . $e->getMessage());
        }

        $bicycle->update(['lockStatus' => Bicycle::LOCK_LOCKED]);
    }

    private function adminIds(): array
    {
        return User::where('role', User::ROLE_ADMIN)->pluck('id')->all();
    }
}
