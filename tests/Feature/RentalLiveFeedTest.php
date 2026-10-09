<?php

namespace Tests\Feature;

use App\Events\RentalFeedUpdated;
use App\Models\Bicycle;
use App\Models\Rental;
use App\Models\User;
use App\Services\RentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * The admin live sync reads one versioned feed: cheap counters polled or
 * pushed over WebSockets, with tables re-rendered only when the version
 * moves. These tests pin the feed to the same aggregates the pages render
 * through the full rental-to-return lifecycle.
 */
class RentalLiveFeedTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function feedAs(User $user)
    {
        return $this->actingAs($user)->getJson(route('admin.rentals.feed'));
    }

    public function test_feed_requires_admin(): void
    {
        $rider = $this->makeRider();

        $this->getJson(route('admin.rentals.feed'))->assertUnauthorized();
        $this->feedAs($rider)->assertForbidden();
        $this->feedAs($this->makeAdmin())->assertOk();
    }

    public function test_feed_responses_are_never_cached(): void
    {
        $response = $this->feedAs($this->makeAdmin())->assertOk();

        $this->assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control', ''),
            'a cached snapshot would hide new rentals until reload'
        );
    }

    public function test_feed_tracks_the_rental_to_return_lifecycle(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => true]);
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $service = app(RentalService::class);

        $before = $this->feedAs($admin)->assertOk();
        $this->assertSame(0, $before->json('active'));
        $this->assertSame(0, $before->json('pendingReturns'));
        $this->assertSame(1, $before->json('bikesAvailable'));

        // Renter starts a rental: appears in Active Rentals, bike leaves stock.
        $rental = $service->startRental($rider, $bike->id, 60, 'cash');

        $started = $this->feedAs($admin)->assertOk();
        $this->assertSame(1, $started->json('active'));
        $this->assertSame(0, $started->json('bikesAvailable'));
        $this->assertNotSame($before->json('version'), $started->json('version'));

        // Renter clicks Return Bicycle: leaves the active queue for the
        // pending-inspection list, kept pending until an admin confirms.
        $service->returnRental($rental->fresh(), $rider);

        $returned = $this->feedAs($admin)->assertOk();
        $this->assertSame(0, $returned->json('active'));
        $this->assertSame(1, $returned->json('pendingReturns'));
        $this->assertSame(0, $returned->json('processed'));
        $this->assertNotSame($started->json('version'), $returned->json('version'));

        // Admin confirms: moves to processed, settles the fee, frees the bike.
        $service->processReturn($rental->fresh(), $admin, ['condition' => 'good']);

        $settled = $this->feedAs($admin)->assertOk();
        $this->assertSame(0, $settled->json('pendingReturns'));
        $this->assertSame(1, $settled->json('processed'));
        $this->assertSame(1, $settled->json('bikesAvailable'));
        $this->assertGreaterThan(0, $settled->json('feeCollected'));
        $this->assertSame('available', $bike->fresh()->status);
        $this->assertSame(Rental::STATUS_RETURNED, $rental->fresh()->status);
    }

    public function test_lifecycle_transitions_broadcast_feed_updates(): void
    {
        Event::fake([RentalFeedUpdated::class]);

        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => true]);
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $service = app(RentalService::class);

        $rental = $service->startRental($rider, $bike->id, 60, 'cash');
        Event::assertDispatched(RentalFeedUpdated::class, 1);

        $service->returnRental($rental->fresh(), $rider);
        Event::assertDispatched(RentalFeedUpdated::class, 2);

        $service->processReturn($rental->fresh(), $admin, ['condition' => 'good']);
        Event::assertDispatched(RentalFeedUpdated::class, 3);
    }

    public function test_expiry_promotion_is_visible_without_a_scheduler_run(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => true]);
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $rental = app(RentalService::class)->startRental($rider, $bike->id, 60, 'cash');
        $rental->update(['expectedEndTime' => now()->subMinute()]);

        // The feed route runs rental.sync, so the expired row is promoted on
        // read and stays in the active queue (expired rides still occupy it).
        $this->feedAs($admin)->assertOk()->assertJsonPath('active', 1);
        $this->assertSame(Rental::STATUS_EXPIRED, $rental->fresh()->status);
    }

    public function test_pages_expose_live_sync_regions(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get(route('admin.rentals.index'))
            ->assertOk()
            ->assertSee('id="rvFoot"', false)
            ->assertSee('PedalyaRentalLivePage', false)
            ->assertSee('PedalyaRentalsLiveRefresh', false);

        $this->actingAs($admin)->get(route('admin.rentals.returns'))
            ->assertOk()
            ->assertSee('id="rvTabs"', false)
            ->assertSee('id="rvSummary"', false)
            ->assertSee('id="rvFoot"', false)
            ->assertSee('PedalyaReturnsLiveRefresh', false);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('id="kpiRow"', false)
            ->assertSee('id="recentRentalsFeed"', false);
    }
}
