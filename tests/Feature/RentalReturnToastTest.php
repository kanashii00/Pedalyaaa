<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\Notification;
use App\Models\Rental;
use App\Models\User;
use App\Services\RentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * Return notifications stay on one shared pipeline:
 *
 *  - the real return transition notifies administrators once;
 *  - the rental feed exposes enough real event data for the polling fallback;
 *  - success/failure operations use the shared structured toast instead of a
 *    second banner+toast pair.
 *
 * The browser-side dedupe across WebSocket, polling, and page navigation is
 * covered by the source assertions at the end of this file.
 */
class RentalReturnToastTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function test_active_ride_return_notifies_each_admin_once_for_inspection(): void
    {
        $admin = $this->makeAdmin();
        $otherAdmin = $this->makeAdmin();
        $rider = $this->makeRider(['name' => 'Maya Rider']);
        $bike = $this->makeBicycle([
            'name' => 'City Cruiser',
            'status' => Bicycle::STATUS_RENTED,
            'lockStatus' => Bicycle::LOCK_UNLOCKED,
        ]);
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
        ]);

        $this->actingAs($rider)
            ->from(route('rider.dashboard'))
            ->post(route('rider.rentals.return', $rental->id))
            ->assertRedirect(route('rider.dashboard'));

        $this->assertSame(Rental::STATUS_AWAITING_RETURN, $rental->fresh()->status);

        foreach ([$admin, $otherAdmin] as $recipient) {
            $notification = Notification::where('userId', $recipient->id)
                ->where('type', 'return_requested')
                ->first();

            $this->assertNotNull($notification, 'every admin should receive the real return event');
            $this->assertSame('Return Inspection Required', $notification->title);
            $this->assertSame($rental->rentalId, $notification->rentalId);
            $this->assertStringContainsString('Maya Rider', $notification->message);
            $this->assertStringContainsString('City Cruiser', $notification->message);
            $this->assertStringContainsString('Inspect the bicycle', $notification->message);
        }

        // A repeat click is an idempotent no-op, including its notification.
        $this->actingAs($rider)
            ->from(route('rider.dashboard'))
            ->post(route('rider.rentals.return', $rental->id))
            ->assertRedirect(route('rider.dashboard'));

        $this->assertSame(
            1,
            Notification::where('userId', $admin->id)->where('type', 'return_requested')->count(),
            'the same return must not create a second notification'
        );
        $this->assertSame(
            1,
            Notification::where('userId', $otherAdmin->id)->where('type', 'return_requested')->count()
        );
    }

    public function test_feed_exposes_real_pending_return_events_for_the_toast_fallback(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['name' => 'Maya Rider']);
        $bike = $this->makeBicycle(['name' => 'City Cruiser']);
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
            'returnRequestedAt' => null,
        ]);

        app(RentalService::class)->requestReturn($rental, $rider);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.rentals.feed'))
            ->assertOk()
            ->assertJsonCount(1, 'pendingReturnRequests')
            ->assertJsonPath('pendingReturnRequests.0.key', 'rental:'.$rental->rentalId)
            ->assertJsonPath('pendingReturnRequests.0.rentalId', $rental->rentalId)
            ->assertJsonPath('pendingReturnRequests.0.rider', 'Maya Rider')
            ->assertJsonPath('pendingReturnRequests.0.bicycle', 'City Cruiser')
            ->assertJsonPath('pendingReturnRequests.0.status', Rental::STATUS_AWAITING_RETURN);

        $this->assertNotNull($response->json('pendingReturnRequests.0.requestedAt'));

        app(RentalService::class)->processReturn($rental->fresh(), $admin, ['condition' => 'good']);

        $this->actingAs($admin)
            ->getJson(route('admin.rentals.feed'))
            ->assertOk()
            ->assertJsonCount(0, 'pendingReturnRequests');
    }

    public function test_process_return_failure_posts_one_structured_error_toast(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
        ]);

        $service = Mockery::mock(RentalService::class);
        $service->shouldReceive('processReturn')
            ->once()
            ->andThrow(new \RuntimeException('Process failure'));
        $this->app->instance(RentalService::class, $service);

        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), ['condition' => 'good'])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionHasErrors('rental')
            ->assertSessionHas('toast', fn ($toast) => $toast['type'] === 'error'
                && $toast['title'] === 'Return not processed'
                && $toast['message'] === 'Process failure');

        // The follow-up page carries the toast but not the competing error
        // banner, so the administrator sees exactly one failure message.
        $this->app->forgetInstance(RentalService::class);

        $this->actingAs($admin)
            ->get(route('admin.rentals.returns'))
            ->assertOk()
            ->assertSee('Process failure', false)
            ->assertSee('Return not processed', false)
            ->assertDontSee('Please correct the following errors:', false);
    }

    public function test_process_return_validation_failure_posts_one_structured_error_toast(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'return_time' => 'not-a-date',
                'condition' => 'good',
            ])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionHasErrors('return_time')
            ->assertSessionHas('toast', fn ($toast) => $toast['type'] === 'error'
                && $toast['title'] === 'Return not processed'
                && str_contains($toast['message'], 'return time'));
    }

    public function test_end_ride_success_uses_the_shared_structured_toast(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.rentals.index'))
            ->put(route('admin.rentals.end-ride', $rental->id), ['condition' => Rental::CONDITION_GOOD])
            ->assertRedirect(route('admin.rentals.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('toast', fn ($toast) => $toast['type'] === 'success'
                && $toast['title'] === 'Ride ended successfully'
                && str_contains($toast['message'], $rental->rentalId));
    }

    public function test_end_ride_failure_posts_the_shared_structured_error_toast(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
        ]);

        $service = Mockery::mock(RentalService::class);
        $service->shouldReceive('endRideAndSettle')
            ->once()
            ->andThrow(new \RuntimeException('End failure'));
        $this->app->instance(RentalService::class, $service);

        $this->actingAs($admin)
            ->from(route('admin.rentals.index'))
            ->put(route('admin.rentals.end-ride', $rental->id), ['condition' => Rental::CONDITION_GOOD])
            ->assertRedirect(route('admin.rentals.index'))
            ->assertSessionHasErrors('rental')
            ->assertSessionHas('toast', fn ($toast) => $toast['type'] === 'error'
                && $toast['title'] === 'Ride not ended'
                && $toast['message'] === 'End failure');
    }

    public function test_shared_toast_runtime_is_used_on_both_rental_pages_without_a_second_system(): void
    {
        $admin = $this->makeAdmin();

        foreach ([route('admin.rentals.index'), route('admin.rentals.returns')] as $url) {
            $this->actingAs($admin)
                ->get($url)
                ->assertOk()
                ->assertSee('id="adminToasts"', false)
                ->assertSee('PedalyaRentalLivePage', false);
        }

        $rentalLive = file_get_contents(public_path('js/rental-live.js'));
        $notifications = file_get_contents(public_path('js/notifications.js'));

        $this->assertStringContainsString('window.PedalyaToast.warning', $rentalLive);
        $this->assertStringContainsString("sessionStorage", $rentalLive);
        $this->assertStringContainsString('pendingReturnRequests', $rentalLive);
        $this->assertStringContainsString('pageOwnsToast', $notifications);
        $this->assertStringContainsString("'return_requested'", $notifications);
    }
}
