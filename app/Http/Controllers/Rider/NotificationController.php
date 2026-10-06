<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Concerns\HandlesNotifications;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Rider half of the notifications feature. Every bit of behaviour lives in the
 * shared HandlesNotifications trait so this section reports the same list data,
 * unread count, status codes and messages as the admin section.
 */
class NotificationController extends Controller
{
    use HandlesNotifications;

    public function index(Request $request): View
    {
        $userId = $request->user()->id;

        $notifications = $this->notificationList($userId);
        $unreadCount = $this->notificationUnreadCount($userId);

        return view('rider.notifications', compact('notifications', 'unreadCount'));
    }

    /**
     * Returns just the notification list (HTML) so the page can refresh it
     * after a mark-as-read without a full page reload, mirroring the admin
     * table partial endpoint.
     */
    public function table(Request $request): View
    {
        $notifications = $this->notificationList($request->user()->id);

        return view('rider.notifications-list', compact('notifications'));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->unreadCountResponse($request);
    }

    public function markRead(Request $request, int $id): JsonResponse|RedirectResponse
    {
        return $this->markReadResponse($request, $id);
    }

    public function markAllRead(Request $request): JsonResponse|RedirectResponse
    {
        return $this->markAllReadResponse($request);
    }
}