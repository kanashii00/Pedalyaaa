<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Services\NotificationService;
use App\Support\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * Locks the rider notifications section to the same data, status codes and
 * messages as the admin section (see AdminNotificationTest for the admin side).
 */
class RiderNotificationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'log']);
        Cache::flush();
    }

    private function riderAuth()
    {
        $rider = $this->makeRider();
        $this->actingAs($rider);

        return $rider;
    }

    public function test_unread_count_matches_the_shared_service_value(): void
    {
        $rider = $this->riderAuth();
        $service = app(NotificationService::class);
        $service->create($rider->id, 'New Rental', 'A bike was rented.', 'rental_started');
        $service->create($rider->id, 'Alert', 'Geofence breach.', 'geofence_alert');

        $this->getJson(route('rider.notifications.unread-count'))
            ->assertOk()
            ->assertJson(['unread_count' => 2]);
    }

    public function test_mark_read_returns_the_same_message_and_status_as_admin(): void
    {
        $rider = $this->riderAuth();
        $notification = Notification::create([
            'userId' => $rider->id,
            'title' => 'Rental Approved',
            'message' => 'Your rental has been approved.',
            'type' => 'rental_approved',
            'read' => false,
        ]);

        $this->postJson(route('rider.notifications.mark-read', $notification->id))
            ->assertOk()
            ->assertJson(['message' => 'Notification marked as read.']);

        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'read' => true]);
    }

    public function test_mark_read_reports_404_for_someone_elses_notification(): void
    {
        $rider = $this->riderAuth();
        $other = $this->makeRider();
        $foreign = Notification::create([
            'userId' => $other->id,
            'title' => 'Foreign',
            'message' => 'm',
            'read' => false,
        ]);

        $this->postJson(route('rider.notifications.mark-read', $foreign->id))
            ->assertStatus(404)
            ->assertJson(['message' => 'Notification not found.']);

        $this->assertDatabaseHas('notifications', ['id' => $foreign->id, 'read' => false]);
    }

    public function test_plain_form_post_flashes_the_same_message_instead_of_json(): void
    {
        $rider = $this->riderAuth();
        $notification = Notification::create([
            'userId' => $rider->id,
            'title' => 'Notice',
            'message' => 'm',
            'read' => false,
        ]);

        $this->post(route('rider.notifications.mark-read', $notification->id))
            ->assertRedirect()
            ->assertSessionHas('success', 'Notification marked as read.');
    }

    public function test_mark_all_read_reports_the_same_count_based_message_as_admin(): void
    {
        $rider = $this->riderAuth();
        Notification::create(['userId' => $rider->id, 'title' => 'A', 'message' => 'm', 'type' => 'info', 'read' => false]);
        Notification::create(['userId' => $rider->id, 'title' => 'B', 'message' => 'm', 'type' => 'warning', 'read' => false]);

        $this->postJson(route('rider.notifications.mark-all-read'))
            ->assertOk()
            ->assertJson(['message' => '2 notification(s) marked as read.', 'unread_count' => 0]);

        $this->postJson(route('rider.notifications.mark-all-read'))
            ->assertOk()
            ->assertJson(['message' => 'No unread notifications.']);
    }

    public function test_mark_all_read_only_touches_the_signed_in_rider(): void
    {
        $rider = $this->riderAuth();
        $other = $this->makeRider();
        $foreign = Notification::create(['userId' => $other->id, 'title' => 'Foreign', 'message' => 'm', 'read' => false]);
        $mine = Notification::create(['userId' => $rider->id, 'title' => 'Mine', 'message' => 'm', 'read' => false]);

        $this->postJson(route('rider.notifications.mark-all-read'))->assertOk();

        $this->assertDatabaseHas('notifications', ['id' => $mine->id, 'read' => true]);
        $this->assertDatabaseHas('notifications', ['id' => $foreign->id, 'read' => false]);
    }

    public function test_index_and_table_partial_render_the_same_alert_data(): void
    {
        $rider = $this->riderAuth();
        Notification::create([
            'userId' => $rider->id,
            'title' => 'Geofence Alert',
            'message' => 'Return to the riding zone.',
            'type' => 'geofence_alert',
            'read' => false,
        ]);

        $this->get(route('rider.notifications.index'))
            ->assertOk()
            ->assertSee('Geofence Alert')
            ->assertSee(NotificationType::label('geofence_alert'))
            ->assertSee('Return to the riding zone.');

        $this->getJson(route('rider.notifications.table'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('Geofence Alert');
    }

    public function test_notification_messages_are_escaped(): void
    {
        $rider = $this->riderAuth();
        Notification::create([
            'userId' => $rider->id,
            'title' => 'Notice',
            'message' => '<script>alert(1)</script>',
            'type' => 'info',
            'read' => false,
        ]);

        $this->get(route('rider.notifications.index'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_header_dropdown_matches_the_admin_dropdown_and_stays_scoped(): void
    {
        $rider = $this->riderAuth();
        $other = $this->makeRider();

        Notification::create([
            'userId' => $rider->id,
            'title' => 'Rental Approved',
            'message' => 'Collect your bike from Bay 3.',
            'type' => 'rental_approved',
            'read' => false,
        ]);
        Notification::create([
            'userId' => $other->id,
            'title' => 'Someone Elses Rental',
            'message' => 'Must never render for this rider.',
            'type' => 'rental_approved',
            'read' => false,
        ]);

        $response = $this->get(route('rider.dashboard'))->assertOk();

        // Same dropdown contract the admin shell renders.
        $response->assertSee('admin-dropdown__menu', false);
        $response->assertSee('id="notifUnreadLabel"', false);
        $response->assertSee('data-notif-unread-label', false);
        $response->assertSee('data-mark-all-read', false);
        $response->assertSee('View all notifications');
        $response->assertSee('1 unread');

        // Own notification is listed with the shared type metadata.
        $response->assertSee('Rental Approved');
        $response->assertSee(NotificationType::label('rental_approved'));
        $response->assertSee(NotificationType::icon('rental_approved'), false);

        // Another rider's notification is never rendered.
        $response->assertDontSee('Someone Elses Rental');
        $response->assertDontSee('Must never render for this rider.');

        $response->assertSee('js/notifications.js', false);
        // @json() escapes slashes, so compare the encoded form.
        $response->assertSee(json_encode(route('rider.notifications.mark-all-read')), false);
        $response->assertSee(json_encode(route('rider.notifications.unread-count')), false);
        $response->assertSee('private-App.Models.User.'.$rider->id, false);
    }

    public function test_dropdown_shows_the_shared_empty_state_without_notifications(): void
    {
        $this->riderAuth();

        $this->get(route('rider.dashboard'))
            ->assertOk()
            ->assertSee('All caught up', false)
            ->assertSee('No notifications yet.')
            ->assertSee('0 unread');
    }

    public function test_recent_helper_only_returns_the_owning_users_notifications(): void
    {
        $rider = $this->riderAuth();
        $other = $this->makeRider();

        Notification::create(['userId' => $other->id, 'title' => 'Foreign', 'message' => 'm', 'type' => 'info', 'read' => false]);
        Notification::create(['userId' => $rider->id, 'title' => 'Mine', 'message' => 'm', 'type' => 'info', 'read' => false]);

        $recent = app(NotificationService::class)->getRecentForUser($rider->id);

        $this->assertCount(1, $recent);
        $this->assertSame('Mine', $recent->first()->title);
    }

    public function test_rider_routes_reject_non_riders(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->get(route('rider.notifications.index'))
            ->assertRedirect()
            ->assertSessionHasErrors('role');

        $notification = Notification::create([
            'userId' => $this->makeRider()->id,
            'title' => 'Notice',
            'message' => 'm',
            'read' => false,
        ]);

        $this->postJson(route('rider.notifications.mark-read', $notification->id))->assertForbidden();
        $this->postJson(route('rider.notifications.mark-all-read'))->assertForbidden();
    }
}