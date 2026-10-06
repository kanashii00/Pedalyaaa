<?php

namespace Tests\Feature;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * The header dropdown in both shells relies on the private per-user Echo
 * channel. These tests lock down that a notification is broadcast to its
 * owner only, and that a user cannot subscribe to somebody else's channel.
 *
 * The channel authorization test runs with the "pusher" broadcaster selected
 * before the application boots: channel patterns in routes/channels.php are
 * registered on the default broadcaster instance, and the log broadcaster used
 * by the rest of the suite never authorizes anything.
 */
class NotificationBroadcastTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->booting(function ($app) {
            $app['config']->set('broadcasting.default', 'pusher');
            $app['config']->set('broadcasting.connections.pusher', [
                'driver' => 'pusher',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'app_id' => 'test-app',
                'options' => [
                    'cluster' => 'mt1',
                    'host' => '127.0.0.1',
                    'port' => 6001,
                    'useTLS' => false,
                ],
            ]);
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_creating_a_notification_dispatches_the_broadcast_event(): void
    {
        Event::fake([NotificationCreated::class]);

        $rider = $this->makeRider();
        app(NotificationService::class)->create($rider->id, 'Rental Started', 'Ride safely.', 'rental_started');

        Event::assertDispatched(
            NotificationCreated::class,
            fn ($event) => $event->notification->userId === $rider->id
        );
    }

    public function test_event_targets_the_owners_private_channel_with_the_expected_payload(): void
    {
        $rider = $this->makeRider();
        $notification = Notification::create([
            'userId' => $rider->id,
            'title' => 'Geofence Alert',
            'message' => 'Return to the zone.',
            'type' => 'geofence_alert',
            'read' => false,
        ]);

        $event = new NotificationCreated($notification);

        $this->assertSame('notification.created', $event->broadcastAs());
        $this->assertSame(
            'private-App.Models.User.'.$rider->id,
            $event->broadcastOn()[0]->name
        );

        $payload = $event->broadcastWith();
        $this->assertSame($notification->id, $payload['id']);
        $this->assertSame('Geofence Alert', $payload['title']);
        $this->assertSame('Return to the zone.', $payload['message']);
        $this->assertSame('geofence_alert', $payload['type']);
        $this->assertFalse($payload['read']);
        $this->assertNotNull($payload['created_at']);
    }

    public function test_channel_authorizes_the_owner_and_rejects_everyone_else(): void
    {
        $rider = $this->makeRider();
        $other = $this->makeRider();

        $this->actingAs($rider);

        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-App.Models.User.'.$rider->id,
            'socket_id' => '1234.5678',
        ])->assertOk();

        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-App.Models.User.'.$other->id,
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }

    public function test_broadcast_auth_requires_an_authenticated_session(): void
    {
        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-App.Models.User.'.User::ROLE_ADMIN,
            'socket_id' => '1234.5678',
        ])->assertUnauthorized();
    }
}