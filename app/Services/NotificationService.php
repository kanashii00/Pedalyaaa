<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Services\CacheRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

class NotificationService
{
    public function create(int $userId, string $title, string $message, string $type, array $extra = []): Notification
    {
        CacheRegistry::bumpUserVersion($userId);

        $notification = Notification::create(array_merge([
            'userId' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'read' => false,
        ], $extra));

        // Live dropdown updates are best effort: the row above is already
        // persisted, so an unreachable broadcaster must never fail the request
        // that created the notification.
        try {
            NotificationCreated::dispatch($notification);
        } catch (Throwable $e) {
            report($e);
        }

        return $notification;
    }

    public function createForUsers(array $userIds, string $title, string $message, string $type, array $extra = []): Collection
    {
        $notifications = collect();

        foreach ($userIds as $userId) {
            $notifications->push($this->create($userId, $title, $message, $type, $extra));
        }

        return $notifications;
    }

    public function markAsRead(int $notificationId, int $userId): bool
    {
        $notification = Notification::where('id', $notificationId)
            ->where('userId', $userId)
            ->first();

        if (!$notification) {
            return false;
        }

        $notification->update(['read' => true, 'readAt' => now()]);

        CacheRegistry::bumpUserVersion($userId);

        return true;
    }

    public function markAllAsRead(int $userId): int
    {
        $updated = Notification::where('userId', $userId)
            ->where('read', false)
            ->update(['read' => true, 'readAt' => now()]);

        if ($updated > 0) {
            CacheRegistry::bumpUserVersion($userId);
        }

        return $updated;
    }

    /**
     * Unread count, cached briefly per user. Invalidated via the user version
     * counter whenever notifications are created, read or removed.
     */
    public function getUnreadCount(int $userId): int
    {
        $key = CacheRegistry::unreadCountKey($userId);

        return (int) Cache::remember($key, CacheRegistry::TTL_UNREAD_COUNT, function () use ($userId) {
            return Notification::where('userId', $userId)
                ->where('read', false)
                ->count();
        });
    }

    public function getUnreadForUser(int $userId): Collection
    {
        return Notification::where('userId', $userId)
            ->where('read', false)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Most recent notifications for the header dropdown. Shared by the admin and
     * rider shells so both menus show the same rows for the signed-in user.
     */
    public function getRecentForUser(int $userId, int $limit = 6): Collection
    {
        return Notification::with('bicycle')
            ->where('userId', $userId)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();
    }
}
