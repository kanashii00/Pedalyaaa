<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Single source of truth for the notifications feature.
 *
 * The admin and the rider sections read their list data, unread count, response
 * status codes and user facing messages from here, so the two screens cannot
 * drift apart: marking a notification read, marking everything read, or reading
 * the badge count behaves identically in both sections.
 */
trait HandlesNotifications
{
    /** Page size shared by every notification list. */
    protected int $notificationPerPage = 20;

    /**
     * Notifications, newest first. Passing a user id scopes the list to that
     * user's notifications; passing null returns the full list (admin view).
     * Query strings are preserved so filters survive pagination.
     */
    protected function notificationList(?int $userId = null): LengthAwarePaginator
    {
        $query = Notification::with('user');

        if ($userId !== null) {
            $query->where('userId', $userId);
        }

        return $query->latest()->paginate($this->notificationPerPage)->withQueryString();
    }

    /** Unread count for a user, straight from the shared (cached) service. */
    protected function notificationUnreadCount(int $userId): int
    {
        return app(NotificationService::class)->getUnreadCount($userId);
    }

    protected function unreadCountResponse(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $this->notificationUnreadCount($request->user()->id),
        ]);
    }

    /**
     * Marks one of the caller's own notifications as read. A notification that
     * does not exist, or belongs to somebody else, is a 404 in both sections.
     */
    protected function markReadResponse(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $marked = app(NotificationService::class)->markAsRead($id, $request->user()->id);

        return $marked
            ? $this->notificationRespond($request, 'Notification marked as read.', [], 200, back()->with('success', 'Notification marked as read.'))
            : $this->notificationRespond($request, 'Notification not found.', [], 404, back()->with('error', 'Notification not found.'));
    }

    /**
     * Marks every unread notification of the caller as read. The count is
     * reported back so both sections can show the same message.
     */
    protected function markAllReadResponse(Request $request): JsonResponse|RedirectResponse
    {
        $updated = app(NotificationService::class)->markAllAsRead($request->user()->id);
        $message = $updated > 0
            ? $updated.' notification(s) marked as read.'
            : 'No unread notifications.';

        return $this->notificationRespond(
            $request,
            $message,
            ['unread_count' => 0],
            200,
            back()->with('success', $message)
        );
    }

    /**
     * Keep a single consistent success/failure response for both AJAX and
     * non-AJAX callers so the browser never displays a raw JSON body.
     */
    protected function notificationRespond(
        Request $request,
        string $message,
        array $extra = [],
        int $status = 200,
        ?RedirectResponse $fallback = null
    ): JsonResponse|RedirectResponse {
        if ($request->wantsJson()) {
            return response()->json(array_merge(['message' => $message], $extra), $status);
        }

        return $fallback ?? back()->with('error', $message);
    }
}