<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Central audit-trail writer for security-sensitive administrative and system
 * changes. Every covered operation funnels through here (or through
 * AuditLog::record(), which applies the same scrubbing) so each event is
 * recorded exactly once with the real actor, timestamp, affected entity,
 * outcome and relevant context.
 *
 * Conventions for the details payload (also honored by the Audit Logs page):
 * - `outcome`: 'success' | 'failed'
 * - `entity` / entity id keys (e.g. riderId, bicycleId, rentalId)
 * - `changes`: [field => ['from' => ..., 'to' => ...]] for before/after values
 * - `email`: attempted address for authentication events without a user row
 * - `ip_address`, `http_method`, `http_path`: captured automatically when an
 *   HTTP request is in flight; `request_id` correlates every event raised
 *   while handling the same request.
 *
 * Category drives the Audit Logs (security) vs Activity Logs (operational)
 * split; both read the same audit_logs table through this service.
 */
final class AuditLogger
{
    public const CATEGORY_SECURITY = 'security';
    public const CATEGORY_OPERATIONAL = 'operational';

    // Authentication.
    public const LOGIN_SUCCESS = 'login_success';
    public const LOGIN_FAILED = 'login_failed';
    public const LOGIN_BLOCKED = 'login_blocked';
    public const LOGOUT = 'logout';

    // Customer lifecycle.
    public const RIDER_CREATED = 'rider_created';
    public const RIDER_VERIFICATION_APPROVED = 'rider_verification_approved';
    public const RIDER_VERIFICATION_REJECTED = 'rider_verification_rejected';
    public const RIDER_STATUS_UPDATED = 'rider_status_updated';
    public const RIDER_BLACKLIST_UPDATED = 'rider_blacklist_updated';
    public const RIDER_DELETED = 'rider_deleted';

    // Bicycles and smart locks.
    public const BICYCLE_CREATED = 'bicycle_created';
    public const BICYCLE_UPDATED = 'bicycle_updated';
    public const BICYCLE_REMOVED = 'bicycle_removed';
    public const BICYCLE_LOCK_LOCK = 'bicycle_lock_lock';
    public const BICYCLE_LOCK_UNLOCK = 'bicycle_lock_unlock';
    public const LOCK_COMMAND_EXECUTED = 'lock_command_executed';
    public const LOCK_COMMAND_FAILED = 'lock_command_failed';

    // Rentals and money.
    public const RENTAL_APPROVED = 'rental_approved';
    public const GCASH_PAYMENT_VERIFIED = 'gcash_payment_verified';
    public const RENTAL_MARKED_PAID = 'rental_marked_paid';
    public const RENTAL_ENDED_BY_ADMIN = 'rental_ended_by_admin';
    public const RENTAL_RETURN_PROCESSED = 'rental_return_processed';
    public const RENTAL_DEADLINE_SET = 'rental_deadline_set';
    public const RENTAL_DEADLINE_CHANGED = 'rental_deadline_changed';
    public const RENTAL_CANCELLED = 'rental_cancelled';

    // Maintenance and incident triage (routine operations, not security).
    public const MAINTENANCE_CREATED = 'maintenance_created';
    public const MAINTENANCE_UPDATED = 'maintenance_updated';
    public const MAINTENANCE_STATUS_UPDATED = 'maintenance_status_updated';
    public const BICYCLE_MAINTENANCE_AUTO = 'bicycle_maintenance_auto';
    public const ACCIDENT_ACKNOWLEDGED = 'accident_acknowledged';
    public const THEFT_ALERT_ACKNOWLEDGED = 'theft_alert_acknowledged';

    // Configuration.
    public const SYSTEM_SETTINGS_UPDATED = 'system_settings_updated';
    public const GEOFENCE_UPDATED = 'geofence_updated';

    /**
     * Canonical operational list. Unknown actions default to security so a
     * new event type is never silently hidden from the accountability view.
     * Duplicated (not referenced) in the category migration so old migrations
     * keep working if this mapping ever changes.
     */
    public const OPERATIONAL_ACTIONS = [
        self::MAINTENANCE_CREATED,
        self::MAINTENANCE_UPDATED,
        self::MAINTENANCE_STATUS_UPDATED,
        self::BICYCLE_MAINTENANCE_AUTO,
        self::ACCIDENT_ACKNOWLEDGED,
        self::THEFT_ALERT_ACKNOWLEDGED,
    ];

    /**
     * Detail keys that must never persist (passwords, tokens, secrets, device
     * credentials). Matched case-insensitively against the alphanumeric core
     * of each key so `deviceApiKey`, `csrf_token` and friends are caught too.
     */
    public const SENSITIVE_KEYS = [
        'password',
        'passwd',
        'secret',
        'token',
        'apikey',
        'privatekey',
    ];

    public static function categoryFor(string $action): string
    {
        return in_array($action, self::OPERATIONAL_ACTIONS, true)
            ? self::CATEGORY_OPERATIONAL
            : self::CATEGORY_SECURITY;
    }

