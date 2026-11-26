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

    /**
     * Parked and not in service: present in the inventory, not rentable, and
     * for a unit without hardware there is no lock to command.
     *
     * This says nothing about the lock position - lockStatus remains the
     * physical truth and may well be `unlocked` for a parked unit.
     */
    public const STATUS_OUT_OF_SERVICE = 'out_of_service';

    /**
     * Every operational state a bicycle can be parked in.
     *
     * `locked` is deliberately absent: a lock position is not an operational
     * state, it lives in lockStatus. Validation and filter lists read from this
     * so a state cannot be added to the enum and then be unsettable.
     */
    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_RENTED,
        self::STATUS_MAINTENANCE,
        self::STATUS_OUT_OF_SERVICE,
        self::STATUS_REMOVED,
    ];

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
        'wheelCircumferenceM',
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
        'manualUnlockOverrideUntil',
        'manualUnlockOverrideBy',
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

    /**
     * iotEnabled is deliberately absent from $fillable for the same reason:
     * whether a physical unit is fitted with controllable hardware is a fleet
     * provisioning fact, not something a request body may assert. It is changed
     * through provisionForService()/sendOutOfService() (and the fleet reconcile
     * command), never through an ordinary create/update payload.
     */

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
            'manualUnlockOverrideUntil' => 'datetime',
            'removedAt' => 'datetime',
            'batteryLevel' => 'integer',
            'totalRentals' => 'integer',
            'iotEnabled' => 'boolean',
            'hourlyRate' => 'decimal:2',
            'wheelCircumferenceM' => 'decimal:3',
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

    /**
     * Most recent lock/command row for this bicycle, whatever its outcome.
     * Backs the "last lock command result" tile: real acknowledgement state,
     * never a fabricated status.
     */
    public function latestCommand()
    {
        return $this->hasOne(DeviceCommand::class, 'bicycleId')->latestOfMany('id');
    }

    public function latestTelemetry()
    {
        return $this->hasOne(DeviceStatus::class, 'bicycleId')->latestOfMany('eventTimestamp');
    }

    public function latestHeartbeatTelemetry()
    {
        return $this->hasOne(DeviceStatus::class, 'bicycleId')
            ->where('type', 'heartbeat')
            ->latestOfMany('eventTimestamp');
    }

    public function latestGpsLog()
    {
        return $this->hasOne(GpsLog::class, 'bicycleId')->latestOfMany('id');
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

    /**
     * Rate a rental is actually billed at.
     *
     * The column is NOT NULL, but a row written before that constraint existed
     * can still carry nothing. The Rent page and RentalService both read the rate
     * through here, so the estimate a rider is shown can never disagree with the
     * amount charged, and a missing rate can never reach the page as null or
     * NaN. A stored 0 is left alone: it is a deliberate value, not a gap.
     */
    public function rentableHourlyRate(): float
    {
        return $this->hourlyRate !== null
            ? (float) $this->hourlyRate
            : (float) config('services.rental.rate_per_hour', 15.00);
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
     * Bicycles fitted with controllable hardware - the only ones that may be
     * commanded and the only ones that may be rented.
     */
    public function scopeInService(Builder $query): Builder
    {
        return $query->where('iotEnabled', true);
    }

    /**
     * Bicycles parked out of service: no hardware, so nothing to command and
     * nothing to rent. Their current lockStatus is left exactly as it is.
     */
    public function scopeOutOfService(Builder $query): Builder
    {
        return $query->where('iotEnabled', false);
    }

    /**
     * The renter-facing availability rule, in one place.
     *
     * `status = available` alone is not enough: a bicycle sitting in the
     * inventory out of service also carries that status, and treating it as
     * rentable would advertise a unit whose lock can never be opened.
     */
    public function scopeRentable(Builder $query): Builder
    {
        return $query->where('iotEnabled', true)
            ->where('status', self::STATUS_AVAILABLE)
            ->where('batteryLevel', '>=', 20);
    }

    /**
     * Everything the Rent page has to render.
     *
     * The page shows an explicit, disabled card for each out-of-service bicycle
     * rather than hiding it, so the fleet a rider can see always matches the
     * fleet the admin manages.
     */
    public function scopeListable(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_REMOVED);
    }

    /** Is this bicycle fitted with hardware we can command? */
    public function isInService(): bool
    {
        return (bool) $this->iotEnabled;
    }

    /**
     * Does this bicycle carry a genuinely registered and configured physical
     * IoT device? Both halves are required: iotEnabled says hardware is
     * fitted, and a stored device credential says a real unit was keyed to
     * this row. Either half missing means "No IoT device registered" - the
     * row must never be presented as monitored.
     */
    public function hasRegisteredDevice(): bool
    {
        return $this->isInService() && $this->hasDeviceKey();
    }

    /** May a rider rent this bicycle right now? */
    public function isRentable(): bool
    {
        return $this->isInService()
            && $this->status === self::STATUS_AVAILABLE
            && $this->batteryLevel !== null
            && $this->batteryLevel >= 20;
    }

    /**
     * Single wording for why a bicycle is or is not selectable, shared by the
     * rider-facing card, the admin inventory and the JSON payload so the three
     * can never disagree about the same row.
     *
     * @return string{0:string,1:bool} [label, rentability]
     */
    public function availabilityLabel(): array
    {
        if (! $this->isInService()) {
            return ['Not In Service', false];
        }

        return match ($this->status) {
            self::STATUS_AVAILABLE => $this->batteryLevel === null
                ? ['Battery data unavailable', false]
                : ($this->batteryLevel < 20 ? ['Low battery', false] : ['Available', true]),
            self::STATUS_RENTED => ['Rented', false],
            self::STATUS_MAINTENANCE => ['Maintenance', false],
            self::STATUS_OUT_OF_SERVICE => ['Unavailable', false],
            self::STATUS_REMOVED => ['Removed', false],
            default => ['Unavailable', false],
        };
    }

    /**
     * Mark this bicycle as the live, controllable rental unit.
     *
     * Uses forceFill because iotEnabled is kept out of $fillable on purpose.
     */
    public function provisionForService(): bool
    {
        return $this->forceFill(['iotEnabled' => true])->save();
    }

    /**
     * Park this bicycle out of service.
     *
     * Deliberately does not touch lockStatus: a unit that is not in service has
     * no hardware to command, and whatever lock state it is recorded in is the
     * accurate physical state.
     */
    public function sendOutOfService(): bool
    {
        return $this->forceFill(['iotEnabled' => false])->save();
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

    /**
     * Per-request zone snapshot written by the monitoring/geofence controllers
     * (`$bike->zone = $service->checkPoint(...)`), falling back to "unknown"
     * when nobody wrote one.
     *
     * The fallback matters because the accessor otherwise shadows the assigned
     * value: without reading the stored attribute first, every row would
     * permanently read as unknown/no-GPS no matter what the controllers
     * computed.
     */
    public function getZoneAttribute(): array
    {
        $stored = $this->attributes['zone'] ?? null;

        return is_array($stored)
            ? $stored
            : ['inside' => null, 'distance' => null, 'level' => 'unknown', 'warning' => false];
    }
}
