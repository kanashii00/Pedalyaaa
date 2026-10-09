<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Silent "rental data changed" nudge for open admin dashboards.
 *
 * Unlike NotificationCreated this carries no toast and creates no rows: it
 * only tells listening admin shells to re-read the authoritative rental feed
 * (counts + tables) so Active Rentals, Returns and the dashboard KPIs stay
 * current without a page reload. Clients always refetch from the backend, so
 * the payload is just a timestamp and the database stays the source of truth.
 * Broadcast synchronously so no queue worker is required for live updates.
 */
class RentalFeedUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @return array<int, \Illuminate\Broadcasting\Channel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('rentals-feed'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'rental.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'updatedAt' => now()->toIso8601String(),
        ];
    }
}
