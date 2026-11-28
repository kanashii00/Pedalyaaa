<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Accident extends Model
{
    use HasFactory;

    protected $table = 'accidents';

    protected $fillable = [
        'bicycleId',
        'rentalId',
        'riderId',
        'riderName',
        'type',
        'severity',
        'description',
        'gpsLocation',
        'accelerometerData',
        'impactForce',
        'speedKmh',
        'imageUrl',
        'acknowledged',
        'acknowledgedBy',
        'acknowledgedAt',
        'alertSent',
        'reportedBy',
        'status',
        'investigationStatus',
        'breachDistance',
        'breachDirection',
        'actionTaken',
        'resolutionNotes',
        'resolvedBy',
        'resolvedAt',
        'lastEscalatedAt',
        'warningLevel',
        'distanceFromBoundary',
        'location',
    ];

    protected function casts(): array
    {
        return [
            'gpsLocation' => 'array',
            'accelerometerData' => 'array',
            'location' => 'array',
            'acknowledged' => 'boolean',
            'alertSent' => 'boolean',
            'impactForce' => 'decimal:2',
            'speedKmh' => 'decimal:2',
            'breachDistance' => 'decimal:2',
            'distanceFromBoundary' => 'decimal:2',
            'acknowledgedAt' => 'datetime',
            'resolvedAt' => 'datetime',
            'lastEscalatedAt' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function bicycle(): BelongsTo
    {
        return $this->belongsTo(Bicycle::class, 'bicycleId');
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class, 'rentalId');
    }

    public function riderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'riderId');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolvedBy');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledgedBy');
    }

    public function rider(): BelongsTo
    {
        // Prioritize explicit riderId foreign key, fallback to reportedBy if numeric
        $foreignKey = $this->riderId ? 'riderId' : 'reportedBy';
        return $this->belongsTo(User::class, $foreignKey);
    }

    /**
     * Dynamically resolve the registered rider's full name.
     * Never hardcodes, invents, or guesses names.
     * If no active rental was associated, returns "No Active Rental".
     */
    public function getRiderDisplayNameAttribute(): string
    {
        if ($this->riderId && $this->riderUser) {
            $name = trim((string) $this->riderUser->name);
            if ($name !== '') {
                return $name;
            }
        }

        if (!empty($this->riderName)) {
            return trim($this->riderName);
        }

        if ($this->rentalId && $this->rental) {
            if ($this->rental->rider && !empty(trim((string) $this->rental->rider->name))) {
                return trim($this->rental->rider->name);
            }
            if (!empty($this->rental->riderName)) {
                return trim($this->rental->riderName);
            }
        }

        if ($this->bicycleId && $this->bicycle) {
            if ($this->bicycle->currentRental && $this->bicycle->currentRental->rider) {
                $name = trim((string) $this->bicycle->currentRental->rider->name);
                if ($name !== '') {
                    return $name;
                }
            }
            if ($this->bicycle->currentRiderUser) {
                $name = trim((string) $this->bicycle->currentRiderUser->name);
                if ($name !== '') {
                    return $name;
                }
            }
        }

        return 'No Active Rental';
    }

    public function getInvestigationStatusDisplayAttribute(): string
    {
        $status = $this->status ?? 'open';
        $invStatus = $this->investigationStatus ?? $status;

        return match ($status) {
            'investigating' => 'Under Investigation',
            'false_alarm' => 'False Alarm',
            'resolved' => 'Resolved',
            'returned' => 'Resolved (Returned)',
            'open' => $invStatus === 'investigating' ? 'Under Investigation' : 'Pending Review',
            default => ucfirst(str_replace('_', ' ', $invStatus)),
        };
    }

    public function getIsUnresolvedAttribute(): bool
    {
        return in_array($this->status, ['open', 'investigating'], true);
    }
}
