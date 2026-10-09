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

        if ($bicycle->batteryLevel < 20) {
            throw new RentalException(
                'Bicycle battery level is too low to rent.'
            );
        }

        // Shared with the Rent page's estimate, so the figure the rider was
        // shown and the figure charged here can never disagree.
        $ratePerHour = $bicycle->rentableHourlyRate();

        $durationHours = $durationMinutes / 60;
        $chargedHours = max((int) ceil($durationHours), 1);
        $totalFee = round($ratePerHour * $chargedHours, 2);

        $durationFormatted = $this->formatDuration($durationMinutes);

        $pricing = [
            'ratePerHour' => $ratePerHour,
            'chargedHours' => $chargedHours,
            'totalFee' => $totalFee,
            'durationFormatted' => $durationFormatted,
        ];

        $isGcash = $paymentMethod === 'gcash';
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
     * Phase 1 of the return lifecycle: mark a ride as ended.
     *
     * The rider has finished and brought the bicycle back, so we record the
     * actual end time and move the rental into the "awaiting_return" state.
     * The bicycle is held (kept Rented + the smart lock is secured) until an
     * administrator confirms the return via processReturn(). This lets the
     * Returns module surface pending returns for inspection.
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

        $endTime = $returnTime ?? Carbon::now();

        return DB::transaction(function () use ($rental, $user, $endTime, $returnLat, $returnLng, $notes) {
            $rental->update([
                'endTime' => $endTime,
                'endLocation' => ['lat' => $returnLat, 'lng' => $returnLng],
                'notes' => $notes,
                'status' => Rental::STATUS_AWAITING_RETURN,
                'returnRequestedAt' => Carbon::now(),
            ]);

            // Bicycle is physically back: secure the smart lock but keep it
            // held (Rented) until an administrator processes the return. A
            // bicycle that is not in service has no lock to secure, and its
            // recorded lockStatus is left exactly as it is.
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
                'Bicycle Returned',
                "Your rental {$rental->rentalId} has been returned and is awaiting confirmation.",
                'rental_returned'
            );

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

        $rental->update([
            'returnRequestedAt' => $returnTime ?? Carbon::now(),
        ]);

        $adminIds = User::where('role', User::ROLE_ADMIN)->pluck('id')->all();

        if (!empty($adminIds)) {
            $this->notificationService->createForUsers(
                $adminIds,
                'Return Requested',
                "{$user->name} requested a return for rental {$rental->rentalId} "
                    . "(bicycle {$rental->bicycleName} #{$rental->bicycleSerial}). Confirm the return to settle it.",
                'return_requested',
                ['rentalId' => $rental->rentalId, 'bicycleId' => $rental->bicycleId]
            );
        }

        RentalFeed::touch();

        return ['rental' => $rental->fresh(), 'requested' => true];
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
        array $penalty = [],
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

        $penaltyType = $penalty['type'] ?? null;
        $penaltyValue = isset($penalty['value']) && is_numeric($penalty['value'])
            ? round((float) $penalty['value'], 2)
            : null;

        if ($penaltyType !== null && !in_array($penaltyType, Rental::PENALTY_TYPES, true)) {
            throw new RentalException('Invalid penalty type.');
        }

        $unchanged = $rental->returnDeadline !== null
            && $rental->returnDeadline->floorMinute()->eq($deadline)
            && $rental->penaltyType === $penaltyType
            && (float) $rental->penaltyValue === (float) $penaltyValue;

        if ($unchanged) {
            return $rental->fresh();
        }

        $attributes = [
            'status' => Rental::STATUS_AWAITING_RETURN,
            'returnDeadline' => $deadline,
            'returnDeadlineSetBy' => (string) ($admin->name ?? $admin->id),
            'penaltyType' => $penaltyType,
            'penaltyValue' => $penaltyValue,
        ];

        if ($message !== null && trim($message) !== '') {
            $attributes['notes'] = trim(implode(PHP_EOL, array_filter([
                $rental->notes,
                'Return deadline message: ' . trim($message),
            ])));
        }

        $rental->update($attributes);

        $penaltyLine = $this->penaltyRuleDescription($penaltyType, $penaltyValue);

        $notificationMessage = "Your rental period for bicycle {$rental->bicycleName} (#{$rental->bicycleSerial}) has ended. "
            . "Please return the bicycle by {$deadline->format('M d, Y g:i A')}."
            . ($penaltyLine ? " {$penaltyLine}." : '')
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
     * deliberately left unset so resolvedPenaltyRule() falls back to the global
     * late-return rate once the window lapses.
     *
     * Idempotent by state: a rental that already carries a deadline keeps it,
     * so a repeated heartbeat can neither shorten nor restart a window, and the
     * manual setReturnDeadline() path still wins whenever an administrator used
     * it first.
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

        if ($graceSeconds === 0) {
            return null;
        }

        $deadline = $asOf->copy()->addSeconds($graceSeconds);

        $attributes = [
            'status' => Rental::STATUS_AWAITING_RETURN,
            'returnDeadline' => $deadline,
            'returnDeadlineSetBy' => 'IoT park detection',
        ];

        // The lock re-engaging while the ride is still open *is* the return, so
        // close the ride here exactly as markRideEnded() would have. Rentals
        // that already expired leave endTime alone: the administrator's expiry
        // path has never set one and settlement derives the fee from the
        // return time regardless.
        if (in_array($rental->status, [Rental::STATUS_ACTIVE, Rental::STATUS_OVERDUE], true)) {
            $attributes['endTime'] = $rental->endTime ?? $asOf;
            $attributes['returnRequestedAt'] = $rental->returnRequestedAt ?? $asOf;
        }

        $rental->update($attributes);

        $graceLabel = $this->describeReturnGrace($graceSeconds);
        $deadlineLabel = $deadline->format('M d, Y g:i A');

        $this->notificationService->create(
            $rental->riderId,
            'Return Grace Started',
            "Bicycle {$rental->bicycleName} (#{$rental->bicycleSerial}) was detected parked and locked. "
                . "Return rental {$rental->rentalId} by {$deadlineLabel} - a {$graceLabel} grace window. "
                . 'The late-return penalty starts to apply once it lapses.',
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
                    . "({$graceLabel} grace, no manual deadline needed). Confirm the return to settle it.",
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
     * Human-readable description of the saved late-return penalty rule, used
     * in renter notifications and admin summaries. Returns '' when no rule is
     * configured (legacy global per-hour setting is then the source of truth).
     */
    private function penaltyRuleDescription(?string $type, ?float $value): string
    {
        if ($type === null || $value === null || $value <= 0) {
            return '';
        }

        $amount = number_format($value, 2);

        return match ($type) {
            Rental::PENALTY_FIXED => "A fixed late-return penalty of PHP {$amount} will apply after the deadline",
            Rental::PENALTY_PER_MINUTE => "A PHP {$amount} per minute late-return penalty will apply after the deadline",
            default => "A PHP {$amount} per hour late-return penalty will apply after the deadline",
        };
    }

    /**
     * Resolve the penalty rule that actually applies to a rental, substituting
     * the legacy global per-hour setting whenever the rental itself has no
     * saved rule. This is the single place the fallback is decided, so the
     * amount shown on a bicycle display can never drift from the amount
     * charged at return.
     *
     * @return array{type: string, value: float, isDefaultRate: bool}
     */
    public function resolvedPenaltyRule(?Rental $rental): array
    {
        $value = $rental?->penaltyValue !== null
            ? (float) $rental->penaltyValue
            : (float) SystemSetting::getValue('lateReturnPenaltyPerHour', 20);

        return [
            'type' => $rental?->penaltyType ?? Rental::PENALTY_PER_HOUR,
            'value' => round(max($value, 0), 2),
            'isDefaultRate' => $rental === null || $rental->penaltyValue === null,
        ];
    }

    /**
     * Penalty accrued for a rental as of the given server time, computed from
     * the administrator-set deadline and the saved penalty rule. Rentals with a
     * deadline but no saved rule fall back to the global per-hour setting.
     *
     * Nothing is ever charged while the deadline is still in the future.
     */
    public function calculatePenaltyForRental(Rental $rental, Carbon $asOf): float
    {
        $deadline = $rental->returnDeadline;

        if ($deadline === null || $asOf->lte($deadline)) {
            return 0.0;
        }

        $minutes = abs((int) $asOf->diffInMinutes($deadline));
        $rule = $this->resolvedPenaltyRule($rental);

        return match ($rule['type']) {
            Rental::PENALTY_FIXED => round($rule['value'], 2),
            Rental::PENALTY_PER_MINUTE => round($minutes * $rule['value'], 2),
            default => round(max(ceil($minutes / 60), 1) * $rule['value'], 2),
        };
    }

    /**
     * Live countdown and penalty snapshot for a rental, as published to the
     * bicycle's on-board display.
     *
     * The countdown targets the administrator-set return deadline once one
     * exists, and the expected end time before that, so the rider always sees
     * the deadline that is actually in force.
     *
     * The projected penalty intentionally reuses the same branches
     * processReturn() charges from: the deadline rule when an administrator set
     * a deadline, otherwise the legacy expected-end-time surcharge. The figure
     * a rider watches climb on the LCD is therefore the figure that lands on
     * the final fee.
     */
    public function rentalClockFor(Rental $rental, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ? $asOf->copy() : Carbon::now();

        // Compared as unix seconds rather than via Carbon's diff helpers so the
        // sign is unambiguous regardless of the installed Carbon version.
        $startTimestamp = $rental->startTime?->getTimestamp() ?? $asOf->getTimestamp();
        $asOfTimestamp = $asOf->getTimestamp();
        $deadline = $rental->returnDeadline ?? $rental->expectedEndTime;
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
            $projectedPenalty = $this->calculatePenaltyForRental($rental, $asOf);
        } elseif ($rental->expectedEndTime !== null) {
            $penaltyBasis = 'expected_end_time';
            $projectedPenalty = $this->calculateOverdueFee(
                $rental->expectedEndTime,
                $asOf,
                $rental->ratePerHour
            );
        } else {
            $penaltyBasis = 'none';
            $projectedPenalty = 0.0;
        }

        $window = $deadlineTimestamp === null ? 0 : $deadlineTimestamp - $startTimestamp;

        return [
            'startTime' => $rental->startTime,
            'effectiveDeadline' => $deadline,
            'deadlineBasis' => $deadlineTimestamp === null
                ? 'none'
                : ($rental->returnDeadline !== null ? 'return_deadline' : 'expected_end_time'),
            'elapsedSeconds' => $elapsedSeconds,
            'remainingSeconds' => $remainingSeconds,
            'overdueSeconds' => $remainingSeconds === null ? 0 : max(0, -$remainingSeconds),
            'isOverdue' => $remainingSeconds !== null && $remainingSeconds < 0,
            'progressPercent' => $window > 0
                ? (int) min(100, max(0, (int) floor($elapsedSeconds / $window * 100)))
                : null,
            'ratePerHour' => (float) $rental->ratePerHour,
            'projectedBaseFee' => $fees['totalFee'],
            'penaltyBasis' => $penaltyBasis,
            'projectedPenalty' => round($projectedPenalty, 2),
            'projectedTotal' => round($fees['totalFee'] + $projectedPenalty, 2),
            'rule' => $this->resolvedPenaltyRule($rental),
        ];
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
        $returnTime = isset($input['returnTime']) && $input['returnTime']
            ? Carbon::parse($input['returnTime'])
            : Carbon::now();

        $condition = in_array($input['condition'] ?? null, [
            Rental::CONDITION_GOOD,
            Rental::CONDITION_FAIR,
            Rental::CONDITION_DAMAGED,
            Rental::CONDITION_NEEDS_MAINTENANCE,
        ], true) ? $input['condition'] : Rental::CONDITION_GOOD;

        // A late-return penalty only makes sense against an administrator-set
        // deadline. Rentals that reached awaiting_return without a deadline
        // (e.g. the rider ended the ride before expiry) keep the legacy
        // expected-end-time surcharge instead. Penalties are always computed
        // from server timestamps, so the final amount is fixed at confirm time
        // and stops accumulating from that moment.
        $latePenalty = $rental->returnDeadline !== null
            ? $this->calculatePenaltyForRental($rental, $returnTime)
            : $this->calculateOverdueFee(
                $rental->expectedEndTime,
                $returnTime,
                $rental->ratePerHour
            );

        $fees = $this->calculateFees(
            $rental->startTime,
            $returnTime->toDateTimeString(),
            $rental->ratePerHour
        );

        // Elapsed-time pricing is the rule, but a rider who bought extra time
        // keeps the purchased-fee floor: returning shortly after an extension
        // must still settle the extension fee they signed up for, exactly as
        // an unextended rental settles its booked hours. Rentals without
        // extensions keep the pure elapsed-time figure they always had.
        if ($rental->hasRideExtensions()) {
            $fees['totalFee'] = max($fees['totalFee'], (float) $rental->totalFee);
        }

        $finalFee = round($fees['totalFee'] + $latePenalty, 2);

        return DB::transaction(function () use (
            $rental,
            $admin,
            $returnTime,
            $condition,
            $input,
            $fees,
            $latePenalty,
            $finalFee,
            $lockActor
        ) {
            $rental->update([
                'endTime' => $returnTime,
                'status' => Rental::STATUS_RETURNED,
                // Never overwrite the moment the rider actually asked to
                // return; only stamp it when nobody got there first.
                'returnRequestedAt' => $rental->returnRequestedAt ?? $returnTime,
                'totalFee' => $finalFee,
                'finalFee' => $finalFee,
                'overdueFee' => $latePenalty,
                'penaltyAccrued' => $latePenalty,
                'durationMinutes' => $fees['durationMinutes'],
                'durationFormatted' => $fees['durationFormatted'],
                'chargedHours' => $fees['chargedHours'],
                'paymentStatus' => 'paid',
                'paidAt' => Carbon::now(),
                'returnCondition' => $condition,
                'returnInspectedBy' => (string) ($admin->name ?? $admin->id),
                'returnProcessedAt' => Carbon::now(),
                'returnNote' => $input['note'] ?? null,
            ]);

            $this->settleBicycleAfterReturn($rental, $condition, $admin);

            $this->syncPayment($rental, $finalFee);

            $user = User::find($rental->riderId);
            if ($user) {
                $user->increment('totalSpent', $finalFee);
            }

            if ($lockActor !== null && $rental->bicycleId) {
                $bicycle = Bicycle::find($rental->bicycleId);
                if ($bicycle && $bicycle->isInService()) {
                    Rental::resolveConnection()->afterCommit(function () use ($bicycle, $rental, $lockActor) {
                        $this->iotService->sendCommand($bicycle->id, 'lock', [
                            'reason' => 'rental_settled',
                            'rentalId' => $rental->id,
                        ], $lockActor);
                    });
                }
            }

            $penaltyNotice = $latePenalty > 0
                ? " A late-return penalty of PHP {$latePenalty} applies."
                : '';

            $this->notificationService->create(
                $rental->riderId,
                'Return Confirmed',
                "Your rental {$rental->rentalId} has been confirmed. Final fee: PHP {$finalFee}.{$penaltyNotice}",
                'rental_completed'
            );

            RentalFeed::touch();

            return [
                'rental' => $rental->fresh(),
                'fees' => [
                    'baseFee' => $fees['totalFee'],
                    'overdueFee' => $latePenalty,
                    'latePenalty' => $latePenalty,
                    'finalFee' => $finalFee,
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
     * Late-return penalty against an administrator-set return deadline: any
     * full hour (or partial, rounded up) beyond the deadline, charged at the
     * configurable late-return penalty rate from System Settings.
     */
    public function calculateLateReturnPenalty(?Carbon $returnDeadline, Carbon $returnTime): float
    {
        if ($returnDeadline === null) {
            return 0.0;
        }

        $rate = (float) SystemSetting::getValue('lateReturnPenaltyPerHour', 20);

        if ($returnTime->lte($returnDeadline)) {
            return 0.0;
        }

        $lateMinutes = abs((int) $returnTime->diffInMinutes($returnDeadline));
        $lateHours = max(ceil($lateMinutes / 60), 1);

        return round($lateHours * max($rate, 0), 2);
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
     * Keep the Payments module in sync by creating or updating a settled
     * payment record for the rental with the final amount.
     */
    private function syncPayment(Rental $rental, float $finalFee): void
    {
        $payment = Payment::where('rentalId', $rental->id)->first();

        if ($payment) {
            $payment->update([
                'status' => 'paid',
                'totalAmount' => $finalFee,
                'amount' => $finalFee,
                'paidAt' => Carbon::now(),
            ]);

            return;
        }

        Payment::create([
            'rentalId' => $rental->id,
            'userId' => $rental->riderId,
            'bicycleId' => $rental->bicycleId,
            'paymentReference' => $rental->paymentReference ?? $rental->rentalId,
            'paymentMethod' => $rental->paymentMethod ?: 'cash',
            'amount' => $finalFee,
            'convenienceFee' => 0,
            'totalAmount' => $finalFee,
            'currency' => 'PHP',
            'status' => 'paid',
            'paidAt' => Carbon::now(),
        ]);
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
     * The extension is priced with the same rule the base fee uses - whole
     * hours, rounded up, at the rental's hourly rate - and lands on the
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

        $ratePerHour = (float) $rental->ratePerHour;

        if ($ratePerHour <= 0) {
            $ratePerHour = (float) ($rental->bicycle?->rentableHourlyRate()
                ?? config('services.rental.rate_per_hour', 15.0));
        }

        $chargedHours = max((int) ceil($extraMinutes / 60), 1);
        $fee = round($ratePerHour * $chargedHours, 2);

        $maxHours = max(1, (int) SystemSetting::getValue('rentalMaxDurationHours', 12));
        $maxMinutes = $maxHours * 60;

        $rental = DB::transaction(function () use (
            $rental,
            $extraMinutes,
            $fee,
            $chargedHours,
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
     * Expire every active rental whose expected end time has passed and
     * notify administrators. Idempotent by state: only status=active rentals
     * are considered, so a rental that already transitioned is never
     * touched and its admin notification is never created twice.
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

        if ($rental->penaltyType === Rental::PENALTY_FIXED) {
            return 'PHP ' . number_format((float) ($rental->penaltyValue ?? 0), 2) . ' fixed penalty';
        }

        if ($rental->penaltyValue !== null) {
            $per = $rental->penaltyType === Rental::PENALTY_PER_MINUTE ? 'minute' : 'hour';

            return 'PHP ' . number_format((float) $rental->penaltyValue, 2) . ' per ' . $per;
        }

        return '';
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
        $durationHours = $durationMinutes / 60;
        $chargedHours = max(ceil($durationHours), 1);
        $totalFee = $chargedHours * $ratePerHour;

        $durationFormatted = $this->formatDuration($durationMinutes);

        return [
            'totalFee' => round($totalFee, 2),
            'durationMinutes' => $durationMinutes,
            'durationFormatted' => $durationFormatted,
            'chargedHours' => $chargedHours,
            'ratePerHour' => $ratePerHour,
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

        $payment->update(['rentalId' => $rental->id]);

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
