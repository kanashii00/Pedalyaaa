<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Bicycle;
use App\Models\DeviceCommand;
use App\Models\DeviceStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class DeviceCommandService
{
    /**
     * Queue a command for a bicycle's on-board device.
     *
     * Returns null when the bicycle is not in service. That gate lives here, at
     * the only place a DeviceCommand row is ever created, so no caller can
     * bypass it: a bicycle without hardware must never accumulate a `pending`
     * command that will sit unacknowledged forever, because
     * normalizeCommandStatus() would eventually treat some unrelated ack as
     * proof it executed and rewrite the bicycle's state on the strength of a
     * lock that was never fitted.
     *
     * Callers that ignore the return value are unaffected; callers that report
     * the outcome to a person (the API, the admin screen) get null and say so.
     */
    public function sendCommand(int $bicycleId, string $command, array $params, ?User $user = null): ?DeviceCommand
    {
        $bicycle = Bicycle::find($bicycleId);

        if (! $bicycle) {
            return null;
        }

        if (! $bicycle->isInService()) {
            Log::warning('IoT command suppressed for out-of-service bicycle', [
                'bicycleId' => $bicycleId,
                'command' => $command,
            ]);

            return null;
        }

        $deviceCommand = DeviceCommand::create([
            'bicycleId' => $bicycleId,
            'command' => $command,
            'params' => $params ?: null,
            'status' => 'pending',
            'issuedBy' => $user?->id,
        ]);

        DeviceStatus::create([
            'bicycleId' => $bicycleId,
            'command' => $command,
            'params' => $params ?: null,
            'commandIssuedBy' => $user?->id,
            'commandIssuedAt' => Carbon::now(),
            'type' => 'command',
            'eventTimestamp' => Carbon::now(),
        ]);

        return $deviceCommand;
    }

    public function acknowledgeDeviceCommand(
        int $deviceCommandId,
        ?string $result = null,
        string $status = 'executed'
    ): bool {
        $command = DeviceCommand::find($deviceCommandId);

        if (! $command) {
            return false;
        }

        $validStatus = $this->normalizeCommandStatus($status);
        $previousStatus = $command->status;

        $command->update([
            'status' => $validStatus,
            'executedAt' => $validStatus === 'executed' ? now() : null,
            'response' => $result,
        ]);

        if ($validStatus === 'executed') {
            $this->applyCommandEffect($command);
        }

        // Device-reported outcome: logged once per real transition so polling
        // or duplicate acknowledgements can never double-record it. There is
        // no authenticated actor here — the device reported back — so the
        // record carries the originating admin for accountability instead of
        // claiming one.
        if ($previousStatus !== $validStatus && in_array($validStatus, ['executed', 'failed'], true)) {
            AuditLog::record(
                $validStatus === 'executed' ? 'lock_command_executed' : 'lock_command_failed',
                null,
                [
                    'bicycleId' => $command->bicycleId,
                    'command' => $command->command,
                    'issuedBy' => $command->issuedBy,
                    'reported_by' => 'device',
                    'outcome' => $validStatus === 'executed' ? 'success' : 'failed',
                    'changes' => [
                        'status' => ['from' => $previousStatus, 'to' => $validStatus],
                    ],
                ]
            );
        }

        return true;
    }

    public function getPendingCommands(int $bicycleId): array
    {
        return DeviceCommand::where('bicycleId', $bicycleId)
            ->where('status', 'pending')
            ->orderBy('id', 'asc')
            ->take(5)
            ->get()
            ->map(fn (DeviceCommand $cmd) => [
                'id' => $cmd->id,
                'command' => $cmd->command,
                'params' => $cmd->params,
                'issued_at' => $cmd->created_at,
            ])
            ->toArray();
    }

    private function normalizeCommandStatus(string $status): string
    {
        return in_array($status, ['executed', 'failed', 'sent'], true)
            ? $status
            : 'executed';
    }

    private function applyCommandEffect(DeviceCommand $command): void
    {
        if (! in_array($command->command, ['lock', 'unlock'], true)) {
            return;
        }

        $bicycle = Bicycle::find($command->bicycleId);

        if (! $bicycle) {
            return;
        }

        // A bicycle that is not in service has no hardware, so it must never be
        // promoted to Available here. This rewrite used to fire on every lock
        // acknowledgement for any bicycle, which is how a parked unit could be
        // dragged back into the rentable fleet by a stray ack.
        if (! $bicycle->isInService()) {
            return;
        }

        // Maintenance is an operator decision and outranks the lock state, so
        // acknowledging a lock may never quietly return a serviced bicycle to
        // the rentable fleet.
        $status = match (true) {
            $bicycle->status === Bicycle::STATUS_MAINTENANCE => Bicycle::STATUS_MAINTENANCE,
            $bicycle->currentRentalId !== null => Bicycle::STATUS_RENTED,
            default => Bicycle::STATUS_AVAILABLE,
        };

        $bicycle->update([
            'lockStatus' => $command->command === 'unlock'
                ? Bicycle::LOCK_UNLOCKED
                : Bicycle::LOCK_LOCKED,
            'status' => $status,
            'lastLockAction' => Carbon::now(),
            'lockActionBy' => $command->issuedBy,
        ]);
    }
}
