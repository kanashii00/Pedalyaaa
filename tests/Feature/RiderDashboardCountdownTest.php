<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\Rental;
use App\Models\User;
use App\Services\RentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * The rider dashboard and the on-board LCD are two renderings of one clock.
 *
 * Both must resolve the deadline the same way, otherwise a rider watches the
 * website count down to zero while the handlebar display still shows time
 * left. The dashboard used to derive its target from startTime + durationMinutes
 * and ignore expectedEndTime, which the device endpoint uses, so the two
 * silently disagreed whenever those two values diverged.
 */
class RiderDashboardCountdownTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function dashboardTarget(User $rider): ?string
    {
        $html = $this->actingAs($rider)->get(route('rider.dashboard'))->getContent();

        $this->assertStringContainsString('var target =', $html, 'countdown script missing');
        $this->assertFalse(
            str_contains($html, '&#039;'),
            'countdown script must not contain HTML-escaped quotes'
        );

        preg_match('/var target = (.+?);/', $html, $m);
        $literal = trim($m[1] ?? '');

        if ($literal === 'null') {
            return null;
        }

        $decoded = json_decode($literal);
        $this->assertIsString($decoded, "var target must be a valid JS literal, got: {$literal}");

        return $decoded;
    }

    private function deviceDeadline(Bicycle $bicycle): ?string
    {
        $key = $bicycle->issueDeviceKey();

        $response = $this->withHeader('X-API-Key', $key)
            ->getJson("/api/iot/bicycle/{$bicycle->id}/rental");

        $response->assertOk();

        return $response->json('data.rental.returnDeadline');
    }

    public function test_dashboard_countdown_matches_device_deadline(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);

        app(RentalService::class)->startRental($rider, $bicycle->id, 1, 'cash');

        $rental = Rental::where('bicycleId', $bicycle->id)
            ->where('status', Rental::STATUS_ACTIVE)
            ->firstOrFail();

        $dashboardTarget = new Carbon($this->dashboardTarget($rider));
        $deviceTarget = new Carbon($this->deviceDeadline($bicycle));

        $this->assertTrue(
            $dashboardTarget->equalTo($deviceTarget),
            'rider dashboard and on-board LCD must count down to the same instant'
        );
    }

    public function test_dashboard_follows_expected_end_time_not_just_the_duration(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);

        app(RentalService::class)->startRental($rider, $bicycle->id, 60, 'cash');

        $rental = Rental::where('bicycleId', $bicycle->id)
            ->where('status', Rental::STATUS_ACTIVE)
            ->firstOrFail();

        // An admin shortens the window. durationMinutes still says 60, so a
        // dashboard deriving its target from the duration would still show an
        // hour and now disagree with the LCD.
        $shortened = $rental->startTime->copy()->addSeconds(15);
        $rental->update(['expectedEndTime' => $shortened]);

        $target = new Carbon($this->dashboardTarget($rider));

        $this->assertTrue(
            $target->equalTo($shortened),
            "dashboard targeted {$target}, expected the shortened deadline {$shortened}"
        );
    }

    public function test_return_deadline_takes_precedence_for_awaiting_return(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $admin = $this->makeAdmin();

        $rental = app(RentalService::class)->startRental($rider, $bicycle->id, 30, 'cash');

        // A return deadline can only be set once the rental has expired.
        $this->travelTo($rental->expectedEndTime->copy()->addSecond());
        app(RentalService::class)->expireDueRentals();
        $rental->refresh();

        app(RentalService::class)->setReturnDeadline(
            $rental,
            $admin,
            Carbon::now()->addMinutes(20)
        );

        $target = new Carbon($this->dashboardTarget($rider));

        $this->assertTrue(
            $target->equalTo($rental->fresh()->returnDeadline),
            'awaiting_return must count down to the admin-set return deadline'
        );
    }

    public function test_expired_rental_shows_grace_countdown_matching_device_deadline_after_refresh(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $expectedEnd = now()->subSecond();
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bicycle->id,
            'status' => Rental::STATUS_EXPIRED,
            'startTime' => $expectedEnd->copy()->subHour(),
            'expectedEndTime' => $expectedEnd,
        ]);
        $deadline = $expectedEnd->copy()->addSeconds(RentalService::RETURN_GRACE_SECONDS);

        $html = $this->actingAs($rider)->get(route('rider.dashboard'))->assertOk()->getContent();
        $target = new Carbon($this->dashboardTarget($rider));

        $this->assertSame($deadline->getTimestamp(), $target->getTimestamp());
        $this->assertStringContainsString('Bike Return Countdown', $html);
        $this->assertStringContainsString('Awaiting Bike Return', $html);
        $this->assertStringContainsString('Please return your bike before the countdown expires.', $html);
        $this->assertStringContainsString(
            'Late Return Penalty:</strong> ₱20 per completed 5 minutes after this period',
            $html
        );
        $this->assertStringContainsString(
            '₱20 per completed 5 minutes after this period',
            $html
        );

        $deviceKey = $bicycle->issueDeviceKey();
        $deviceResponse = $this->withHeader('X-API-Key', $deviceKey)
            ->getJson("/api/iot/bicycle/{$bicycle->id}/rental")
            ->assertOk();

        $this->assertSame(
            $target->copy()->utc()->toIso8601String(),
            $deviceResponse->json('data.rental.returnCountdownDeadlineUtc')
        );

        $this->assertSame(
            $target->toIso8601String(),
            $this->dashboardTarget($rider),
            'refreshes must not restart the session'
        );
    }

    public function test_return_grace_countdown_is_scoped_to_its_rider(): void
    {
        $owner = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $otherRider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $expectedEnd = now()->subSecond();

        $this->makeRental([
            'riderId' => $owner->id,
            'bicycleId' => $bicycle->id,
            'status' => Rental::STATUS_EXPIRED,
            'startTime' => $expectedEnd->copy()->subHour(),
            'expectedEndTime' => $expectedEnd,
        ]);

        $this->actingAs($otherRider)
            ->get(route('rider.dashboard'))
            ->assertOk()
            ->assertDontSee('id="bikeReturnCountdown"', false);
    }

    public function test_dashboard_state_returns_fresh_grace_deadline_and_authoritative_penalty(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $expectedEnd = now()->subMinutes(6);
        $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bicycle->id,
            'status' => Rental::STATUS_EXPIRED,
            'startTime' => $expectedEnd->copy()->subHour(),
            'expectedEndTime' => $expectedEnd,
        ]);

        $expectedDeadline = $expectedEnd->copy()->addSeconds(RentalService::RETURN_GRACE_SECONDS);

        $response = $this->actingAs($rider)
            ->getJson(route('rider.dashboard.state'))
            ->assertOk();

        $response->assertJsonPath('activeRental.deadline', $expectedDeadline->copy()->utc()->toIso8601String());
        $this->assertSame(0.0, (float) $response->json('activeRental.currentPenalty'));
        $this->assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control', '')
        );
    }

    public function test_overdue_rental_shows_overdue_stage_and_penalty_after_grace(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);
        $expectedEnd = now()->subMinutes(10);
        $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bicycle->id,
            'status' => Rental::STATUS_EXPIRED,
            'startTime' => $expectedEnd->copy()->subHour(),
            'expectedEndTime' => $expectedEnd,
        ]);

        $html = $this->actingAs($rider)->get(route('rider.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('id="bikeReturnCountdownStatus">Overdue</div>', $html);
        $this->assertStringContainsString('id="bikeReturnOverdueDetails"', $html);
        $this->assertStringContainsString('₱20.00', $html);
    }

    public function test_rider_active_card_does_not_render_bicycle_battery_percentage(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED, 'batteryLevel' => 73]);
        $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bicycle->id,
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => now()->subMinutes(5),
            'expectedEndTime' => now()->addMinutes(55),
        ]);

        $html = $this->actingAs($rider)->get(route('rider.dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('bi-battery-three-quarters', $html);
        $this->assertStringNotContainsString('73%', $html);
        $this->assertStringContainsString('Rental Cost:', $html);
    }

    public function test_dashboard_state_exposes_final_return_charge_and_penalty(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle();
        $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bicycle->id,
            'status' => Rental::STATUS_RETURNED,
            'totalFee' => 15.00,
            'overdueFee' => 20.00,
            'finalFee' => 35.00,
        ]);

        $this->actingAs($rider)
            ->getJson(route('rider.dashboard.state'))
            ->assertOk()
            ->assertJsonPath('lastSettled.baseFee', 15)
            ->assertJsonPath('lastSettled.finalPenalty', 20)
            ->assertJsonPath('lastSettled.finalFee', 35);
    }

    public function test_expired_grace_displays_zero_without_marking_the_bike_returned(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);
        $expectedEnd = now()->subMinutes(6);
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bicycle->id,
            'status' => Rental::STATUS_EXPIRED,
            'startTime' => $expectedEnd->copy()->subHour(),
            'expectedEndTime' => $expectedEnd,
        ]);

        $html = $this->actingAs($rider)->get(route('rider.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('id="bikeReturnCountdownValue">00:00</div>', $html);
        $this->assertStringContainsString('id="bikeReturnCountdownStatus">Overdue</div>', $html);
        $this->assertSame(Rental::STATUS_EXPIRED, $rental->fresh()->status);
    }
}