    /**
     * Record one audit event. The timestamp is stamped by the model; the
     * category is derived from the action; sensitive values are scrubbed.
     */
    public static function record(string $action, array $details = [], ?int $userId = null): AuditLog
    {
        return AuditLog::create([
            'action' => $action,
            'userId' => $userId ?? auth()->id(),
            'details' => self::scrub($details),
            'category' => self::categoryFor($action),
        ]);
    }

    /**
     * Request context merged into every record (explicit detail keys always
     * win). The correlation id is stable for the lifetime of one HTTP request
     * object so all events from the same request share it; entries are pruned
     * as their requests are garbage-collected.
     *
     * @var array<string, array{request: \WeakReference, id: string}>
     */
    protected static array $correlationIds = [];

    /** @return array<string, string> */
    public static function requestContext(): array
    {
        try {
            $request = request();
        } catch (\Throwable) {
            return [];
        }

        $context = [];

        try {
            $ip = $request->ip();
            if (is_string($ip) && $ip !== '') {
                $context['ip_address'] = $ip;
            }
            $context['http_method'] = strtoupper((string) $request->method());
            $context['http_path'] = '/' . ltrim((string) $request->path(), '/');
        } catch (\Throwable) {
            // Console and queue contexts may not carry a meaningful request.
        }

        $context['request_id'] = self::correlationId($request);

        return $context;
    }

    private static function correlationId(object $request): string
    {
        $hash = spl_object_hash($request);

        // Drop entries whose request object no longer exists so the map stays
        // bounded in long-lived processes, then reuse the live entry.
        foreach (self::$correlationIds as $key => $entry) {
            if ($entry['request']->get() === null) {
                unset(self::$correlationIds[$key]);
            }
        }

        if (isset(self::$correlationIds[$hash])
            && self::$correlationIds[$hash]['request']->get() === $request) {
            return self::$correlationIds[$hash]['id'];
        }

        $id = (string) \Illuminate\Support\Str::uuid();
        self::$correlationIds[$hash] = [
            'request' => \WeakReference::create($request),
            'id' => $id,
        ];

        return $id;
    }

    /**
     * Build a before/after map for the fields that actually changed. Only
     * scalar (or null) values are kept; secrets are never included.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function changes(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $field => $to) {
            if (!array_key_exists($field, $before)) {
                continue;
            }
            $from = $before[$field];
            if (!self::isScalarOrNull($from) || !self::isScalarOrNull($to)) {
                continue;
            }
            if ((string) $from === (string) $to) {
                continue;
            }
            if (self::isSensitiveKey((string) $field)) {
                continue;
            }
            $changes[$field] = ['from' => $from, 'to' => $to];
        }

        return $changes;
    }

    /** Recursively replace sensitive values with a redaction marker. */
    public static function scrub(array $details): array
    {
        foreach ($details as $key => $value) {
            if (is_array($value)) {
                $details[$key] = self::scrub($value);
            } elseif (self::isSensitiveKey((string) $key)) {
                $details[$key] = '[redacted]';
            }
        }

        return $details;
    }

    public static function isSensitiveKey(string $key): bool
    {
        $core = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($core !== '' && str_contains($core, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private static function isScalarOrNull(mixed $value): bool
    {
        return $value === null || is_scalar($value);
    }

    /**
     * Canonical action lists for the Action Type filter, grouped for display.
     * Actions recorded in the database but absent here are appended by the
     * controller so new event types appear without code changes.
     *
     * @return array<string, list<string>>
     */
    public static function groupedActions(): array
    {
        return [
            'Security events' => [
                self::LOGIN_SUCCESS,
                self::LOGIN_FAILED,
                self::LOGIN_BLOCKED,
                self::LOGOUT,
                self::RIDER_CREATED,
                self::RIDER_VERIFICATION_APPROVED,
                self::RIDER_VERIFICATION_REJECTED,
                self::RIDER_STATUS_UPDATED,
                self::RIDER_BLACKLIST_UPDATED,
                self::RIDER_DELETED,
                self::BICYCLE_CREATED,
                self::BICYCLE_UPDATED,
                self::BICYCLE_REMOVED,
                self::BICYCLE_LOCK_LOCK,
                self::BICYCLE_LOCK_UNLOCK,
                self::LOCK_COMMAND_EXECUTED,
                self::LOCK_COMMAND_FAILED,
                self::RENTAL_APPROVED,
                self::GCASH_PAYMENT_VERIFIED,
                self::RENTAL_MARKED_PAID,
                self::RENTAL_ENDED_BY_ADMIN,
                self::RENTAL_RETURN_PROCESSED,
                self::RENTAL_DEADLINE_SET,
                self::RENTAL_DEADLINE_CHANGED,
                self::RENTAL_CANCELLED,
                self::SYSTEM_SETTINGS_UPDATED,
                self::GEOFENCE_UPDATED,
            ],
            'Operational events' => self::OPERATIONAL_ACTIONS,
        ];
    }

    /** Resolve a display name for an actor, preferring the real user. */
    public static function actorName(?User $user, array $details = []): string
    {
        if ($user) {
            return $user->name;
        }

        $email = $details['email'] ?? $details['actor_email'] ?? null;
        if (is_string($email) && $email !== '') {
            return $email;
        }

        return 'System';
    }
}
