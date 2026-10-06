<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time "a new notification arrived" signal for the header dropdown.
 *
 * Broadcast on the recipient's own private channel so an admin and a rider can
 * share the exact same client runtime without either one being able to listen
 * to somebody else's notifications. Broadcast synchronously: the payload only
 * refreshes a badge and shows a toast, so a queued worker must not be required
 * for the dropdown to stay current.
 */
class NotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Notification $notification)
    {
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->notification->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'type' => $this->notification->type,
            'title' => $this->notification->title,
            'message' => $this->notification->message,
            'read' => (bool) $this->notification->read,
            'rentalId' => $this->notification->rentalId,
            'bicycleId' => $this->notification->bicycleId,
            'created_at' => $this->notification->created_at?->toIso8601String(),
        ];
    }
}