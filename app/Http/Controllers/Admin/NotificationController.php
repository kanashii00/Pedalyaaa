<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HandlesNotifications;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    use HandlesNotifications;

    public function __construct(
        private NotificationService $notificationService
    ) {}

    public function index(): Response
    {
        $notifications = $this->notificationList();
        $users = User::where('role', User::ROLE_RIDER)->orderBy('name')->get();
        $unreadCount = $this->notificationUnreadCount(auth()->id());

        return response()->view('admin.notifications', compact('notifications', 'users', 'unreadCount'));
    }

    /**
     * Returns just the notification table (HTML) so the Notifications page can
     * refresh it after a send without a full page reload.
     */
    public function table(Request $request): Response
    {
        $notifications = $this->notificationList();

        return response()->view('admin.notifications-table', compact('notifications'));
    }

    /**
     * Store a notification sent by an administrator.
     *
     * AJAX/fetch requests get a clean JSON result; plain form posts are
     * redirected back with a session flash so the browser never lands on a raw
     * JSON payload. Validation failures render inside the existing UI via the
     * standard invalid-feedback markup (or a JSON 422 for AJAX callers).
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate($this->rules(), $this->messages());

        $userIds = $this->resolveRecipients($validated['recipientType'], $validated);

        if (empty($userIds)) {
            return $this->notificationRespond(
                $request,
                'No recipients match this selection.',
                [],
                422,
                back()->withErrors(['recipientType' => 'No recipients match this selection.'])->withInput()
            );
        }

        if ($this->isDuplicate($userIds, $validated['title'], $validated['message'], $validated['type'])) {
            return $this->notificationRespond(
                $request,
                'This notification was already sent. Please check the Notifications list before sending again.',
                [],
                409,
                back()->with('error', 'This notification was already sent. Please check the Notifications list before sending again.')->withInput()
            );
        }

        $count = $this->notificationService
            ->createForUsers($userIds, $validated['title'], $validated['message'], $validated['type'])
            ->count();

        return $this->notificationRespond(
            $request,
            'Notification sent successfully.',
            ['count' => $count],
            200,
            back()->with('success', "Notification sent successfully to {$count} recipient(s).")
        );
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

    private function rules(): array
    {
        $riderRule = Rule::exists('users', 'id')->where('role', User::ROLE_RIDER);

        return [
            'title'          => ['required', 'string', 'max:255'],
            'message'        => ['required', 'string', 'max:1000'],
            'type'           => ['required', 'string', 'in:info,warning,error,success,general'],
            'recipientType'  => ['required', 'string', 'in:broadcast,single,specific,multi'],
            'user_id'        => ['required_if:recipientType,single,specific', 'nullable', 'integer', $riderRule],
            'user_ids'       => ['required_if:recipientType,multi', 'nullable', 'array', 'min:1', 'max:100'],
            'user_ids.*'     => ['integer', $riderRule],
        ];
    }

    private function messages(): array
    {
        return [
            'title.required'         => 'Please enter a title.',
            'title.max'              => 'The title must not exceed 255 characters.',
            'message.required'       => 'Please write a message.',
            'message.max'            => 'The message must not exceed 1000 characters.',
            'type.required'          => 'Please select a notification type.',
            'type.in'                => 'Please select a valid notification type.',
            'recipientType.required' => 'Please choose who should receive this notification.',
            'recipientType.in'       => 'Please choose a valid recipient option.',
            'user_id.required_if'    => 'Please select a user to send to.',
            'user_id.exists'         => 'The selected recipient is invalid.',
            'user_ids.required_if'   => 'Please select at least one recipient.',
            'user_ids.min'           => 'Please select at least one recipient.',
            'user_ids.max'           => 'You can select at most 100 recipients at once.',
            'user_ids.*.exists'      => 'One or more selected recipients are invalid.',
        ];
    }

    /**
     * Resolve the concrete user ids for the chosen recipient type. Broadcast
     * targets every registered rider, single/specific targets exactly one user,
     * and multi targets only the selected users.
     */
    private function resolveRecipients(string $recipientType, array $validated): array
    {
        if ($recipientType === 'broadcast') {
            return User::where('role', User::ROLE_RIDER)->pluck('id')->all();
        }

        if (in_array($recipientType, ['single', 'specific'], true)) {
            return [(int) $validated['user_id']];
        }

        return array_map('intval', $validated['user_ids'] ?? []);
    }

    /**
     * Prevent duplicates from a double-clicked Send button: if the exact same
     * notification was delivered to any of these recipients within the last
     * minute, treat the request as a duplicate.
     */
    private function isDuplicate(array $userIds, string $title, string $message, string $type): bool
    {
        if (empty($userIds)) {
            return false;
        }

        return Notification::whereIn('userId', $userIds)
            ->where('title', $title)
            ->where('message', $message)
            ->where('type', $type)
            ->where('created_at', '>=', now()->subMinute())
            ->exists();
    }
}