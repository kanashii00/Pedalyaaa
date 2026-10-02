<?php

namespace App\Console\Commands;

use App\Models\Bicycle;
use Illuminate\Console\Command;

/**
 * Reconcile the fleet against the service policy in config('pedalya.fleet').
 *
 * Exactly one bicycle - the serial named by the policy - is in service and
 * rentable. Every other bicycle is parked: out of service, not rentable, and its
 * recorded lockStatus is left exactly as it is, because there is no hardware
 * behind it and its stored value is the accurate physical state.
 *
 * This is idempotent and safe to run against a live database. It is the
 * supported way to bring an existing database in line with the policy after the
 * migration, and to re-assert the policy if rows have drifted.
 *
 * Note it deliberately does NOT repair a rented in-service bicycle: if PDY-001
 * is mid-rental, that is real state and must be settled through End Ride rather
 * than overwritten here.
 */
class ReconcileFleetServiceCommand extends Command
{
    protected $signature = 'pedalya:fleet-reconcile
                            {--dry-run : Report what would change without writing}
                            {--clear-orphan-commands : Also fail pending commands queued for parked bicycles}';

    protected $description = 'Provision the in-service bicycle and park every other one as out of service.';

    public function handle(): int
    {
        $serial = config('pedalya.fleet.in_service_serial');
        $dryRun = (bool) $this->option('dry-run');

        if (! $serial) {
            $this->error('No in-service serial configured (config pedalya.fleet.in_service_serial).');

            return self::FAILURE;
        }

        $inService = Bicycle::where('serialNumber', $serial)->first();

        if (! $inService) {
            $this->error("Configured in-service bicycle \"{$serial}\" does not exist.");

            return self::FAILURE;
        }

        $this->line("In-service unit: <info>{$inService->serialNumber} - {$inService->name}</info> (id {$inService->id})");

        $changes = 0;

        // The in-service unit: provisioned, and Available whenever it is not
        // legitimately busy. Rented/Maintenance/Removed are real operational
        // states and are never overwritten.
        if (! $inService->isInService()) {
            $this->warn("  {$inService->serialNumber}: provisioning as in service.");
            $changes++;
            $dryRun ? null : $inService->provisionForService();
        }

        if ($inService->isInService()
            && $inService->status === Bicycle::STATUS_OUT_OF_SERVICE) {
            $this->warn("  {$inService->serialNumber}: locked -> available.");
            $changes++;
            $dryRun ? null : $inService->update(['status' => Bicycle::STATUS_AVAILABLE]);
        }

        // Everything else: parked. Status becomes `locked` (out of service, part
        // of the column enum) and lockStatus is left untouched. Maintenance rows
        // are never brought out of maintenance here - open work must complete via
        // the existing maintenance workflow.
        $others = Bicycle::where('serialNumber', '!=', $serial)->get();

        foreach ($others as $bicycle) {
            $needsParking = false;

            if ($bicycle->isInService()) {
                $needsParking = true;
            }

            if (! $needsParking && $bicycle->status === Bicycle::STATUS_MAINTENANCE) {
                // Preserve open maintenance: the bicycle remains non-rentable and
                // with no hardware commands, but its status stays maintenance.
                continue;
            }

            if (! $needsParking && $bicycle->status !== Bicycle::STATUS_OUT_OF_SERVICE) {
                $needsParking = true;
            }

            if ($needsParking) {
                $this->warn(sprintf(
                    '  %s: %s -> out of service%s (lock left as %s).',
                    $bicycle->serialNumber,
                    $bicycle->status,
                    $bicycle->status === Bicycle::STATUS_MAINTENANCE ? ', maintenance preserved' : '',
                    $bicycle->lockStatus
                ));
                $changes++;

                if ($dryRun) {
                    continue;
                }

                $bicycle->sendOutOfService();

                if ($bicycle->status !== Bicycle::STATUS_OUT_OF_SERVICE && $bicycle->status !== Bicycle::STATUS_MAINTENANCE) {
                    $bicycle->update(['status' => Bicycle::STATUS_OUT_OF_SERVICE]);
                }
            }
        }

        // A pending command against a bicycle that has no device will never be
        // acknowledged. Clearing it is an irreversible write to rows that this
        // policy change did not create, so it is opt-in rather than implicit.
        if ($this->option('clear-orphan-commands')) {
            $orphaned = \App\Models\DeviceCommand::where('status', 'pending')
                ->whereIn('bicycleId', $others->pluck('id'))
                ->update(['status' => 'failed', 'response' => 'Superseded: bicycle is not in service.']);

            if ($orphaned > 0) {
                $this->warn("  Cleared {$orphaned} orphaned pending device command(s).");
                $changes += $orphaned;
            }
        } else {
            $queued = \App\Models\DeviceCommand::where('status', 'pending')
                ->whereIn('bicycleId', $others->pluck('id'))
                ->count();

            if ($queued > 0) {
                $this->line("  {$queued} pending device command(s) left untouched; rerun with --clear-orphan-commands to fail them.");
            }
        }

        if ($dryRun) {
            $this->info("Dry run: {$changes} change(s) would be applied.");
        } else {
            $this->info("Fleet reconciled: {$changes} change(s) applied.");
        }

        return self::SUCCESS;
    }
}