<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\Rental;
use App\Models\User;
use App\Services\RentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * The Active Rental card must flip to the Return action the moment the ride
 * ends, without a manual refresh. The browser re-reads this endpoint once at
 * zero, so it has to report the authoritative (post-sync) state, never a
 * cached one, with the server clock attached for client-clock correction.
 */
class RiderActiveStatusTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function getStatus(User $rider)
    {
        return $this->actingAs($rider)->getJson(route('rider.rentals.active-status'));
    }

    public function test_active_rental_reports_future_deadline_and_no_return_yet(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        app(RentalService::class)->startRental($rider, $bike->id, 60, 'cash');

        // An active ride may be returned at any time (the dashboard only
        // reveals the button at zero); the flag reports what the backend
        // allows, and the countdown decides when to show it.
        $this->getStatus($rider)
            ->assertOk()
            ->assertJsonPath('rental.status', Rental::STATUS_ACTIVE)
            ->assertJsonPath('rental.canReturn', true)
            ->assertJsonPath('rental.canExtend', true)
            ->assertJsonPath('rental.returnSubmitted', false)
            ->assertJsonStructure(['rental' => ['deadline'], 'serverTime']);
    }

    public function test_active_status_derives_deadline_for_legacy_rental(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $startTime = now()->subMinutes(20);
        $expectedDeadline = $startTime->copy()->addMinutes(60);
        $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => $startTime,
            'endTime' => null,
            'expectedEndTime' => null,
            'durationMinutes' => 60,
        ]);

        $this->getStatus($rider)
            ->assertOk()
            ->assertJsonPath('rental.deadline', $expectedDeadline->copy()->utc()->toIso8601String());
    }

    public function test_expired_rental_is_promoted_before_it_is_reported(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $rental = app(RentalService::class)->startRental($rider, $bike->id, 60, 'cash');
        // No scheduler run: the sync middleware on this endpoint must observe
        // the transition, exactly as the dashboard request does.
        $rental->update(['expectedEndTime' => now()->subMinute()]);

        $this->getStatus($rider)
            ->assertOk()
            ->assertJsonPath('rental.status', Rental::STATUS_EXPIRED)
            ->assertJsonPath('rental.canReturn', true)
            ->assertJsonPath('rental.canExtend', true)
            ->assertJsonPath('rental.returnSubmitted', false);

        $this->assertSame(Rental::STATUS_EXPIRED, $rental->fresh()->status);
    }

    public function test_extended_rental_reports_the_new_deadline(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $rental = app(RentalService::class)->startRental($rider, $bike->id, 60, 'cash');
        $before = $rental->expectedEndTime->copy();

        app(RentalService::class)->extendRental($rental, 60, $rider);

        $deadline = $this->getStatus($rider)->assertOk()->json('rental.deadline');

        $this->assertNotNull($deadline);
        $this->assertTrue(
            now()->parse($deadline)->greaterThan($before),
            'the reported deadline must move forward after an extension'
        );
    }

    public function test_pending_return_reports_submitted_and_disables_extend(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $rental = app(RentalService::class)->startRental($rider, $bike->id, 60, 'cash');

        app(RentalService::class)->returnRental($rental, $rider);

        $this->getStatus($rider)
            ->assertOk()
            ->assertJsonPath('rental.status', Rental::STATUS_AWAITING_RETURN)
            ->assertJsonPath('rental.returnSubmitted', true)
            ->assertJsonPath('rental.returnRequestedAt', $rental->fresh()->returnRequestedAt->toIso8601String())
            ->assertJsonPath('rental.canReturn', false)
            ->assertJsonPath('rental.canExtend', false);
    }

    public function test_status_responses_are_never_cached(): void
    {
        $rider = $this->makeRider();

        $response = $this->getStatus($rider)->assertOk();

        $this->assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control', ''),
            'stale rental status must never hide the Return button'
        );

        $live = $this->actingAs($rider)->getJson(route('rider.operating-area.live'))->assertOk();

        $this->assertStringContainsString(
            'no-store',
            $live->headers->get('Cache-Control', ''),
            'live operating-area payload must never be served stale'
        );
    }

    public function test_high_frequency_rider_read_polls_skip_lifecycle_sync(): void
    {
        $rider = $this->makeRider();
        $this->mock(RentalService::class, function ($mock) {
            $mock->shouldNotReceive('synchronizeLifecycle');
        });

        $this->actingAs($rider)
            ->getJson(route('rider.operating-area.live'))
            ->assertOk();
        $this->actingAs($rider)
            ->getJson(route('rider.dashboard.state'))
            ->assertOk();
    }

    public function test_dashboard_renders_the_in_place_action_states(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        app(RentalService::class)->startRental($rider, $bike->id, 60, 'cash');

        $html = $this->actingAs($rider)->get(route('rider.dashboard'))->assertOk()->getContent();

        // Hidden until the countdown sync confirms them: present for the
        // script, invisible on first paint.
        $this->assertStringContainsString('id="returnActionForm"', $html);
        $this->assertStringContainsString('id="pendingReturnBox"', $html);
        $this->assertStringContainsString('id="extendActionWrap"', $html);
        $this->assertStringContainsString('id="expiredWarningTemplate"', $html);
        $this->assertStringContainsString('PedalyaRentalCountdown', $html, 'singleton guard missing');
        // The status URL is JSON-encoded in the page (slashes escaped), so
        // match the unescaped endpoint slug.
        $this->assertStringContainsString('active-status', $html);
        $this->assertStringContainsString('var target =', $html);
    }
}
