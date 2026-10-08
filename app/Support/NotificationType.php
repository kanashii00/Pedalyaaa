<?php

namespace App\Support;

/**
 * Canonical presentation metadata for notification types.
 *
 * Every notification type that can reach the notifications table is registered
 * here once, so the admin list and the rider list always render the same label,
 * badge, icon and filter group for a given type. Types that are not registered
 * (for example rows written by an older release) fall back to a readable,
 * neutral rendering instead of leaking raw snake_case text.
 */
final class NotificationType
{
    public const GROUP_RENTAL = 'rental';

    public const GROUP_SYSTEM = 'system';

    /**
     * type => [label, badge variant, icon, filter group]
     */
    private const MAP = [
        // Types an administrator can pick when sending by hand.
        'info'                  => ['Info', 'info', 'bi-info-circle', self::GROUP_SYSTEM],
        'success'               => ['Success', 'success', 'bi-check-circle', self::GROUP_SYSTEM],
        'warning'               => ['Warning', 'warning', 'bi-exclamation-triangle', self::GROUP_SYSTEM],
        'error'                 => ['Error', 'danger', 'bi-x-circle', self::GROUP_SYSTEM],
        'general'               => ['General', 'neutral', 'bi-bell', self::GROUP_SYSTEM],

        // Types raised by the system triggers.
        'system'                => ['System', 'neutral', 'bi-cpu', self::GROUP_SYSTEM],
        'maintenance'           => ['Maintenance', 'warning', 'bi-tools', self::GROUP_SYSTEM],
        'device_inactive'       => ['Device Inactive', 'warning', 'bi-router', self::GROUP_SYSTEM],
        'geofence_alert'        => ['Geofence Alert', 'warning', 'bi-geo-alt', self::GROUP_SYSTEM],
        'theft'                 => ['Theft', 'danger', 'bi-shield-exclamation', self::GROUP_SYSTEM],
        'verification_approved' => ['Verification Approved', 'success', 'bi-patch-check', self::GROUP_SYSTEM],
        'verification_rejected' => ['Verification Rejected', 'danger', 'bi-patch-exclamation', self::GROUP_SYSTEM],

        // Rental lifecycle + money, shared by the rental triggers.
        'rental_started'        => ['Rental Started', 'info', 'bi-play-circle', self::GROUP_RENTAL],
        'rental_approved'       => ['Rental Approved', 'success', 'bi-check2-circle', self::GROUP_RENTAL],
        'rental_returned'       => ['Rental Returned', 'info', 'bi-arrow-return-left', self::GROUP_RENTAL],
        'rental_completed'      => ['Rental Completed', 'success', 'bi-check2-all', self::GROUP_RENTAL],
        'rental_cancelled'      => ['Rental Cancelled', 'danger', 'bi-x-octagon', self::GROUP_RENTAL],
        'rental_deadline'       => ['Return Deadline', 'warning', 'bi-alarm', self::GROUP_RENTAL],
        'rental_warning'        => ['Rental Warning', 'warning', 'bi-hourglass-split', self::GROUP_RENTAL],
        'rental_expired'        => ['Rental Expired', 'danger', 'bi-hourglass-bottom', self::GROUP_RENTAL],
        'rental_extended'       => ['Ride Extended', 'info', 'bi-plus-circle', self::GROUP_RENTAL],
        'rental_overdue_return' => ['Overdue Return', 'danger', 'bi-exclamation-octagon', self::GROUP_RENTAL],
        'rental_status'         => ['Rental Status', 'info', 'bi-arrow-repeat', self::GROUP_RENTAL],
        'return_requested'      => ['Return Requested', 'warning', 'bi-arrow-return-right', self::GROUP_RENTAL],
        'payment_status'        => ['Payment Status', 'info', 'bi-cash-coin', self::GROUP_RENTAL],
    ];

    public static function label(?string $type): string
    {
        $type = (string) $type;

        if (isset(self::MAP[$type])) {
            return self::MAP[$type][0];
        }

        return ucfirst(str_replace('_', ' ', $type));
    }

    public static function badge(?string $type): string
    {
        $type = (string) $type;

        return self::MAP[$type][1] ?? 'neutral';
    }

    public static function icon(?string $type): string
    {
        $type = (string) $type;

        return self::MAP[$type][2] ?? 'bi-bell';
    }

    public static function group(?string $type): string
    {
        $type = (string) $type;

        return self::MAP[$type][3] ?? self::GROUP_SYSTEM;
    }

    /**
     * Accent colours per badge variant. These mirror the semantic tokens of the
     * admin design system (public/css/admin.css) as literal hex values so the
     * same markup renders identically inside and outside the admin shell.
     *
     * @var array<string, string>
     */
    private const ACCENTS = [
        'success' => '#16A34A',
        'warning' => '#D97706',
        'danger'  => '#DC2626',
        'info'    => '#0EA5E9',
        'neutral' => '#2563EB',
    ];

    /** Soft tile backgrounds per badge variant. */
    private const TILE_BACKGROUNDS = [
        'success' => '#ECFDF3',
        'warning' => '#FFFBEB',
        'danger'  => '#FEF2F2',
        'info'    => '#F0F9FF',
        'neutral' => '#EFF6FF',
    ];

    /**
     * Icon tile styling for the notification list and the header dropdown.
     *
     * @return array{background: string, color: string}
     */
    public static function tileColors(?string $type): array
    {
        $variant = self::badge($type);

        return [
            'background' => self::TILE_BACKGROUNDS[$variant] ?? self::TILE_BACKGROUNDS['neutral'],
            'color' => self::ACCENTS[$variant] ?? self::ACCENTS['neutral'],
        ];
    }

    /** Accent colour for the icon of a notification type. */
    public static function accent(?string $type): string
    {
        return self::ACCENTS[self::badge($type)] ?? self::ACCENTS['neutral'];
    }
}