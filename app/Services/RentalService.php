<?php

namespace App\Services;

use App\Exceptions\RentalException;
use App\Models\Bicycle;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RentalService
{
    /** Pedalya rental price: ₱30 per 20 minutes, billed proportionally. */
    public const RENTAL_RATE_PER_MINUTE = 1.50;
    public const RENTAL_RATE_PER_HOUR = 90.00;
    /** Late returns cost this amount for each fully completed five-minute block. */
    public const LATE_PENALTY_PER_COMPLETED_FIVE_MINUTES = 20.00;
protected NotificationService $notificationService;
protected IoTService $iotService;
protected MaintenanceService $maintenanceService;

    public function __construct(
        NotificationService $notificationService,
        IoTService $iotService,
        MaintenanceService $maintenanceService
    ) {
        $this->notificationService = $notificationService;
        $this->iotService = $iotService;
        $this->maintenanceService = $maintenanceService;
    }

public function startRental(
        User $user,
        int $bicycleId,
        int $durationMinutes = 30,
        string $paymentMethod = 'cash',
        ?string $paymentReference = null
    ): Rental {
        if ($paymentMethod !== 'cash') {
            throw new RentalException('Cash is the only available payment method for rentals.');
        }
        if ($durationMinutes < 1 || $durationMinutes > 720) {
            throw new RentalException('The rental duration must be between 1 and 720 minutes.');
        }
        if (!$user->verified) {
            throw new RentalException(
                'User must be verified to start a rental.'
            );
        }

        $activeRental = Rental::where('riderId', $user->id)
            ->whereIn('status', [
                'active',
                'pending',
                'overdue',
                Rental::STATUS_AWAITING_RETURN,
            ])
            ->first();

        if ($activeRental) {
            throw new RentalException(
                'User already has an active rental.'
            );
        }

        $bicycle = Bicycle::findOrFail($bicycleId);

        // A bicycle parked out of service carries `status = available` but has no
        // hardware to unlock, so it must never start a rental. Checked before
        // status so the message names the real reason.
        if (! $bicycle->isInService()) {
            throw new RentalException(
                "Bicycle {$bicycle->serialNumber} is not in service."
            );
        }

        if ($bicycle->status !== Bicycle::STATUS_AVAILABLE) {
            throw new RentalException(
                'Bicycle is not available for rental.'
            );
        }

        if ($bicycle->batteryLevel === null) {
            throw new RentalException('Bicycle battery level has not been reported.');
        }

        if ($bicycle->batteryLevel < 20) {
            throw new RentalException(
                'Bicycle battery level is too low to rent.'
            );
        }

        // This is deliberately independent of bicycle.hourlyRate: Pedalya
        // charges ₱1.50 for every minute (₱30 / 20 min) with no hourly
        // rounding. Store its hourly equivalent for legacy reporting fields.
        $ratePerHour = self::RENTAL_RATE_PER_HOUR;
        $chargedHours = max((int) ceil($durationMinutes / 60), 1);
        $totalFee = round($durationMinutes * self::RENTAL_RATE_PER_MINUTE, 2);

        $durationFormatted = $this->formatDuration($durationMinutes);

        $pricing = [
            'ratePerHour' => $ratePerHour,
            'chargedHours' => $chargedHours,
            'totalFee' => $totalFee,
            'durationFormatted' => $durationFormatted,
        ];

        $isGcash = false;
        $rental = DB::transaction(function () use (
            $user,
            $bicycleId,
            $durationMinutes,
            $paymentMethod,
            $paymentReference,
            $pricing
        ) {
            // Re-read under a row lock. The availability check above ran outside
            // the transaction, so two riders submitting the same bicycle
            // concurrently could both pass it; the lock serialises them and the
            // second one then sees the status the first already changed.
            $locked = Bicycle::query()->lockForUpdate()->find($bicycleId);

            if ($locked === null) {
                throw new RentalException('Bicycle no longer exists.');
            }

            // Re-checked under the row lock: provisioning can be revoked between
            // the pre-transaction check and here.
            if (! $locked->isInService()) {
                throw new RentalException(
                    "Bicycle {$locked->serialNumber} is not in service."
                );
            }

            if ($locked->status !== Bicycle::STATUS_AVAILABLE) {
                throw new RentalException(
                    'That bicycle is no longer available for rent.'
                );
            }

            if ($locked->batteryLevel === null) {
                throw new RentalException('Bicycle battery level has not been reported.');
            }

            if ($locked->batteryLevel < 20) {
                throw new RentalException(
                    'Bicycle battery level is too low to rent.'
                );
            }

            return $this->persistRental(
                $user,
                $locked,
                $durationMinutes,
                $paymentMethod,
                $paymentReference,
                $pricing
            );
        });

        // Queue unlock only after the rental transaction succeeds.
        // The actual lockStatus changes only after the device acknowledges it.
        if (!$isGcash) {
            $this->iotService->sendCommand(
                $bicycle->id,
                'unlock',
                [
                    'reason' => 'rental_started',
                    'rental_id' => $rental->id,
                    'rental_code' => $rental->rentalId,
                ],
                $user
            );
        }

        return $rental;
    }

    private function persistRental(
        User $user,
        Bicycle $bicycle,
        int $durationMinutes,
        string $paymentMethod,
        ?string $paymentReference,
        array $pricing
    ): Rental {
        $isGcash = $paymentMethod === 'gcash';
        $ratePerHour = $pricing['ratePerHour'];
        $chargedHours = $pricing['chargedHours'];
        $totalFee = $pricing['totalFee'];
        $durationFormatted = $pricing['durationFormatted'];

        $startTime = Carbon::now();

        $expectedEndTime = $startTime
            ->copy()
            ->addMinutes($durationMinutes);

        $rental = Rental::create([
            'rentalId' => $this->generateRentalId(),

            'riderId' => $user->id,
            'riderName' => $user->name,
            'riderEmail' => $user->email,

            'bicycleId' => $bicycle->id,
            'bicycleName' => $bicycle->name,
            'bicycleSerial' => $bicycle->serialNumber,

            'startTime' => $startTime,
            'expectedEndTime' => $expectedEndTime,

            'startLocation' => [
                'lat' => $bicycle->currentLat,
                'lng' => $bicycle->currentLng,
            ],

            'status' => $isGcash ? 'pending' : 'active',

            'ratePerHour' => $ratePerHour,
            'totalFee' => $totalFee,

            'durationMinutes' => $durationMinutes,
            'durationFormatted' => $durationFormatted,
            'chargedHours' => $chargedHours,

            'paymentMethod' => $paymentMethod,
            'paymentReference' => $paymentReference,
            'paymentStatus' => $isGcash
                ? 'pending_verification'
                : 'paid',
        ]);

        if (!$isGcash) {
            $bicycle->update([
                'status' => Bicycle::STATUS_RENTED,
                'currentRider' => $user->id,
                'currentRentalId' => $rental->id,
            ]);

            $user->increment('totalRentals');
        }

        if ($isGcash) {
            Payment::create([
                'rentalId' => $rental->id,
                'userId' => $user->id,
                'bicycleId' => $bicycle->id,

                'paymentReference' =>
                    $paymentReference
                    ?? 'GC-' . strtoupper(
                        substr(bin2hex(random_bytes(4)), 0, 8)
                    ),

                'paymentMethod' => 'gcash',
                'amount' => $totalFee,
                'convenienceFee' => 0,
                'totalAmount' => $totalFee,
                'latePenalty' => 0,
                'amountPaid' => 0,
                'balanceDue' => $totalFee,
                'currency' => 'PHP',
                'status' => 'pending',

                'paymentDetails' => [
                    'rental_id' => $rental->rentalId,
                    'duration_minutes' => $durationMinutes,
                    'bicycle_name' => $bicycle->name,
                    'bicycle_serial' => $bicycle->serialNumber,
                ],
            ]);
        }

        $this->notificationService->create(
            $user->id,
            $isGcash
                ? 'GCash Payment Submitted'
                : 'Rental Started',
            $isGcash
                ? "Your rental {$rental->rentalId} is pending payment verification. Please wait for admin approval."
                : "Your rental {$rental->rentalId} has started. Pay PHP {$totalFee} upon return. Enjoy your ride!",
            'rental_started'
        );

        $adminIds = User::where('role', User::ROLE_ADMIN)->pluck('id')->all();
        if (!empty($adminIds)) {
            $this->notificationService->createForUsers(
                $adminIds,
                'New Rental',
                $isGcash
                    ? "{$user->name} submitted a rental for bicycle {$bicycle->name} (#{$bicycle->serialNumber}). Rental {$rental->rentalId} is pending payment verification."
                    : "{$user->name} started a rental for bicycle {$bicycle->name} (#{$bicycle->serialNumber}). Rental {$rental->rentalId} · PHP {$totalFee}.",
                'rental_started',
                ['rentalId' => $rental->rentalId, 'bicycleId' => $bicycle->id]
            );
        }

        RentalFeed::touch();

        return $rental;
    }

    /**
     * Phase 1 of the return lifecycle: record the rider's return request.
     *
     * This is deliberately NOT the actual return. The rider may tap Return
     * before the bicycle is physically back, so only the server-side request
     * instant is persisted. The rental moves into "awaiting_return" and the
     * bicycle is held (kept Rented + the smart lock secured) until an
     * administrator inspects it and records the verified arrival time via
     * processReturn(). endTime is therefore left untouched here: it is the
     * verified physical arrival and is written only during settlement.
     *
     * The $returnTime argument is retained for backwards compatibility with
     * callers that used to supply a timestamp, but it is intentionally ignored.
     * A client-supplied request time must never become the basis for lateness.
     */
    public function markRideEnded(
        Rental $rental,
        User $user,
        ?float $returnLat = null,
        ?float $returnLng = null,
        ?Carbon $returnTime = null,
        ?string $notes = null
    ): array {
        if (!in_array($rental->status, ['active', 'overdue', 'expired'], true)) {
            throw new RentalException('Only active, overdue, or expired rentals can be returned.');
        }

        return DB::transaction(function () use ($rental, $user, $returnLat, $returnLng, $notes) {
            $rental->update([
                'endLocation' => ['lat' => $returnLat, 'lng' => $returnLng],
                'notes' => $notes,
                'status' => Rental::STATUS_AWAITING_RETURN,
                'returnRequestedAt' => Carbon::now(),
            ]);

            // The rider has asked to return the bicycle. Secure the smart lock
            // as a precaution, but keep the bicycle held (Rented) until an
            // administrator verifies its physical arrival and processes the
            // return. A bicycle that is not in service has no lock to secure,
            // and its recorded lockStatus is left exactly as it is.
            $bicycle = Bicycle::find($rental->bicycleId);
            if ($bicycle && $bicycle->isInService()) {
                $bicycle->update(['lockStatus' => Bicycle::LOCK_LOCKED]);
                Rental::resolveConnection()->afterCommit(function () use ($bicycle, $rental, $user) {
                    $this->iotService->sendCommand($bicycle->id, 'lock', [
                        'reason' => 'ride_ended_pending_return',
                        'rentalId' => $rental->id,
                    ], $user);
                });
            }

            $this->notificationService->create(
                $user->id,
                'Return Requested',
                "Your return request for rental {$rental->rentalId} has been submitted and is awaiting inspection.",
                'rental_returned'
            );

            // The rider has requested a return, so administrators need to
            // inspect the bicycle and verify its physical arrival before
            // confirming the return. This is the same real notification type
            // used by requestReturn() below, which keeps the toast, badge and
            // Notifications page in sync for both return entry points.
            $this->notifyAdminsOfReturnInspection($rental, $user);

            // Broadcast immediately, exactly like the rider notification above:
            // the event is only a refetch nudge, so it can never corrupt state
            // even if the surrounding transaction were to roll back.
            RentalFeed::touch();

            return ['rental' => $rental->fresh()];
        });
    }

    /**
     * Rider-side return entry point used by the web app.
     *
     * Dispatches to the matching step of the return lifecycle: when the
     * bicycle is no longer in use (active / overdue / expired) this "ends the
     * ride" and parks the bicycle awaiting confirmation; once the rental is
     * already awaiting a return or overdue for return it simply records the
     * request so the administrator can confirm.
     */
    public function returnRental(
        Rental $rental,
        User $user,
        ?float $returnLat = null,
        ?float $returnLng = null,
        ?string $paymentMethod = null,
        ?string $paymentReference = null,
        ?string $notes = null
    ): array {
        if (in_array($rental->status, ['active', 'overdue', 'expired'], true)) {
            return $this->markRideEnded($rental, $user, $returnLat, $returnLng, null, $notes);
        }

        return $this->requestReturn($rental, $user);
    }

    /**
     * Record a rider's return request for a rental that is already waiting on
     * (or past) its return deadline. The bicycle stays held until the
     * administrator confirms the return via processReturn().
     *
     * Re-sending the request is idempotent: the first request is recorded and
     * notifies the administrators, repeated requests are no-ops.
     */
    public function requestReturn(Rental $rental, User $user, ?Carbon $returnTime = null, ?string $notes = null): array
    {
        if (!in_array($rental->status, [
            Rental::STATUS_ACTIVE,
            Rental::STATUS_OVERDUE,
            Rental::STATUS_EXPIRED,
            Rental::STATUS_AWAITING_RETURN,
            Rental::STATUS_OVERDUE_RETURN,
        ], true)) {
            throw new RentalException('This rental cannot request a return in its current status.');
        }

        $alreadyRequested = $rental->returnRequestedAt !== null;

        if ($alreadyRequested) {
            return ['rental' => $rental->fresh(), 'requested' => false];
        }

        // The server clock is the only source for the request instant. The
        // historical $returnTime argument is intentionally not trusted here:
        // Return Submitted At is an audit fact, not the physical arrival time.
        $rental->update([
            'returnRequestedAt' => Carbon::now(),
        ]);

        $this->notifyAdminsOfReturnInspection($rental, $user);

        RentalFeed::touch();

        return ['rental' => $rental->fresh(), 'requested' => true];
    }

    /**
     * Tell every administrator that a real bicycle return is waiting for
     * inspection. Both return entry points (ending an active ride and
     * re-requesting an already parked ride) funnel through here so the copy,
     * notification type and rental/bicycle references can never drift.
     */
    private function notifyAdminsOfReturnInspection(Rental $rental, User $rider): void
    {
        $adminIds = User::where('role', User::ROLE_ADMIN)->pluck('id')->all();

        if (empty($adminIds)) {
            return;
        }

        $bikeName = $rental->bicycleName ?: ($rental->bicycle?->name ?? 'bicycle');
        $serial = $rental->bicycleSerial ?: ($rental->bicycle?->serialNumber ?? '');
        $bikeRef = $serial !== '' ? "{$bikeName} (#{$serial})" : $bikeName;

        $this->notificationService->createForUsers(
            $adminIds,
            'Return Inspection Required',
            "{$rider->name} submitted a return for rental {$rental->rentalId} "
                . "(bicycle {$bikeRef}). Inspect the bicycle and confirm the return to settle it.",
            'return_requested',
            ['rentalId' => $rental->rentalId, 'bicycleId' => $rental->bicycleId]
        );
    }

    /**
     * Grant (or change) the return deadline for an expired / awaiting-return /
     * overdue-return rental. Deadline changes are idempotent.
     *
     * Sets the rental back to awaiting_return so the Returns module surfaces
     * it for confirmation, and notifies the renter of the new deadline.
     */
    public function setReturnDeadline(
        Rental $rental,
        User $admin,
        Carbon $deadline,
        ?string $message = null
    ): Rental {
        if (!in_array($rental->status, [
            Rental::STATUS_EXPIRED,
            Rental::STATUS_AWAITING_RETURN,
            Rental::STATUS_OVERDUE_RETURN,
        ], true)) {
            throw new RentalException('Only expired, awaiting-return, or overdue-return rentals can get a return deadline.');
        }

        $deadline = $deadline->copy()->floorMinute();

        $unchanged = $rental->returnDeadline !== null
            && $rental->returnDeadline->floorMinute()->eq($deadline);

        if ($unchanged) {
            return $rental->fresh();
        }

        $attributes = [
            'status' => Rental::STATUS_AWAITING_RETURN,
            'returnDeadline' => $deadline,
            'returnDeadlineSetBy' => (string) ($admin->name ?? $admin->id),
        ];

        if ($message !== null && trim($message) !== '') {
            $attributes['notes'] = trim(implode(PHP_EOL, array_filter([
                $rental->notes,
                'Return deadline message: ' . trim($message),
            ])));
        }

        $rental->update($attributes);

        $notificationMessage = "Your rental period for bicycle {$rental->bicycleName} (#{$rental->bicycleSerial}) has ended. "
            . "Please return the bicycle by {$deadline->format('M d, Y g:i A')}."
            . ' Late fees are PHP 20 for each completed 5-minute block after the return deadline.'
            . ($message ? " Message from Pedalya: {$message}" : '');

        $this->notificationService->create(
            $rental->riderId,
            'Return Deadline Set',
            $notificationMessage,
            'rental_deadline',
            ['rentalId' => $rental->rentalId, 'bicycleId' => $rental->bicycleId]
        );

        RentalFeed::touch();

        return $rental->fresh();
    }

    /**
     * Open the automatic return-grace window for a rental the bicycle's own
     * hardware just reported as parked and locked.
     *
     * The window length comes from the returnGraceSeconds system setting, which
     * mirrors the firmware's RETURN_GRACE_SECONDS (300 seconds, i.e. five
     * minutes, by default). No administrator is involved: the deadline is
     * computed from the authoritative server clock and the penalty rule is
     * deliberately left unset. The five-minute return period is anchored to
     * the scheduled rental end, not the time a delayed park report arrives.
     *
     * Idempotent by state: a deadline established at rental expiry is reused
     * when the bicycle is parked, so a later heartbeat cannot restart the
     * window. The manual setReturnDeadline() path still wins whenever an
     * administrator used it first.
     *
     * @return Rental|null the refreshed rental when a window was opened, or
     *                     null when there was nothing to do
     */
    public function startAutomaticReturnGrace(Rental $rental, ?Carbon $asOf = null): ?Rental
    {
        $asOf = $asOf ?? Carbon::now();

        if ($rental->returnDeadline !== null) {
            return null;
        }

        if (!in_array($rental->status, [
            Rental::STATUS_ACTIVE,
            Rental::STATUS_OVERDUE,
            Rental::STATUS_EXPIRED,
            Rental::STATUS_AWAITING_RETURN,
        ], true)) {
            return null;
        }

        $graceSeconds = max(0, (int) SystemSetting::getValue('returnGraceSeconds', 300));
        $graceStartedAt = $rental->returnGraceStartedAt?->copy()
            ?? $rental->expectedEndTime?->copy()
            ?? $asOf->copy();
        $deadline = $rental->returnGraceDeadline?->copy();
        if ($deadline === null) {
            if ($graceSeconds === 0) {
                return null;
            }
            $deadline = $graceStartedAt->copy()->addSeconds($graceSeconds);
        }

        $attributes = [
            'status' => Rental::STATUS_AWAITING_RETURN,
            'returnGraceStartedAt' => $graceStartedAt,
            'returnGraceDeadline' => $deadline,
            'returnDeadline' => $deadline,
            'returnDeadlineSetBy' => 'IoT park detection',
        ];

        // The lock re-engaging is a strong indication that the bicycle is
        // parked, but it is still not an administrator-verified arrival. It
        // opens the grace window and records the request/detection instant;
        // endTime remains reserved for the verified physical arrival recorded
        // during Process Return.
        $attributes['returnRequestedAt'] = $rental->returnRequestedAt ?? $asOf;

        $rental->update($attributes);

        $graceLabel = $this->describeReturnGrace($graceSeconds);
        $deadlineLabel = $deadline->format('M d, Y g:i A');

        $this->notificationService->create(
            $rental->riderId,
            'Return Grace Started',
            "Bicycle {$rental->bicycleName} (#{$rental->bicycleSerial}) was detected parked and locked. "
                . "Return rental {$rental->rentalId} by {$deadlineLabel} - a {$graceLabel} grace window. "
                . 'Late fees are PHP 20 per completed 5-minute block after the return deadline.',
            'rental_deadline',
            ['rentalId' => $rental->rentalId, 'bicycleId' => $rental->bicycleId]
        );

        $adminIds = User::where('role', User::ROLE_ADMIN)->pluck('id')->all();

        if (!empty($adminIds)) {
            $this->notificationService->createForUsers(
                $adminIds,
                'Return Deadline Auto-Set',
                "Bicycle {$rental->bicycleName} (#{$rental->bicycleSerial}) was detected parked, so rental "
                    . "{$rental->rentalId} automatically has until {$deadlineLabel} to be returned "
                    . "({$graceLabel} grace, no manual deadline needed). Late fees are PHP 20 per completed 5-minute block after the return deadline.",
                'rental_deadline',
                ['rentalId' => $rental->rentalId, 'bicycleId' => $rental->bicycleId]
            );
        }

        RentalFeed::touch();

        return $rental->fresh();
    }

    /**
     * Human-readable length of the automatic return-grace window, used in the
     * renter and administrator notifications.
     */
    private function describeReturnGrace(int $graceSeconds): string
    {
        if ($graceSeconds % 60 === 0) {
            $minutes = intdiv($graceSeconds, 60);

            return $minutes === 1 ? '1 minute' : "{$minutes} minutes";
        }

        return "{$graceSeconds} seconds";
    }

    /**
     * Resolve the fixed late-return penalty used for every rental. Legacy
     * per-rental values and the retired global setting are not charge inputs.
     *
     * @return array{type: string, value: float, isDefaultRate: bool}
     */
    public function resolvedPenaltyRule(?Rental $rental): array
    {
        return [
            'type' => Rental::PENALTY_PER_5_MINUTES,
            'value' => self::LATE_PENALTY_PER_COMPLETED_FIVE_MINUTES,
            'isDefaultRate' => true,
        ];
    }

    /**
     * Penalty accrued as of server time, beginning at the authoritative return
     * deadline. Legacy rentals derive that deadline from the scheduled end
     * plus the configured return-grace period.
     *
     * Nothing is ever charged while the deadline is still in the future.
     */
    public function calculatePenaltyForRental(Rental $rental, Carbon $asOf): float
    {
        $deadline = $this->penaltyDeadlineForRental($rental);

        if ($deadline === null || $asOf->lte($deadline)) {
            return 0.0;
        }

        $diffSeconds = max(0, $asOf->getTimestamp() - $deadline->getTimestamp());

        // This is deliberately timestamp based (rather than calendar-minute
        // arithmetic), so it is correct across midnight. Partial blocks never
        // round up: 4:59 late costs 0; 5:00 costs exactly PHP 20.
        return round(
            intdiv($diffSeconds, 300) * self::LATE_PENALTY_PER_COMPLETED_FIVE_MINUTES,
            2
        );
    }

    private function penaltyDeadlineForRental(Rental $rental): ?Carbon
    {
        if ($rental->returnDeadline !== null) {
            return $rental->returnDeadline->copy();
        }

        if ($rental->returnGraceDeadline !== null) {
            return $rental->returnGraceDeadline->copy();
        }

        $rentalEnd = $rental->expectedEndTime
            ?? ($rental->startTime?->copy()->addMinutes($rental->durationMinutes ?? 60));

        if ($rentalEnd === null) {
            return null;
        }

        $graceSeconds = max(0, (int) SystemSetting::getValue('returnGraceSeconds', 300));

        return $rentalEnd->copy()->addSeconds($graceSeconds);
    }

    /**
     * Live countdown and penalty snapshot for a rental, as published to the
     * bicycle's on-board display.
     *
     * The countdown targets the administrator-set return deadline once one
     * exists, and the expected end time before that, so the rider always sees
     * the deadline that is actually in force. Legacy rows without either
     * value fall back to their end time or start time plus stored duration.
     *
     * The projected penalty uses the same fixed policy processReturn() settles:
     * PHP 20 per completed five-minute block after the return deadline.
     */
    public function rentalClockFor(Rental $rental, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ? $asOf->copy() : Carbon::now();

        // Compared as unix seconds rather than via Carbon's diff helpers so the
        // sign is unambiguous regardless of the installed Carbon version.
        $startTimestamp = $rental->startTime?->getTimestamp() ?? $asOf->getTimestamp();
        $asOfTimestamp = $asOf->getTimestamp();
        $deadline = $rental->returnDeadline
            ?? $rental->expectedEndTime
            ?? $rental->endTime
            ?? ($rental->startTime?->copy()->addMinutes($rental->durationMinutes ?? 60));
        $deadlineTimestamp = $deadline?->getTimestamp();

        $elapsedSeconds = max(0, $asOfTimestamp - $startTimestamp);
        $remainingSeconds = $deadlineTimestamp === null
            ? null
            : $deadlineTimestamp - $asOfTimestamp;

        $fees = $this->calculateFees(
            ($rental->startTime ?? $asOf)->toDateTimeString(),
            $asOf->toDateTimeString(),
            (float) $rental->ratePerHour
        );

        if ($rental->returnDeadline !== null) {
            $penaltyBasis = 'return_deadline';
        } elseif ($rental->returnGraceDeadline !== null) {
            $penaltyBasis = 'return_grace_deadline';
        } elseif ($rental->expectedEndTime !== null) {
            $penaltyBasis = 'expected_end_plus_return_grace';
        } elseif ($rental->startTime !== null) {
            $penaltyBasis = 'duration_plus_return_grace';
        } else {
            $penaltyBasis = 'none';
        }

        $projectedPenalty = $this->latePenaltyForRental($rental, $asOf);

        $window = $deadlineTimestamp === null ? 0 : $deadlineTimestamp - $startTimestamp;

        // Mirror settleRental(): the Base Fee is the rental's positive stored
        // Estimated Cost, so the figure on the bicycle display is the base fee
        // that will land on the final charge. Only legacy rows with no positive
        // estimate fall back to elapsed time.
        $projectedBase = $this->baseFeeForRental($rental, $fees);

        return [
            'startTime' => $rental->startTime,
            'effectiveDeadline' => $deadline,
            'deadlineBasis' => $deadlineTimestamp === null
                ? 'none'
                : ($rental->returnDeadline !== null
                    ? 'return_deadline'
                    : ($rental->expectedEndTime !== null
                        ? 'expected_end_time'
                        : ($rental->endTime !== null ? 'legacy_end_time' : 'duration_fallback'))),
            'elapsedSeconds' => $elapsedSeconds,
            'remainingSeconds' => $remainingSeconds,
            'overdueSeconds' => $remainingSeconds === null ? 0 : max(0, -$remainingSeconds),
            'isOverdue' => $remainingSeconds !== null && $remainingSeconds < 0,
            'progressPercent' => $window > 0
                ? (int) min(100, max(0, (int) floor($elapsedSeconds / $window * 100)))
                : null,
            'ratePerHour' => (float) $rental->ratePerHour,
            'projectedBaseFee' => $projectedBase,
            'penaltyBasis' => $penaltyBasis,
            'projectedPenalty' => round($projectedPenalty, 2),
            'projectedTotal' => round($projectedBase + $projectedPenalty, 2),
            'rule' => $this->resolvedPenaltyRule($rental),
        ];
    }

    /**
     * Deadline shown for the rider-facing return countdown.
     *
     * Once the rental expires, the LCD's grace deadline is persisted on the
     * rental and shared with the dashboard. Legacy rows derive the same value
     * from their stored rental end and grace setting.
     */
    public function riderCountdownDeadline(Rental $rental): ?Carbon
    {
        return $this->countdownDeadlineFor(
            $rental,
            in_array($rental->status, [
                Rental::STATUS_EXPIRED,
                Rental::STATUS_OVERDUE,
            ], true)
        );
    }

    /**
     * The device may observe the scheduled end before the lifecycle scheduler
     * has changed the rental status. It still needs the same grace deadline
     * the server will return once the status transition is persisted.
     */
    public function deviceCountdownDeadline(Rental $rental, Carbon $asOf): ?Carbon
    {
        $baseDeadline = $rental->returnDeadline
            ?? $rental->returnGraceDeadline
            ?? $rental->expectedEndTime
            ?? $rental->endTime
            ?? ($rental->startTime?->copy()->addMinutes($rental->durationMinutes ?? 60));

        $expired = in_array($rental->status, [
            Rental::STATUS_EXPIRED,
            Rental::STATUS_OVERDUE,
        ], true) || ($baseDeadline !== null && $asOf->gte($baseDeadline));

        return $this->countdownDeadlineFor($rental, $expired);
    }

    private function countdownDeadlineFor(Rental $rental, bool $expired): ?Carbon
    {
        if ($rental->returnDeadline !== null) {
            return $rental->returnDeadline->copy();
        }
        if ($rental->returnGraceDeadline !== null) {
            return $rental->returnGraceDeadline->copy();
        }

        $deadline = $rental->expectedEndTime
            ?? $rental->endTime
            ?? ($rental->startTime?->copy()->addMinutes($rental->durationMinutes ?? 60));

        if ($deadline === null) {
            return null;
        }

        if ($expired) {
            $graceSeconds = max(0, (int) SystemSetting::getValue('returnGraceSeconds', 300));

            return $deadline->copy()->addSeconds($graceSeconds);
        }

        return $deadline->copy();
    }

    /**
     * Phase 2 of the return lifecycle: confirm the return (Process Return).
     *
     * The administrator records the actual return time and the bicycle's
     * condition. A final fee (base + any overdue surcharge) is computed, the
     * rental is marked "returned", and the bicycle is released to Available
     * or routed to Maintenance depending on the inspected condition.
     *
     * Confirming is only allowed once: a rental that is already returned
     * (or not awaiting a return) is rejected, so the same rental can never be
     * returned twice.
     */
    public function processReturn(
        Rental $rental,
        User $admin,
        array $input = []
    ): array {
        if (!in_array($rental->status, [
            Rental::STATUS_AWAITING_RETURN,
            Rental::STATUS_OVERDUE_RETURN,
        ], true)) {
            if ($rental->status === Rental::STATUS_RETURNED) {
                throw new RentalException('This rental has already been returned.');
            }
            throw new RentalException('Only rentals awaiting return can be processed.');
        }

        return $this->settleRental($rental, $admin, $input);
    }

    /**
     * One-step "End Ride": settle an in-progress rental on the spot.
     *
     * This is the single-click equivalent of end ride + process return, used
     * by an administrator who has the bicycle in hand and can inspect it right
     * away. It reuses the exact settlement core as processReturn(), so the fee
     * maths, the penalty rule, the bicycle release and the payment record are
     * identical no matter which entry point settled the rental.
     *
     * Consequences of settling here, all in one transaction:
     *  - the rental leaves Active Rentals and lands in Rental History
     *    (status "returned"), and the renter is notified of the final fee;
     *  - the bicycle is released to Available (or routed to Maintenance when
     *    the inspected condition demands it) with its rental pointers cleared;
     *  - the applicable penalty is frozen onto finalFee/overdueFee so it
     *    stops accruing;
     *  - the payment record is synced and the rider's spend is updated.
     *
     * Rejecting anything already settled is what keeps a double submission
     * (or a second click) from ever double-charging a rental.
     */
    public function endRideAndSettle(
        Rental $rental,
        User $admin,
        array $input = []
    ): array {
        if (!in_array($rental->status, [
            Rental::STATUS_ACTIVE,
            Rental::STATUS_OVERDUE,
            Rental::STATUS_EXPIRED,
        ], true)) {
            if ($rental->status === Rental::STATUS_RETURNED) {
                throw new RentalException('This ride has already been ended and settled.');
            }
            throw new RentalException('Only an active, overdue, or expired ride can be ended.');
        }

        return $this->settleRental($rental, $admin, $input, $admin);
    }

    /**
     * Shared settlement core behind processReturn() and endRideAndSettle().
     *
     * The caller decides which statuses may reach this point; everything after
     * that - return time, inspected condition, penalty basis, fee breakdown,
     * bicycle release, payment sync and rider notification - is identical.
     *
     * $lockActor, when given, also queues a physical lock command for the
     * bicycle once the transaction commits. The one-step end-ride path needs it
     * because it never passes through markRideEnded(), which is what normally
     * secures the lock; the two-step path already locked it in phase 1.
     */
    private function settleRental(
        Rental $rental,
        User $admin,
        array $input = [],
        ?User $lockActor = null
    ): array {
        // Server is the source of truth: the return timestamp is parsed in the
        // app timezone (Asia/Manila, same zone the stored deadline was written
        // in and the datetime-local picker edits in), and an omitted value
        // means "now". Both the form's snake_case field and the service's
        // camelCase key are accepted. No fee total is ever accepted from the
        // caller.
        $submittedReturnTime = ($input['returnTime'] ?? null)
            ?: ($input['return_time'] ?? null)
            ?: ($input['actualReturnTime'] ?? null)
            ?: ($input['actual_return_time'] ?? null);
        $returnTime = $submittedReturnTime !== null && $submittedReturnTime !== ''
            ? Carbon::parse($submittedReturnTime)->setTimezone(config('app.timezone'))
            : Carbon::now();

        $condition = in_array($input['condition'] ?? null, [
            Rental::CONDITION_GOOD,
            Rental::CONDITION_FAIR,
            Rental::CONDITION_DAMAGED,
            Rental::CONDITION_NEEDS_MAINTENANCE,
        ], true) ? $input['condition'] : Rental::CONDITION_GOOD;

        // The Base Fee is ALWAYS the rental's positive stored Estimated Cost
        // (totalFee written at booking time, already including any extension
        // fees). It is never recomputed from elapsed time, so a one-minute
        // rental keeps its ₱15.00 base even when the actual return lands hours
        // later. A legacy zero/null estimate keeps the elapsed fallback.
        //
        // A late-return penalty is calculated separately against the STORED
        // return deadline. The default rule charges PHP 20 for every COMPLETED
        // 5-minute interval past it (1-4:59 min = 0; 5-9:59 = one interval;
        // Final = Base + Late). Rentals that reached awaiting_return without a
        // deadline keep the legacy expected-end-time surcharge for backward
        // compatibility, but their Base Fee still stays the saved estimate.
        //
        // Everything is derived here from the submitted return timestamp, never
        // from the frontend preview. The stored deadline is never mutated, and
        // the frozen overdueFee/finalFee stop further accrual. Confirming twice
        // is rejected by the status guards plus the in-transaction re-check, so
        // no duplicate charge is possible.
        return DB::transaction(function () use (
            $rental,
            $admin,
            $returnTime,
            $condition,
            $input,
            $lockActor
        ) {
            // Re-check under a row lock: two admins confirming the same
            // pending return concurrently must not double-charge it. The
            // first commit flips status to returned; the second then sees a
            // settled row and is rejected instead of settling again.
            /** @var Rental|null $locked */
            $locked = Rental::query()->lockForUpdate()->find($rental->id);

            if ($locked === null) {
                throw new RentalException('Rental no longer exists.');
            }

            if ($locked->status === Rental::STATUS_RETURNED) {
                throw new RentalException('This rental has already been returned.');
            }

            if (! in_array($locked->status, [
                Rental::STATUS_AWAITING_RETURN,
                Rental::STATUS_OVERDUE_RETURN,
                Rental::STATUS_ACTIVE,
                Rental::STATUS_OVERDUE,
                Rental::STATUS_EXPIRED,
            ], true)) {
                throw new RentalException('This rental can no longer be settled in its current status.');
            }

            // The admin-verified arrival is the source of truth. It must never
            // be replaced by the rider's submission timestamp or by the moment
            // the return was processed, but it also cannot precede the rental
            // start or be in the future.
            if ($locked->startTime !== null && $returnTime->lt($locked->startTime)) {
                throw new RentalException('The actual return time cannot be before the rental start time.');
            }

            if ($returnTime->gt(Carbon::now()->copy()->addMinute())) {
                throw new RentalException('The actual return time cannot be in the future.');
            }

            // Re-derive every amount from the FRESH locked row so a concurrent
            // extension or deadline change landing between the outer read and
            // this lock cannot be silently ignored. This is the single
            // canonical settlement calculation; the modal only previews it.
            $settlement = $this->settlementFeesFor($locked, $returnTime);
            $fees = $settlement['fees'];
            $freshBase = $settlement['baseFee'];
            $freshPenalty = $settlement['latePenalty'];
            $freshFinal = $settlement['finalFee'];

            // Sync the existing payment before freezing the rental row. The
            // payment keeps the method and the amount already collected; only
            // the final total and any outstanding balance are updated.
            $payment = $this->syncPayment($locked, $freshBase, $freshPenalty, $freshFinal);

            $locked->update([
                'endTime' => $returnTime,
                'status' => Rental::STATUS_RETURNED,
                // Never overwrite the moment the rider actually asked to
                // return; only stamp it when nobody got there first.
                'returnRequestedAt' => $locked->returnRequestedAt ?? $returnTime,
                // totalFee is the original saved Estimated Cost (including any
                // bought extension), never the final charge. Keeping it intact
                // makes Base Fee immutable across settlement and reporting.
                'finalFee' => $freshFinal,
                'overdueFee' => $freshPenalty,
                'penaltyAccrued' => $freshPenalty,
                'durationMinutes' => $fees['durationMinutes'],
                'durationFormatted' => $fees['durationFormatted'],
                'chargedHours' => $fees['chargedHours'],
                // "paid" means the whole final charge is collected. A GCash
                // base payment with a late fee still due is "partial" so the
                // balance cannot be mistaken for money already received.
                'paymentStatus' => $payment['rentalPaymentStatus'],
                'paidAt' => $payment['rentalPaidAt'],
                'returnCondition' => $condition,
                'returnInspectedBy' => (string) ($admin->name ?? $admin->id),
                'returnProcessedAt' => Carbon::now(),
                'returnNote' => $input['note'] ?? null,
            ]);

            $this->settleBicycleAfterReturn($locked, $condition, $admin);

            $user = User::find($locked->riderId);
            if ($user && $payment['amountPaid'] > 0) {
                // Count only money actually collected. A late GCash balance
                // that is still due must not inflate the rider's total spend.
                $user->increment('totalSpent', $payment['amountPaid']);
            }

            if ($lockActor !== null && $locked->bicycleId) {
                $bicycle = Bicycle::find($locked->bicycleId);
                if ($bicycle && $bicycle->isInService()) {
                    Rental::resolveConnection()->afterCommit(function () use ($bicycle, $locked, $lockActor) {
                        $this->iotService->sendCommand($bicycle->id, 'lock', [
                            'reason' => 'rental_settled',
                            'rentalId' => $locked->id,
                        ], $lockActor);
                    });
                }
            }

            $penaltyNotice = $freshPenalty > 0
                ? " A late-return penalty of PHP {$freshPenalty} applies."
                : '';
            $balanceNotice = $payment['balanceDue'] > 0
                ? " Balance due: PHP {$payment['balanceDue']}."
                : '';

            $this->notificationService->create(
                $locked->riderId,
                'Return Confirmed',
                "Your rental {$locked->rentalId} has been confirmed. Final fee: PHP {$freshFinal}.{$penaltyNotice}{$balanceNotice}",
                'rental_completed'
            );

            RentalFeed::touch();

            return [
                'rental' => $locked->fresh(),
                'payment' => [
                    'id' => $payment['payment']?->id,
                    'method' => $payment['method'],
                    'amountPaid' => $payment['amountPaid'],
                    'balanceDue' => $payment['balanceDue'],
                    'status' => $payment['payment']?->status,
                ],
                'fees' => [
                    'baseFee' => $freshBase,
                    'overdueFee' => $freshPenalty,
                    'latePenalty' => $freshPenalty,
                    'finalFee' => $freshFinal,
                    'durationMinutes' => $fees['durationMinutes'],
                    'durationFormatted' => $fees['durationFormatted'],
                    'chargedHours' => $fees['chargedHours'],
                    'condition' => $condition,
                    'returnTime' => $returnTime->toDateTimeString(),
                ],
            ];
        });
    }

    /**
     * The single canonical fee calculation used when a rental is settled.
     *
     * Base Fee is the saved Estimated Cost. Late Penalty is derived from the
     * stored deadline (or the legacy expected-end surcharge when no deadline
     * exists). Final Fee is always Base + Late.
     *
     * @return array{
     *     fees: array<string, mixed>,
     *     baseFee: float,
     *     latePenalty: float,
     *     finalFee: float
     * }
     */
    private function settlementFeesFor(Rental $rental, Carbon $returnTime): array
    {
        $fees = $this->calculateFees(
            ($rental->startTime ?? $returnTime)->toDateTimeString(),
            $returnTime->toDateTimeString(),
            (float) $rental->ratePerHour
        );

        $baseFee = $this->baseFeeForRental($rental, $fees);
        $latePenalty = $this->latePenaltyForRental($rental, $returnTime);

        return [
            'fees' => $fees,
            'baseFee' => $baseFee,
            'latePenalty' => $latePenalty,
            'finalFee' => round($baseFee + $latePenalty, 2),
        ];
    }

    /**
     * Base Fee = the Estimated Cost saved on the rental.
     *
     * Settlement must never reprice the base from elapsed time; only a legacy
     * row with no positive stored estimate falls back to the elapsed-time
     * figure so old records remain settleable.
     */
    private function baseFeeForRental(Rental $rental, array $fees): float
    {
        if ($rental->totalFee !== null && (float) $rental->totalFee > 0) {
            return round((float) $rental->totalFee, 2);
        }

        $base = (float) ($fees['totalFee'] ?? 0);

        // An extension always writes the larger amount back to totalFee, but
        // keep the floor for malformed legacy rows that only persisted one
        // side of that pair.
        if ($rental->hasRideExtensions()) {
            $base = max($base, (float) ($rental->totalFee ?? 0));
        }

        return round($base, 2);
    }

    /**
     * Late Penalty for settlement is always based on the original rental
     * deadline and the verified physical arrival time.
     */
    private function latePenaltyForRental(Rental $rental, Carbon $returnTime): float
    {
        return $this->calculatePenaltyForRental($rental, $returnTime);
    }

    /**
     * Convenience form of the same fixed completed-five-minute rule.
     */
    public function calculateLateReturnPenalty(?Carbon $returnDeadline, Carbon $returnTime): float
    {
        if ($returnDeadline === null) {
            return 0.0;
        }

        if ($returnTime->lte($returnDeadline)) {
            return 0.0;
        }

        $lateSeconds = max(0, $returnTime->getTimestamp() - $returnDeadline->getTimestamp());

        return round(intdiv($lateSeconds, 300) * self::LATE_PENALTY_PER_COMPLETED_FIVE_MINUTES, 2);
    }

    /**
     * Calculate an overdue surcharge: any full extra hour (or partial, rounded
     * up) beyond the expected end time charged at the hourly rate.
     */
    private function calculateOverdueFee(?Carbon $expectedEndTime, Carbon $returnTime, float $ratePerHour): float
    {
        if ($expectedEndTime === null || $returnTime->lte($expectedEndTime)) {
            return 0.0;
        }

        $overdueMinutes = abs((int) $returnTime->diffInMinutes($expectedEndTime));
        $overdueHours = max(ceil($overdueMinutes / 60), 1);

        return round($overdueHours * max($ratePerHour, 0), 2);
    }

    /**
     * Route the bicycle to Available or Maintenance based on the inspected
     * condition, clear the rental references, and re-secure the smart lock.
     *
     * A damaged or needs-maintenance inspection opens a maintenance record as
     * part of the same transaction, so the Maintenance module always has
     * something to act on for a bicycle that has just been taken out of service.
     *
     * A bicycle that is not in service is treated very differently. It never had
     * a rider and never will, so there is no hardware to unlock, no lock state
     * to restore, and no business being made Available: the rental pointers are
     * cleared and the condition recorded, but the parked status and the
     * recorded lockStatus are both left untouched. Settling a rental must not be
     * able to drag an out-of-service unit back into the rentable fleet.
     */
    private function settleBicycleAfterReturn(Rental $rental, string $condition, User $admin): void
    {
        $bicycle = Bicycle::find($rental->bicycleId);
        if (!$bicycle) {
            return;
        }

        $routeToMaintenance = in_array($condition, [
            Rental::CONDITION_DAMAGED,
            Rental::CONDITION_NEEDS_MAINTENANCE,
        ], true);

        $updates = [
            'currentRider' => null,
            'currentRentalId' => null,
            'condition' => $condition,
        ];

        if ($bicycle->isInService()) {
            $updates['status'] = $routeToMaintenance
                ? Bicycle::STATUS_MAINTENANCE
                : Bicycle::STATUS_AVAILABLE;
            $updates['lockStatus'] = Bicycle::LOCK_LOCKED;
        } else {
            // Out of service: a damaged return is still a real finding, so the
            // bicycle goes to maintenance; otherwise it stays parked exactly as
            // it was, and its lockStatus is never written.
            $updates['status'] = $routeToMaintenance
                ? Bicycle::STATUS_MAINTENANCE
                : $bicycle->status;
        }

        $bicycle->update($updates);

        if ($routeToMaintenance && $bicycle->wasChanged('status')) {
            // Open the actual work order rather than only moving the status. A
            // bicycle sitting in maintenance with no record is a dead end: the
            // Maintenance module shows nothing to work on, and releasing it
            // becomes possible with nothing ever having been tracked.
            $this->maintenanceService->placeBicycleInMaintenance(
                $bicycle,
                "Found during the return inspection of rental {$rental->rentalId}: {$condition}. "
                    . ($rental->returnNote ?: 'No further note recorded.'),
                $admin->id,
            );

            $this->notificationService->create(
                $admin->id,
                'Bicycle Needs Maintenance',
                "Bicycle {$bicycle->name} was routed to maintenance from rental {$rental->rentalId}.",
                'maintenance'
            );
        }
    }

    /**
     * Keep the Payments module in sync with a settled rental.
     *
     * The final fee is the rental's Base Fee plus any late penalty. That is
     * not necessarily the amount already collected:
     *
     *  - GCash is collected when the booking is verified, so a later late fee
     *    is still due. Its method and original paid amount are preserved.
     *  - Cash is handled at the counter. The existing workflow marks the
     *    rental paid, so the final fee (including any late fee handed over at
     *    return) is recorded as collected.
     *  - An unknown method is never silently turned into Cash and never
     *    creates a second transaction.
     *
     * Exactly one Payment row is updated/created per rental.
     *
     * @return array{
     *     payment: Payment|null,
     *     method: string|null,
     *     amountPaid: float,
     *     balanceDue: float,
     *     rentalPaymentStatus: string,
     *     rentalPaidAt: Carbon|null
     * }
     */
    private function syncPayment(
        Rental $rental,
        float $baseFee,
        float $latePenalty,
        float $finalFee
    ): array {
        $payment = Payment::where('rentalId', $rental->id)
            ->lockForUpdate()
            ->first();

        // Preserve the method already recorded on the payment; only fall back
        // to the rental's own method when no payment record exists yet.
        $method = $payment?->paymentMethod ?: $rental->paymentMethod;

        $alreadyPaid = 0.0;

        if ($payment !== null) {
            $alreadyPaid = (float) ($payment->amountPaid ?? 0);

            // Legacy paid rows did not have amountPaid. Their totalAmount is
            // the amount that was collected before this settlement.
            if ($alreadyPaid <= 0 && $payment->status === 'paid') {
                $alreadyPaid = (float) ($payment->totalAmount ?? $payment->amount ?? 0);
            }
        } elseif ($rental->paymentStatus === 'paid' && $method !== null && $method !== 'cash') {
            // The rental was marked paid earlier but its payment row is
            // missing. Preserve that collected base instead of charging the
            // rider again for it.
            $alreadyPaid = (float) ($rental->totalFee ?? $baseFee);
        }

        $convenienceFee = round((float) ($payment?->convenienceFee ?? 0), 2);
        $finalPaymentTotal = round($finalFee + $convenienceFee, 2);

        if ($method === 'cash') {
            // Existing workflow treats cash as settled when the return is
            // confirmed; any late fee and valid payment charge are handed
            // over at the counter.
            $amountPaid = $finalPaymentTotal;
        } else {
            $amountPaid = min(max($alreadyPaid, 0), $finalPaymentTotal);
        }

        $amountPaid = round($amountPaid, 2);
        $balanceDue = round(max($finalPaymentTotal - $amountPaid, 0), 2);

        $status = $payment?->status;
        if ($balanceDue <= 0) {
            $status = 'paid';
        } elseif ($status === null) {
            // A missing payment row can still have a paid base recorded on the
            // rental. Preserve that fact instead of presenting the whole
            // record as unpaid.
            $status = $alreadyPaid > 0 ? 'paid' : 'pending';
        }

        $rentalPaymentStatus = $balanceDue <= 0
            ? 'paid'
            : ($alreadyPaid > 0 ? 'partial' : 'pending');

        $paidAt = $payment?->paidAt;
        if ($paidAt === null && $amountPaid > 0) {
            $paidAt = Carbon::now();
        }

        $details = is_array($payment?->paymentDetails) ? $payment->paymentDetails : [];
        $details['rental_settlement'] = [
            'base_fee' => $baseFee,
            'late_penalty' => $latePenalty,
            'final_fee' => $finalFee,
            'payment_total' => $finalPaymentTotal,
            'amount_paid' => $amountPaid,
            'balance_due' => $balanceDue,
            'payment_method' => $method,
            'settled_at' => Carbon::now()->toIso8601String(),
        ];

        if ($method === null) {
            // No recorded payment method: do not invent Cash and do not create
            // a payment transaction that claims money was collected.
            return [
                'payment' => null,
                'method' => null,
                'amountPaid' => 0.0,
                'balanceDue' => $finalFee,
                'rentalPaymentStatus' => 'pending',
                'rentalPaidAt' => null,
            ];
        }

        $attributes = [
            'paymentMethod' => $method,
            'amount' => $baseFee,
            'convenienceFee' => $convenienceFee,
            'totalAmount' => $finalPaymentTotal,
            'latePenalty' => $latePenalty,
            'amountPaid' => $amountPaid,
            'balanceDue' => $balanceDue,
            'currency' => $payment?->currency ?? 'PHP',
            'status' => $status,
            'paymentDetails' => $details,
            'paidAt' => $paidAt,
        ];

        if ($payment !== null) {
            $payment->update($attributes);
        } else {
            $payment = Payment::create(array_merge($attributes, [
                'rentalId' => $rental->id,
                'userId' => $rental->riderId,
                'bicycleId' => $rental->bicycleId,
                'paymentReference' => $rental->paymentReference ?? $rental->rentalId,
            ]));
        }

        return [
            'payment' => $payment,
            'method' => $method,
            'amountPaid' => $amountPaid,
            'balanceDue' => $balanceDue,
            'rentalPaymentStatus' => $rentalPaymentStatus,
            'rentalPaidAt' => $rentalPaymentStatus === 'pending' ? null : $paidAt,
        ];
    }

    /**
     * Automated status update rule.
     *
 * When a rental's status is "Completed" and its payment status is
* "Paid", the corresponding bicycle is released and its status
* becomes "Available". A physical lock command is queued for the
* ESP32 after the database transaction commits. The lockStatus is
* updated only after the device acknowledges the lock command.
     *
     * The rule is idempotent: an already-settled bicycle is left
     * untouched and no duplicate lock command is queued.
     */
    public function settleBicycleForRental(Rental $rental, ?User $actor = null, array $extraAttributes = []): bool
    {
        $bicycle = $this->settleableBicycle($rental);

        if ($bicycle === null) {
            return false;
        }

        // Only the in-service unit is released to Available. For a bicycle that
        // is not in service the rental pointers are still cleared, but its
        // status is left alone so a settlement can never promote a parked
        // bicycle into the rentable fleet.
        $attributes = array_merge(
            $bicycle->isInService()
                ? ['status' => Bicycle::STATUS_AVAILABLE]
                : ['status' => $bicycle->status],
            [
                'currentRider' => null,
                'currentRentalId' => null,
            ],
            $extraAttributes
        );

        $bicycle->fill($attributes);

        // Already settled — nothing to do.
        if (!$bicycle->isDirty()) {
            return false;
        }

        $bicycle->save();

        // Lock action control: queue the physical lock command so the
        // ESP32 secures the bicycle (executes after commit).
        if ($bicycle->isInService()) {
            Rental::resolveConnection()->afterCommit(function () use ($bicycle, $rental, $actor) {
                $this->iotService->sendCommand($bicycle->id, 'lock', [
                    'reason' => 'rental_settled',
                    'rentalId' => $rental->id,
                ], $actor);
            });
        }

        return true;
    }

    /**
     * Returns the bicycle that may be settled for this rental, or null when any
     * settlement precondition fails (status, payment, or ownership).
     */
    private function settleableBicycle(Rental $rental): ?Bicycle
    {
        if (!in_array($rental->status, [Rental::STATUS_COMPLETED, Rental::STATUS_RETURNED], true)
            || strtolower((string) $rental->paymentStatus) !== 'paid'
            || !$rental->bicycleId) {
            return null;
        }

        $bicycle = Bicycle::find($rental->bicycleId);

        // Never hijack a bicycle that another rental now holds (currentRentalId
        // historically stores either the rental PK or the REN- reference string,
        // so both are recognised as self), and never auto-release one that has
        // already been routed to maintenance (e.g. by a damaged return).
        if ($bicycle === null
            || $bicycle->status === Bicycle::STATUS_MAINTENANCE
            || ($bicycle->currentRentalId !== null
                && !in_array((string) $bicycle->currentRentalId, [(string) $rental->id, (string) $rental->rentalId], true))) {
            return null;
        }

        return $bicycle;
    }

    public function approveRental(Rental $rental, User $admin): Rental
    {
        if ($rental->status !== 'pending') {
            throw new RentalException('Rental is not pending approval.');
        }

        $rental->update([
            'status' => 'active',
            'approvedBy' => $admin->id,
            'approvedAt' => Carbon::now(),
        ]);

        $this->notificationService->create(
            $rental->riderId,
            'Rental Approved',
            "Your rental {$rental->rentalId} has been approved.",
            'rental_approved'
        );

        RentalFeed::touch();

        return $rental->fresh();
    }

    /**
     * Put a bicycle back into the fleet when its rental is cancelled.
     *
     * Single source of truth for "the rental is gone, free the bicycle": the
     * rider-facing, admin and API cancel paths all call this so they cannot
     * drift apart. Clears the denormalised currentRider/currentRentalId
     * pointers the inventory reads, and locks the smart lock because nobody is
     * authorised to use the bicycle any more.
     *
     * A bicycle that is under maintenance stays in maintenance: a cancellation
     * must not silently return a bike that is out of service to the rentable
     * fleet. Neither does a bicycle that is parked out of service: it has no
     * hardware to lock and its recorded lockStatus is left untouched.
     */
    public function releaseBicycleOnCancel(Rental $rental, User $actor): ?Bicycle
    {
        $bicycle = Bicycle::find($rental->bicycleId);

        if (! $bicycle) {
            return null;
        }

        // Only release the pointers that still point at this rental, so
        // cancelling a superseded rental cannot evict a newer renter.
        $stillHoldsThisRental = $bicycle->currentRentalId === null
            || in_array(
                (string) $bicycle->currentRentalId,
                [(string) $rental->id, (string) $rental->rentalId],
                true
            );

        if (! $stillHoldsThisRental) {
            return $bicycle;
        }

        $inService = $bicycle->isInService();

        $updates = [
            'status' => $bicycle->status === Bicycle::STATUS_MAINTENANCE
                ? Bicycle::STATUS_MAINTENANCE
                : ($inService ? Bicycle::STATUS_AVAILABLE : $bicycle->status),
            'currentRider' => null,
            'currentRentalId' => null,
        ];

        if ($inService) {
            $updates['lockStatus'] = Bicycle::LOCK_LOCKED;
        }

        $bicycle->update($updates);

        // Nobody is authorised to use the bicycle any more, so the in-service
        // unit is physically secured. There is no command to send otherwise.
        if ($inService) {
            $this->iotService->sendCommand($bicycle->id, 'lock', [], $actor);
        }

        return $bicycle;
    }

    public function cancelRental(Rental $rental, User $user, ?string $reason = null): Rental
    {
        if (!in_array($rental->status, ['active', 'pending'])) {
            throw new RentalException('Rental cannot be cancelled in its current status.');
        }

        $rental->update([
            'status' => 'cancelled',
            'cancelReason' => $reason,
            'cancelledBy' => $user->id,
        ]);

        $this->releaseBicycleOnCancel($rental, $user);

        $this->notificationService->create(
            $rental->riderId,
            'Rental Cancelled',
            "Your rental {$rental->rentalId} has been cancelled." . ($reason ? " Reason: {$reason}" : ''),
            'rental_cancelled'
        );

        RentalFeed::touch();

        return $rental->fresh();
    }

    /**
     * Extend an ongoing (or just-expired) ride by buying extra time.
     *
     * The extension is priced with the same proportional ₱1.50-per-minute
     * rule as the base fee and lands on the
     * rental's running total, so the fee the rider is shown is the fee that
     * settles at return. The expected end time (and any return deadline that
     * is already in force) is pushed forward by the same minutes, the rental
     * leaves the "expired"/"overdue" state, and the whole event is logged on
     * the rental so receipts can show staff that the ride was extended
     * rather than reading the row as an overdue rental.
     */
    public function extendRental(Rental $rental, int $extraMinutes, ?User $actor = null): Rental
    {
        $extraMinutes = max(1, $extraMinutes);

        $chargedHours = max((int) ceil($extraMinutes / 60), 1);
        $fee = round($extraMinutes * self::RENTAL_RATE_PER_MINUTE, 2);

        $maxHours = max(1, (int) SystemSetting::getValue('rentalMaxDurationHours', 12));
        $maxMinutes = $maxHours * 60;

        $rental = DB::transaction(function () use (
            $rental,
            $extraMinutes,
            $fee,
            $chargedHours,
            $maxHours,
            $maxMinutes,
            $actor
        ) {
            /** @var Rental $locked */
            $locked = Rental::query()->lockForUpdate()->find($rental->id);

            if ($locked === null) {
                throw new RentalException('Rental no longer exists.');
            }

            if (! in_array($locked->status, [
                Rental::STATUS_ACTIVE,
                Rental::STATUS_EXPIRED,
                Rental::STATUS_OVERDUE,
            ], true)) {
                throw new RentalException('Only an ongoing or expired ride can be extended.');
            }

            $startTime = $locked->startTime ?? Carbon::now();
            $currentEnd = $locked->expectedEndTime
                ?? $locked->endTime
                ?? $startTime->copy()->addMinutes((int) $locked->durationMinutes);

            $newEnd = $currentEnd->copy()->addMinutes($extraMinutes);

            if ($newEnd->gt($startTime->copy()->addMinutes($maxMinutes))) {
                throw new RentalException(
                    "A rental cannot run longer than {$maxHours} hours in total, so it cannot be extended further."
                );
            }

            $totalMinutes = (int) $locked->durationMinutes + $extraMinutes;

            $log = array_values($locked->extensions ?? []);
            $log[] = [
                'n' => count($log) + 1,
                'minutes' => $extraMinutes,
                'fee' => $fee,
                'chargedHours' => $chargedHours,
                'at' => Carbon::now()->toIso8601String(),
                'newExpectedEnd' => $newEnd->toIso8601String(),
                'by' => $actor?->name ?? 'rider',
            ];

            $locked->update([
                // An extension undoes the expired/overdue transition: the ride
                // has paid time on it again, so it goes back to Active and the
                // expiry markers that made it read as overdue are cleared.
                'status' => Rental::STATUS_ACTIVE,
                'expiredAt' => null,
                'overdueAt' => null,
                'isOverdue' => false,
                'overdueReturnAt' => null,
                'warningSentAt' => null,

                'expectedEndTime' => $newEnd,
                'returnDeadline' => $locked->returnDeadline !== null
                    ? $locked->returnDeadline->copy()->addMinutes($extraMinutes)
                    : null,

                'durationMinutes' => $totalMinutes,
                'durationFormatted' => $this->formatDuration($totalMinutes),
                'chargedHours' => (int) $locked->chargedHours + $chargedHours,
                'totalFee' => round((float) $locked->totalFee + $fee, 2),

                'extensionCount' => (int) $locked->extensionCount + 1,
                'extendedMinutes' => (int) $locked->extendedMinutes + $extraMinutes,
                'extensionFee' => round((float) $locked->extensionFee + $fee, 2),
                'extensions' => $log,
                'lastExtendedAt' => Carbon::now(),
            ]);

            return $locked;
        });

        $label = intdiv($extraMinutes, 60) . 'h ' . ($extraMinutes % 60) . 'm';

        // Snapshot columns can be empty on rentals created directly, so fall
        // back to the related bicycle before writing the notification copy.
        $bikeName = $rental->bicycleName ?: ($rental->bicycle?->name ?? 'your bicycle');
        $bikeSerial = $rental->bicycleSerial ?: ($rental->bicycle?->serialNumber ?? '');
        $bikeRef = $bikeSerial !== '' ? "{$bikeName} (#{$bikeSerial})" : $bikeName;
        $riderName = $rental->riderName ?: ($rental->rider?->name ?? 'rider');

        $this->notificationService->create(
            $rental->riderId,
            'Ride Extended',
            "Your ride on bicycle {$bikeRef} was extended by {$label}. "
                . 'New end time: ' . $rental->expectedEndTime->format('M d, Y g:i A') . ". "
                . "An extra PHP {$fee} has been added to your rental fee.",
            'rental_extended',
            ['rentalId' => $rental->rentalId, 'bicycleId' => $rental->bicycleId]
        );

        $adminIds = User::where('role', User::ROLE_ADMIN)->pluck('id')->all();

        if (! empty($adminIds)) {
            $this->notificationService->createForUsers(
                $adminIds,
                'Ride Extended',
                "Rental {$rental->rentalId} ({$riderName}, bicycle {$bikeRef}) "
                    . "was extended by {$label} for an extra PHP {$fee}. "
                    . 'The receipt shows the ride as extended, not overdue.',
                'rental_extended',
                ['rentalId' => $rental->rentalId, 'bicycleId' => $rental->bicycleId]
            );
        }

        RentalFeed::touch();

        return $rental->fresh();
    }

    /**
     * Expire every active rental whose expected end time has passed, persist
     * the return-grace deadline, and notify administrators. Idempotent by
     * state: only status=active rentals are considered, so a rental that
     * already transitioned is never touched or notified twice.
     *
     * Comparisons use the application timezone (config('app.timezone')), so
     * the same authoritative server clock is used by the scheduler command,
     * the request-time sync, and the tests.
     */
    public function expireDueRentals(?Carbon $asOf = null): int
    {
        $asOf = $asOf ?? Carbon::now();

        $expired = Rental::with(['bicycle'])
            ->where('status', Rental::STATUS_ACTIVE)
            ->whereNotNull('expectedEndTime')
            ->where('expectedEndTime', '<=', $asOf)
            ->get();

        if ($expired->isEmpty()) {
            return 0;
        }

        $adminIds = User::where('role', User::ROLE_ADMIN)->pluck('id')->all();

        foreach ($expired as $rental) {
            $rental->update([
                'status' => Rental::STATUS_EXPIRED,
                'expiredAt' => $asOf,
                'returnGraceStartedAt' => $rental->returnGraceStartedAt
                    ?? $rental->expectedEndTime->copy(),
                'returnGraceDeadline' => $rental->returnGraceDeadline
                    ?? $rental->expectedEndTime->copy()->addSeconds(
                        max(0, (int) SystemSetting::getValue('returnGraceSeconds', 300))
                    ),
            ]);

            if (!empty($adminIds)) {
                $this->notificationService->createForUsers(
                    $adminIds,
                    'Rental Expired',
                    "Rental {$rental->rentalId} by {$rental->riderName} for bicycle {$rental->bicycleName} (#{$rental->bicycleSerial}) expired on "
                        . $rental->expectedEndTime->format('M d, Y g:i A')
                        . ' and now has status "expired". Set a return deadline to start the return workflow.',
                    'rental_expired',
                    ['rentalId' => $rental->rentalId, 'bicycleId' => $rental->bicycleId]
                );
            }
        }

        RentalFeed::touch();

        return $expired->count();
    }

    /**
     * Mark awaiting-return rentals as overdue_return once their return
     * deadline has passed, snapshot the accrued penalty, and notify both the
     * renter and the administrators. Idempotent by state: only
     * status=awaiting_return rows are considered and each rental notifies
     * exactly once.
     */
    public function markOverdueReturnDeadlines(?Carbon $asOf = null): int
    {
        $asOf = $asOf ?? Carbon::now();

        $overdue = Rental::with(['bicycle'])
            ->where('status', Rental::STATUS_AWAITING_RETURN)
            ->whereNotNull('returnDeadline')
            ->where('returnDeadline', '<=', $asOf)
            ->get();

        if ($overdue->isEmpty()) {
            return 0;
        }

        foreach ($overdue as $rental) {
            $rental->update([
                'status' => Rental::STATUS_OVERDUE_RETURN,
                'overdueReturnAt' => $asOf,
                'penaltyAccrued' => $this->calculatePenaltyForRental($rental, $asOf),
            ]);

            $penaltyText = $this->penaltyDisplay($rental);

            $this->notificationService->create(
                $rental->riderId,
                'Return Deadline Passed',
                "You missed the return deadline for rental {$rental->rentalId} (bicycle {$rental->bicycleName} #{$rental->bicycleSerial}). "
                    . 'Rental period over; the return is now overdue. '
                    . ($penaltyText ? "Penalty applied: {$penaltyText}. " : '')
                    . 'The penalty keeps accruing until an administrator confirms the return.',
                'rental_overdue_return',
                ['rentalId' => $rental->rentalId, 'bicycleId' => $rental->bicycleId]
            );
        }

        $adminIds = User::where('role', User::ROLE_ADMIN)->pluck('id')->all();

        if (!empty($adminIds)) {
            $this->notificationService->createForUsers(
                $adminIds,
                'Overdue Returns',
                $overdue->count() . ' rental(s) passed their return deadline and now require confirmation (overdue_return). Verify the return and confirm it to settle any accrued penalty.',
                'rental_overdue_return'
            );
        }

        RentalFeed::touch();

        return $overdue->count();
    }

    /**
     * Synchronize the rental lifecycle using the authoritative server clock.
     * Runs the same idempotent transitions as the scheduled commands so stale
     * records can never keep showing as "Active" or "Awaiting Return" when a
     * user requests a relevant page or API endpoint — even if the scheduler
     * is not running.
     */
    public function synchronizeLifecycle(?Carbon $asOf = null): array
    {
        return [
            'expired' => $this->expireDueRentals($asOf),
            'overdue_return' => $this->markOverdueReturnDeadlines($asOf),
        ];
    }

    private function penaltyDisplay(Rental $rental): string
    {
        if ($rental->penaltyAccrued !== null && (float) $rental->penaltyAccrued > 0) {
            return 'PHP ' . number_format((float) $rental->penaltyAccrued, 2) . ' accrued so far';
        }

        return 'PHP '
            . number_format(self::LATE_PENALTY_PER_COMPLETED_FIVE_MINUTES, 2)
            . ' per completed 5-minute block after the return deadline';
    }

    /**
     * Bulk-expire due active rentals. Kept as a backward-compatible alias of
     * expireDueRentals() for existing callers.
     */
    public function markExpiredRentals(?Carbon $asOf = null): int
    {
        return $this->expireDueRentals($asOf);
    }

    public function calculateFees(string $startTime, ?string $endTime, float $ratePerHour): array
    {
        $start = Carbon::parse($startTime);
        $end = $endTime ? Carbon::parse($endTime) : Carbon::now();

        $durationMinutes = (int) $start->diffInMinutes($end);
        $chargedHours = max((int) ceil($durationMinutes / 60), 1);
        $totalFee = $durationMinutes * self::RENTAL_RATE_PER_MINUTE;

        $durationFormatted = $this->formatDuration($durationMinutes);

        return [
            'totalFee' => round($totalFee, 2),
            'durationMinutes' => $durationMinutes,
            'durationFormatted' => $durationFormatted,
            'chargedHours' => $chargedHours,
            'ratePerHour' => self::RENTAL_RATE_PER_HOUR,
        ];
    }

    public function createRentalFromPaidPayment(Payment $payment): Rental
    {
        $metadata = $payment->metadata ?? [];
        $durationHours = (int) ($metadata['rental_duration_hours'] ?? 1);
        $rider = User::find($payment->userId);

        $rental = Rental::create([
            'rentalId' => $this->generateRentalId(),
            'bicycleId' => $payment->bicycleId,
            'bicycleName' => optional(Bicycle::find($payment->bicycleId))->name,
            'bicycleSerial' => optional(Bicycle::find($payment->bicycleId))->serialNumber,
            'riderId' => $payment->userId,
            'riderName' => $rider?->name,
            'riderEmail' => $rider?->email,
            'status' => 'active',
            'startTime' => now(),
            'endTime' => now()->addHours(max($durationHours, 1)),
            'ratePerHour' => $durationHours > 0 ? round($payment->totalAmount / $durationHours, 2) : $payment->totalAmount,
            'totalFee' => $payment->totalAmount,
            'chargedHours' => max($durationHours, 1),
            'durationMinutes' => max($durationHours, 1) * 60,
            'durationFormatted' => $durationHours . 'h 0m',
            'paymentStatus' => 'paid',
            'paymentMethod' => 'gcash',
        ]);

        $payment->update([
            'rentalId' => $rental->id,
            'amountPaid' => $payment->totalAmount,
            'balanceDue' => 0,
        ]);

        $bicycle = Bicycle::find($payment->bicycleId);
        if ($bicycle) {
            $updates = [
                'status' => Bicycle::STATUS_RENTED,
                'currentRider' => $payment->userId,
                'currentRentalId' => $rental->id,
            ];

            // Only the in-service unit has a lock to open. The recorded
            // lockStatus of a bicycle parked out of service is physical truth and
            // must not be rewritten by a payment callback.
            if ($bicycle->isInService()) {
                $updates['lockStatus'] = Bicycle::LOCK_UNLOCKED;
                $updates['lastLockAction'] = now();
            }

            $bicycle->update($updates);

            if ($rider) {
                $this->iotService->sendCommand($bicycle->id, 'unlock', [], $rider);
            }
        }

        return $rental;
    }

    private function formatDuration(int $durationMinutes): string
    {
        $hours = intdiv($durationMinutes, 60);
        $minutes = $durationMinutes % 60;

        return "{$hours}h {$minutes}m";
    }

    public function generateRentalId(): string
    {
        $date = Carbon::now()->format('Ymd');
        $random = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));

        return "REN-{$date}-{$random}";
    }
}
