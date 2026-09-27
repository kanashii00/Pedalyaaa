<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bicycle extends Model
{
    use HasFactory, SoftDeletes;

    /** Rental / operational state of the bicycle. */
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_RENTED = 'rented';

    public const STATUS_MAINTENANCE = 'maintenance';

    public const STATUS_REMOVED = 'removed';

    /** Physical smart-lock state (independent of status). */
    public const LOCK_LOCKED = 'locked';

    public const LOCK_UNLOCKED = 'unlocked';

    protected $table = 'bicycles';

    protected $fillable = [
        'name',
        'model',
        'serialNumber',
        'description',
        'status',
        'hourlyRate',
        'currentLat',
        'currentLng',
        'batteryLevel',
        'lockStatus',
        'qrCode',
        'totalRentals',
        'totalDistance',
        'condition',
        'lastMaintenanceDate',
        'currentRider',
        'currentRentalId',
        'lastGpsUpdate',
        'lastHeartbeat',
        'lastLockAction',
        'lockActionBy',
        'addedBy',
        'removedAt',
        'removedBy',
    ];

    protected $appends = ['zone'];

    /**
     * The device credential is intentionally absent from $fillable so it can
     * never be set through a mass-assigned request, and hidden so the digest
     * never reaches a serialised API response.
     */
    protected $hidden = [
        'deviceApiKey',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'deviceKeyProvisionedAt' => 'datetime',
            'lastMaintenanceDate' => 'datetime',
            'lastGpsUpdate' => 'datetime',
            'lastHeartbeat' => 'datetime',
            'lastLockAction' => 'datetime',
            'removedAt' => 'datetime',
            'batteryLevel' => 'integer',
            'totalRentals' => 'integer',
            'hourlyRate' => 'decimal:2',
            'currentLat' => 'decimal:7',
            'currentLng' => 'decimal:7',
            'totalDistance' => 'decimal:2',
        ];
    }

    public function rentals(): HasMany
    {
        return $this->hasMany(Rental::class, 'bicycleId');
    }

    public function gpsLogs(): HasMany
    {
        return $this->hasMany(GpsLog::class, 'bicycleId');
    }

    public function deviceStatuses(): HasMany
    {
        return $this->hasMany(DeviceStatus::class, 'bicycleId');
    }

    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class, 'bicycleId');
    }

    public function pendingCommands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class, 'bicycleId');
    }

    public function latestTelemetry()
    {
        return $this->hasOne(DeviceStatus::class, 'bicycleId')->latestOfMany('eventTimestamp');
    }

    public function latestGpsLog()
    {
        return $this->hasOne(GpsLog::class, 'bicycleId')->latestOfMany('timestamp');
    }

    public function currentRiderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'currentRider');
    }

    /**
     * The rental that currently holds this bicycle, if any.
     *
     * Denormalised onto the bicycle row by RentalService on every status
     * transition, so the inventory can show who is riding and which rental is
     * open without a second query per row.
     */
    public function currentRental(): BelongsTo
    {
        return $this->belongsTo(Rental::class, 'currentRentalId');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AVAILABLE);
    }

    public function scopeRented(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RENTED);
    }

    public function scopeInMaintenance(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_MAINTENANCE);
    }

    /**
     * Issue a fresh device credential for this bicycle's on-board ESP32 and
     * return the plaintext token. Only the SHA-256 digest is persisted, so the
     * plaintext is shown exactly once and cannot be recovered afterwards.
     */
    public function issueDeviceKey(): string
    {
        $plain = bin2hex(random_bytes(32));

        $this->forceFill([
            'deviceApiKey' => hash('sha256', $plain),
            'deviceKeyProvisionedAt' => now(),
        ])->save();

        return $plain;
    }

    /** Drop this bicycle's device credential, locking its ESP32 out. */
    public function revokeDeviceKey(): void
    {
        $this->forceFill([
            'deviceApiKey' => null,
            'deviceKeyProvisionedAt' => null,
        ])->save();
    }

    public function hasDeviceKey(): bool
    {
        return $this->deviceApiKey !== null && $this->deviceApiKey !== '';
    }

    /** Constant-time check of a presented plaintext key against the digest. */
    public function hasValidDeviceKey(?string $plain): bool
    {
        if (!$this->hasDeviceKey() || $plain === null || $plain === '') {
            return false;
        }

        return hash_equals($this->deviceApiKey, hash('sha256', $plain));
    }

    /** Locate a bicycle by a presented plaintext key in a single indexed query. */
    public static function findByDeviceKey(string $plain): ?static
    {
        return static::where('deviceApiKey', hash('sha256', $plain))->first();
    }

    public function getZoneAttribute(): array
    {
        return ['inside' => null, 'distance' => null, 'level' => 'unknown', 'warning' => false];
    }
}
