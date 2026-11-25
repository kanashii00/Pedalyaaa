<?php

namespace App\Http\Resources;

use App\Models\Bicycle;
use App\Models\Rental;
use App\Services\RentalService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Payload for the on-board ESP32 countdown display.
 *
 * Unlike RentalResource this surfaces the deadline and penalty columns, and
 * ships the server clock alongside the data so a device with no battery-backed
 * RTC can correct for drift and still count down accurately between polls.
 *
 * rental may be null: an idle bicycle is a normal state, not an error, and the
 * display simply shows its ready screen.
 */
class DeviceRentalResource extends JsonResource
{
    public function __construct(
        protected Bicycle $bicycle,
        protected ?Rental $rental,
        protected RentalService $rentalService,
        protected Carbon $serverTime
    ) {
        parent::__construct($rental);
    }

    public function toArray(Request $request): array
    {
        return [
            'bicycle' => [
                'id' => $this->bicycle->id,
                'serialNumber' => $this->bicycle->serialNumber,
                'name' => $this->bicycle->name,
                'status' => $this->bicycle->status,
                'lockStatus' => $this->bicycle->lockStatus,
                'manualUnlockOverrideUntil' => $this->bicycle->manualUnlockOverrideUntil?->toIso8601String(),
                'manualUnlockOverrideActive' => $this->bicycle->manualUnlockOverrideUntil?->isFuture() ?? false,
                'batteryLevel' => $this->bicycle->batteryLevel !== null
                    ? (int) $this->bicycle->batteryLevel
                    : null,
            ],

            'rental' => $this->rental ? $this->rentalPayload() : null,

            'clock' => [
                'serverTime' => $this->serverTime->toIso8601String(),
                'serverTimeUtc' => $this->serverTime->copy()->utc()->toIso8601String(),
                'unix' => $this->serverTime->getTimestamp(),
                'utcOffsetSeconds' => $this->serverTime->getOffset(),
                'timezone' => $this->serverTime->getTimezone()->getName(),
            ],

            'pollAfterSeconds' => (int) config('services.device_display.poll_after_seconds', 15),
        ];
    }

    private function rentalPayload(): array
    {
        /** @var Rental $rental */
        $rental = $this->resource;
        $clock = $this->rentalService->rentalClockFor($rental, $this->serverTime);

        return [
            'rentalId' => $rental->rentalId,
            'status' => $rental->status,
            'riderName' => $rental->riderName,
            'durationFormatted' => $rental->durationFormatted,

            'startTime' => $rental->startTime?->toIso8601String(),
            'startTimeUtc' => $rental->startTime?->copy()->utc()->toIso8601String(),
            'startTimestamp' => $clock['startTime']?->getTimestamp(),

            'returnDeadline' => $clock['effectiveDeadline']?->toIso8601String(),
            'returnDeadlineUtc' => $clock['effectiveDeadline']?->copy()->utc()->toIso8601String(),
            'returnDeadlineTimestamp' => $clock['effectiveDeadline']?->getTimestamp(),
            'deadlineBasis' => $clock['deadlineBasis'],
            'deadlineSetBy' => $rental->returnDeadlineSetBy,

            'elapsedSeconds' => $clock['elapsedSeconds'],
            'remainingSeconds' => $clock['remainingSeconds'],
            'overdueSeconds' => $clock['overdueSeconds'],
            'isOverdue' => $clock['isOverdue'],
            'progressPercent' => $clock['progressPercent'],

            'ratePerHour' => $clock['ratePerHour'],
            'projectedBaseFee' => $clock['projectedBaseFee'],

            'penalty' => [
                'accrued' => $clock['projectedPenalty'],
                'basis' => $clock['penaltyBasis'],
                'rule' => $clock['rule']['type'],
                'ruleValue' => $clock['rule']['value'],
                'isDefaultRate' => $clock['rule']['isDefaultRate'],
                'projectedTotal' => $clock['projectedTotal'],
            ],
        ];
    }
}
