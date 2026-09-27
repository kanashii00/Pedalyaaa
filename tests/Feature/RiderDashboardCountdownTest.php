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

        $this->assertSame(
            $this->dashboardTarget($rider),
            $this->deviceDeadline($bicycle),
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
            Carbon::now()->addMinutes(20),
            ['type' => Rental::PENALTY_PER_HOUR, 'value' => 25]
        );

        $target = new Carbon($this->dashboardTarget($rider));

        $this->assertTrue(
            $target->equalTo($rental->fresh()->returnDeadline),
            'awaiting_return must count down to the admin-set return deadline'
        );
    }
}
