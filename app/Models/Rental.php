<?php

namespace App\Models;

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
        ];
    }

    public function bicycle(): BelongsTo
    {
        return $this->belongsTo(Bicycle::class, 'bicycleId');
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'riderId');
    }
}
