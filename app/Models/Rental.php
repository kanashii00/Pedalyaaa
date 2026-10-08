<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rental extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PENDING = 'pending';

    public const STATUS_AWAITING_RETURN = 'awaiting_return';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_OVERDUE_RETURN = 'overdue_return';

    /**
     * Statuses in which a rental is still occupying its bicycle and the rider
     * still owes time on it. A rental outside this set is settled and must
     * never be presented as the bicycle's current, live rental.
     */
    /**
     * Every status a rental can hold.
     *
     * Filter validation reads from this so a status cannot be added to the enum
     * and then be unfilterable through the API.
     */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_PENDING,
        self::STATUS_AWAITING_RETURN,
        self::STATUS_OVERDUE,
        self::STATUS_EXPIRED,
        self::STATUS_OVERDUE_RETURN,
        self::STATUS_RETURNED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Statuses of a rental that has not been settled yet: the rider still owes
     * the bicycle, whether or not they have asked to hand it back.
     */
    public const LIVE_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_OVERDUE,
        self::STATUS_EXPIRED,
        self::STATUS_AWAITING_RETURN,
        self::STATUS_OVERDUE_RETURN,
    ];

    /**
     * The statuses the Active Rentals queue is made of: a rental the rider is
     * still holding, plus requests that have not been decided yet.
     *
     * This is the one definition of "active rental". The queue page, its
     * "Showing N records" footer and the sidebar badge all read it, so the
     * badge can never drift away from the rows behind it (it used to count
     * unread `rental_started` notifications instead, which is an unrelated
     * number that only ever grows).
     *
     * `returned`/`completed`/`cancelled` are deliberately absent: a settled
     * rental belongs to Rental History.
     */
    public const ACTIVE_LIST_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_PENDING,
        self::STATUS_OVERDUE,
        self::STATUS_EXPIRED,
    ];

    public const PENALTY_FIXED = 'fixed';

    public const PENALTY_PER_HOUR = 'per_hour';

    public const PENALTY_PER_MINUTE = 'per_minute';

    public const PENALTY_TYPES = [
        self::PENALTY_FIXED,
        self::PENALTY_PER_HOUR,
        self::PENALTY_PER_MINUTE,
    ];

    public const CONDITION_GOOD = 'good';

    public const CONDITION_FAIR = 'fair';

    public const CONDITION_DAMAGED = 'damaged';

    public const CONDITION_NEEDS_MAINTENANCE = 'needs_maintenance';

    /**
     * The inspected conditions the Return / End Ride workflow can record.
     *
     * A settlement copies the inspected condition straight onto the bicycle row,
     * so this doubles as the set of values `bicycles.condition` has to be able
     * to store. See the condition-alignment migration for the details.
     */
    public const CONDITIONS = [
        self::CONDITION_GOOD,
        self::CONDITION_FAIR,
        self::CONDITION_DAMAGED,
        self::CONDITION_NEEDS_MAINTENANCE,
    ];

    protected $table = 'rentals';

    protected $fillable = [
        'rentalId',
        'bicycleId',
        'bicycleName',
        'bicycleSerial',
        'riderId',
        'riderName',
        'riderEmail',
        'status',
        'startTime',
        'endTime',
        'expectedEndTime',
        'startLocation',
        'endLocation',
        'ratePerHour',
        'totalFee',
        'durationMinutes',
        'durationFormatted',
        'chargedHours',
        'extensionCount',
        'extendedMinutes',
        'extensionFee',
        'extensions',
        'lastExtendedAt',
        'totalDistance',
        'paymentStatus',
        'paidAt',
        'isOverdue',
        'overdueAt',
        'paymentMethod',
        'paymentReference',
        'notes',
        'cancelledBy',
        'cancelReason',
        'approvedBy',
        'approvedAt',
        'warningSentAt',
        'expiredAt',
        'returnDeadline',
        'returnDeadlineSetBy',
        'overdueReturnAt',
        'penaltyType',
        'penaltyValue',
        'penaltyAccrued',
        'returnRequestedAt',
        'returnCondition',
        'returnInspectedBy',
        'returnProcessedAt',
        'returnNote',
        'overdueFee',
        'finalFee',
    ];

    protected function casts(): array
    {
        return [
            'startTime' => 'datetime',
            'endTime' => 'datetime',
            'expectedEndTime' => 'datetime',
            'startLocation' => 'array',
            'endLocation' => 'array',
            'approvedAt' => 'datetime',
            'overdueAt' => 'datetime',
            'expiredAt' => 'datetime',
            'returnDeadline' => 'datetime',
            'overdueReturnAt' => 'datetime',
            'warningSentAt' => 'datetime',
            'returnRequestedAt' => 'datetime',
            'returnProcessedAt' => 'datetime',
            'paidAt' => 'datetime',
            'lastExtendedAt' => 'datetime',
            'extensions' => 'array',
            'isOverdue' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'ratePerHour' => 'decimal:2',
            'totalFee' => 'decimal:2',
            'totalDistance' => 'decimal:2',
            'overdueFee' => 'decimal:2',
            'finalFee' => 'decimal:2',
            'penaltyValue' => 'decimal:2',
            'penaltyAccrued' => 'decimal:2',
            'durationMinutes' => 'integer',
            'chargedHours' => 'integer',
            'extensionCount' => 'integer',
            'extendedMinutes' => 'integer',
            'extensionFee' => 'decimal:2',
        ];
    }

    /**
     * Whether the rider ever bought extra time on this rental.
     *
     * The Active Rentals queue, the receipts and the rider's own dashboard all
     * gate their "Extended" presentation on this one method, so they can never
     * disagree about whether a ride was extended.
     */
    public function hasRideExtensions(): bool
    {
        return (int) ($this->extensionCount ?? 0) > 0;
    }

    /**
     * Total extra time bought, as a "+1h 30m" label (empty when never extended).
     */
    public function extensionMinutesLabel(): string
    {
        $minutes = (int) ($this->extendedMinutes ?? 0);

        if ($minutes <= 0) {
            return '';
        }

        return '+' . intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm';
    }

    /**
     * One printable line per extension, newest last, for the receipt's
     * "Ride Extensions" section. Plain strings so the Blade receipt JSON and
     * the printed receipt can never describe an extension differently.
     *
     * @return array<int, string>
     */
    public function extensionReceiptLines(): array
    {
        $lines = [];

        foreach (($this->extensions ?? []) as $log) {
            $n = $log['n'] ?? (count($lines) + 1);
            $minutes = (int) ($log['minutes'] ?? 0);
            $fee = number_format((float) ($log['fee'] ?? 0), 2);
            $label = intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm';
            $at = isset($log['at']) && $log['at']
                ? \Carbon\Carbon::parse($log['at'])->format('M d, Y g:i A')
                : '';

            $lines[] = "Extension #{$n}: +{$label} — PHP {$fee}" . ($at ? " ({$at})" : '');
        }

        return $lines;
    }

    public function bicycle(): BelongsTo
    {
        return $this->belongsTo(Bicycle::class, 'bicycleId');
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'riderId');
    }

    /**
     * The Active Rentals queue.
     *
     * Every consumer of "how many rentals are active" - the queue listing, its
     * record count and the sidebar badge - goes through this scope, so they all
     * report the same number.
     */
    public function scopeActiveList(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_LIST_STATUSES);
    }

    /**
     * Live count of active rentals, straight from the table.
     *
     * Deliberately uncached: rentals are created, approved, expired, returned
     * and cancelled from several places (including a scheduler that flips
     * rentals to `expired` with nobody on the page), so a memoised total would
     * report a stale number. One indexed COUNT is cheaper than the bug.
     */
    public static function activeListCount(): int
    {
        return static::query()->activeList()->count();
    }
}
