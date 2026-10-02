<?php

namespace App\Services;

use App\Models\Bicycle;
use App\Models\MaintenanceRecord;
use Carbon\Carbon;

class MaintenanceService
{
    /**
     * Place a bicycle into maintenance.
     *
     * Creates an active maintenance record for the bicycle (unless one
     * already exists), sets the bicycle status to Maintenance, and locks
     * the smart-lock so the unit is unavailable for rental.
     *
     * Returns the MaintenanceRecord that was created or already active.
     */
    public function placeBicycleInMaintenance(
        Bicycle $bicycle,
        ?string $description = null,
        ?int $createdBy = null,
    ): MaintenanceRecord {
        $existing = MaintenanceRecord::where('bicycleId', $bicycle->id)
            ->whereIn('status', [MaintenanceRecord::STATUS_SCHEDULED, MaintenanceRecord::STATUS_IN_PROGRESS])
            ->latest()
            ->first();

        if ($existing) {
            return $existing;
        }

        $record = MaintenanceRecord::create([
            'bicycleId' => $bicycle->id,
            'bicycleName' => $bicycle->name,
            'description' => $description ?? 'Maintenance initiated from Bicycle Inventory',
            'type' => 'other',
            'severity' => 'low',
            'status' => MaintenanceRecord::STATUS_SCHEDULED,
            'createdBy' => $createdBy,
        ]);

        // Parking a bicycle in maintenance is valid for every unit, in service or
        // not. Only the in-service unit has a smart lock to secure; a bicycle
        // that is already out of service keeps the lock state it is recorded in.
        $bicycle->update($bicycle->isInService()
            ? [
                'status' => Bicycle::STATUS_MAINTENANCE,
                'lockStatus' => Bicycle::LOCK_LOCKED,
            ]
            : ['status' => Bicycle::STATUS_MAINTENANCE]);

        return $record;
    }

    /**
     * Check whether a bicycle can be released from maintenance.
     *
     * Returns true when no other active (scheduled / in_progress) records
     * exist for the bicycle, meaning it is safe to return to Available.
     */
    public function canReleaseBicycle(Bicycle $bicycle, ?int $excludeRecordId = null): bool
    {
        $query = MaintenanceRecord::where('bicycleId', $bicycle->id)
            ->whereIn('status', [MaintenanceRecord::STATUS_SCHEDULED, MaintenanceRecord::STATUS_IN_PROGRESS]);

        if ($excludeRecordId) {
            $query->where('id', '!=', $excludeRecordId);
        }

        return ! $query->exists();
    }

    /**
     * Automated completion rule.
     *
     * When a maintenance record is set to "Completed", the corresponding
     * bicycle is released back into the Bicycle Inventory: its status
     * becomes "Available", the last-maintenance date is stamped, and its
     * condition is reset to good.
     *
     * Only bicycles actually parked in maintenance are released — bikes
     * that are rented, removed or otherwise occupied are left untouched.
     * Idempotent by construction thanks to that guard.
     *
     * A bicycle that is not in service is *not* returned to Available when its
     * maintenance closes. Repairing it does not put a lock on it or put it on
     * the road, so it goes back to being parked out of service. The
     * maintenance bookkeeping (last-maintenance date, condition) still applies,
     * and its recorded lockStatus is never touched.
     */
    public function releaseBicycleForRecord(MaintenanceRecord $record): bool
    {
        $bicycle = $this->completedBicycle($record);

        if ($bicycle === null || $bicycle->status !== Bicycle::STATUS_MAINTENANCE) {
            return false;
        }

        if (! $this->canReleaseBicycle($bicycle, $record->id)) {
            return false;
        }

        $bicycle->update([
            'status' => $bicycle->isInService()
                ? Bicycle::STATUS_AVAILABLE
                : Bicycle::STATUS_OUT_OF_SERVICE,
            'lastMaintenanceDate' => Carbon::now(),
            'condition' => 'good',
        ]);

        if ($bicycle->isInService()) {
            $bicycle->update(['lockStatus' => Bicycle::LOCK_LOCKED]);
        }

        return true;
    }

    private function completedBicycle(MaintenanceRecord $record): ?Bicycle
    {
        if (! in_array($record->status, [MaintenanceRecord::STATUS_COMPLETED, MaintenanceRecord::STATUS_CANCELLED])
            || ! $record->bicycleId) {
            return null;
        }

        return Bicycle::find($record->bicycleId);
    }
}
