<?php

namespace Tests\Feature;

use App\Exceptions\RentalException;
use App\Models\Rental;
use App\Services\RentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class RiderReturnSyncTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function startActiveRental()
    {
        $rider = $this->makeRider(['verified' => true]);
        $bike = $this->makeBicycle();
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
        ]);

        return [$rider, $bike, $rental];
    }

    public function test_dashboard_state_requires_a_rider_session(): void
    {
        $this->getJson(route('rider.dashboard.state'))->assertUnauthorized();
        $this->actingAs($this->makeAdmin())
            ->getJson(route('rider.dashboard.state'))
            ->assertForbidden();
    }

    public function test_dashboard_state_is_never_cached(): void
    {
        $rider = $this->makeRider();

        $response = $this->actingAs($rider)->getJson(route('rider.dashboard.state'))
            ->assertOk();

        // Symfony normalises directive order, so assert containment.
        $this->assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control', ''),
            'a cached snapshot would keep the pending card alive after confirm'
        );
    }

    public function test_state_reports_a_pending_return(): void
    {
        [$rider, $bike, $rental] = $this->startActiveRental();

        app(RentalService::class)->returnRental($rental->fresh(), $rider);

        $this->actingAs($rider)->getJson(route('rider.dashboard.state'))
            ->assertOk()
            ->assertJsonPath('activeRental.status', Rental::STATUS_AWAITING_RETURN)
            ->assertJsonPath('activeRental.returnSubmitted', true)
            ->assertJsonPath('stats.activeCount', 1);

        // Pending — never settled early — so the dashboard keeps the card.
        $this->actingAs($rider)->get(route('rider.dashboard'))
            ->assertOk()
            ->assertSee('Awaiting Admin Confirmation', false);
    }

    public function test_state_settles_immediately_after_admin_confirm(): void
    {
        [$rider, $bike, $rental] = $this->startActiveRental();
        $admin = $this->makeAdmin();

        app(RentalService::class)->returnRental($rental->fresh(), $rider);
        app(RentalService::class)->processReturn($rental->fresh(), $admin, []);

        $state = $this->actingAs($rider)->getJson(route('rider.dashboard.state'))
            ->assertOk();

        // No live rental left: the watcher may clear the card.
        $state->assertJsonPath('activeRental', null)
            ->assertJsonPath('stats.activeCount', 0);

        // Recent list carries the settled row with the final fee.
        $recent = $state->json('recentRentals');
        $this->assertNotEmpty($recent);
        $this->assertSame(Rental::STATUS_RETURNED, $recent[0]['status']);
        $this->assertEqualsWithDelta(
            (float) $rental->fresh()->finalFee,
            (float) $recent[0]['cost'],
            0.001
        );

        // Toast payload for the in-place confirmation message.
        $this->assertSame($rental->fresh()->rentalId, $state->json('lastSettled.rentalId'));
        $this->assertEqualsWithDelta(
            (float) $rental->fresh()->finalFee,
            (float) $state->json('lastSettled.finalFee'),
            0.001
        );

        // A full reload agrees with the live state (backend is the truth).
        // (Matched on markup-only strings: the watcher script itself
        // mentions badge labels, so plain-text assertions would collide.)
        $this->actingAs($rider)->get(route('rider.dashboard'))
            ->assertOk()
            ->assertSee('No active rental', false)
            ->assertSee('Rent Now', false)
            ->assertDontSee('id="pendingReturnBox"', false)
            ->assertDontSee('id="returnActionForm"', false)
            ->assertSee('id="statActiveRental">0<', false);
    }

    public function test_repeat_return_submits_stay_idempotent(): void
    {
        [$rider, $bike, $rental] = $this->startActiveRental();

        $first = $this->actingAs($rider)->post(route('rider.rentals.return', $rental->id));
        $first->assertRedirect(route('rider.dashboard'));
        $requestedAt = $rental->fresh()->returnRequestedAt;

        // A double-click (or a second tab) can never queue another request.
        $second = $this->actingAs($rider)->post(route('rider.rentals.return', $rental->id));
        $second->assertRedirect(route('rider.dashboard'));

        $this->assertSame(Rental::STATUS_AWAITING_RETURN, $rental->fresh()->status);
        $this->assertEquals($requestedAt, $rental->fresh()->returnRequestedAt);
        $this->assertSame(1, Rental::where('riderId', $rider->id)->count());
    }

    public function test_confirming_twice_is_rejected(): void
    {
        [$rider, $bike, $rental] = $this->startActiveRental();
        $admin = $this->makeAdmin();

        app(RentalService::class)->returnRental($rental->fresh(), $rider);
        app(RentalService::class)->processReturn($rental->fresh(), $admin, []);

        $this->expectException(RentalException::class);
        app(RentalService::class)->processReturn($rental->fresh(), $admin, []);
    }

    public function test_dashboard_renders_the_return_watcher(): void
    {
        [$rider, $bike, $rental] = $this->startActiveRental();

        $html = $this->actingAs($rider)->get(route('rider.dashboard'))->assertOk()->getContent();

        // @json backslash-escapes slashes, so match the encoded path.
        $this->assertStringContainsString('rider\\/dashboard\\/state', $html);
        $this->assertStringContainsString('PedalyaReturnWatch', $html);
        $this->assertStringContainsString('noActiveRentalTemplate', $html);
        $this->assertStringContainsString('recentRentalsBody', $html);
        $this->assertStringContainsString('statActiveRental', $html);
        $this->assertStringContainsString('statTotalSpent', $html);
        $this->assertStringContainsString('ridingAreaTitle', $html);
        $this->assertStringContainsString('zoneBadgeWrap', $html);
    }
}
