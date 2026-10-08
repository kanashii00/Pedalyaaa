<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Services\RentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class RideExtensionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdmin();
    }

    private function activeRental(array $overrides = []): Rental
    {
        $attributes = array_merge([
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => now()->subMinutes(30),
            'expectedEndTime' => now()->addMinutes(30),
            'durationMinutes' => 60,
            'durationFormatted' => '1h 0m',
            'totalFee' => 15.00,
            'chargedHours' => 1,
            'ratePerHour' => 15.00,
        ], $overrides);

        if (! isset($attributes['riderId'])) {
            $attributes['riderId'] = $this->makeRider()->id;
        }
        if (! isset($attributes['bicycleId'])) {
            $attributes['bicycleId'] = $this->makeBicycle()->id;
        }

        return $this->makeRental($attributes);
    }

    public function test_rider_can_extend_an_active_ride(): void
    {
        $rider = $this->makeRider();
        $rental = $this->activeRental(['riderId' => $rider->id]);
        $originalEnd = $rental->expectedEndTime->copy();

        $this->actingAs($rider)
            ->from(route('rider.dashboard'))
            ->post(route('rider.rentals.extend', $rental->id), ['extensionMinutes' => 60])
            ->assertRedirect(route('rider.dashboard'))
            ->assertSessionHas('success');

        $rental = $rental->fresh();
        $this->assertSame(Rental::STATUS_ACTIVE, $rental->status);
        $this->assertSame(1, (int) $rental->extensionCount);
        $this->assertSame(60, (int) $rental->extendedMinutes);
        $this->assertSame(120, (int) $rental->durationMinutes);
        $this->assertSame(2, (int) $rental->chargedHours);
        $this->assertEquals(30.0, (float) $rental->totalFee);
        $this->assertEquals(15.0, (float) $rental->extensionFee);
        $this->assertTrue($originalEnd->copy()->addHour()->equalTo($rental->expectedEndTime));
        $this->assertCount(1, $rental->extensions ?? []);
        $this->assertNotNull($rental->lastExtendedAt);
        $this->assertDatabaseHas('notifications', [
            'userId' => $rider->id,
            'type' => 'rental_extended',
        ]);
    }

    public function test_extension_reopens_an_expired_ride(): void
    {
        $rider = $this->makeRider();
        $rental = $this->activeRental([
            'riderId' => $rider->id,
            'startTime' => now()->subMinutes(65),
            'expectedEndTime' => now()->subMinutes(5),
            'durationMinutes' => 60,
            'status' => Rental::STATUS_EXPIRED,
            'expiredAt' => now()->subMinutes(5),
            'warningSentAt' => now()->subMinutes(10),
        ]);

        $this->actingAs($rider)
            ->from(route('rider.dashboard'))
            ->post(route('rider.rentals.extend', $rental->id), ['extensionMinutes' => 30])
            ->assertRedirect(route('rider.dashboard'))
            ->assertSessionHas('success');

        $rental = $rental->fresh();
        $this->assertSame(Rental::STATUS_ACTIVE, $rental->status);
        $this->assertNull($rental->expiredAt);
        $this->assertNull($rental->warningSentAt);
        $this->assertSame(1, (int) $rental->extensionCount);
        $this->assertTrue($rental->expectedEndTime->isFuture());
        $this->assertEquals(30.0, (float) $rental->totalFee);
    }

    public function test_extension_beyond_maximum_duration_is_rejected(): void
    {
        $rider = $this->makeRider();
        $rental = $this->activeRental([
            'riderId' => $rider->id,
            'startTime' => now()->subMinutes(690),
            'expectedEndTime' => now()->addMinutes(30),
            'durationMinutes' => 690,
            'totalFee' => 207.00,
            'chargedHours' => 12,
        ]);

        $this->actingAs($rider)
            ->from(route('rider.dashboard'))
            ->post(route('rider.rentals.extend', $rental->id), ['extensionMinutes' => 60])
            ->assertRedirect(route('rider.dashboard'))
            ->assertSessionHasErrors('extension');

        $rental = $rental->fresh();
        $this->assertSame(Rental::STATUS_ACTIVE, $rental->status);
        $this->assertSame(0, (int) $rental->extensionCount);
        $this->assertEquals(207.0, (float) $rental->totalFee);
        $this->assertEqualsWithDelta(
            now()->addMinutes(30)->getTimestamp(),
            $rental->expectedEndTime->getTimestamp(),
            5
        );
    }

    public function test_settlement_keeps_the_extension_fee_when_rider_returns_early(): void
    {
        $rider = $this->makeRider();
        $service = app(RentalService::class);

        $rental = $this->activeRental([
            'riderId' => $rider->id,
            'startTime' => now()->subMinutes(20),
            'expectedEndTime' => now()->addMinutes(10),
            'durationMinutes' => 30,
            'totalFee' => 15.00,
            'chargedHours' => 1,
        ]);

        $service->extendRental($rental, 30, $rider);

        $result = $service->endRideAndSettle($rental->fresh(), $this->admin, ['condition' => 'good']);

        // Elapsed ride time alone would only bill ~20 minutes (1 charged hour,
        // PHP 15), but the purchased extension sets the fee floor, and that
        // floored figure is what the receipt reports as the base fee.
        $this->assertEquals(30.0, (float) $result['fees']['baseFee']);
        $this->assertEquals(30.0, (float) $result['fees']['finalFee']);
        $this->assertEquals(0.0, (float) $result['fees']['latePenalty']);
        $this->assertEquals(30.0, (float) $rental->fresh()->finalFee);
    }

    public function test_unextended_rental_settlement_is_unchanged(): void
    {
        $rider = $this->makeRider();
        $service = app(RentalService::class);

        $rental = $this->activeRental([
            'riderId' => $rider->id,
            'startTime' => now()->subMinutes(20),
            'expectedEndTime' => now()->addMinutes(10),
            'durationMinutes' => 30,
            'totalFee' => 15.00,
            'chargedHours' => 1,
        ]);

        $result = $service->endRideAndSettle($rental, $this->admin, ['condition' => 'good']);

        // No extension: the legacy behavior bills from actual elapsed time.
        $this->assertEquals(15.0, (float) $result['fees']['finalFee']);
        $this->assertSame(0, (int) $rental->fresh()->extensionCount);
    }

    public function test_admin_receipt_shows_the_ride_as_extended(): void
    {
        $rider = $this->makeRider();
        $rental = $this->activeRental(['riderId' => $rider->id]);
        app(RentalService::class)->extendRental($rental, 60, $rider);

        $this->actingAs($this->admin)
            ->get(route('admin.rentals.index'))
            ->assertOk()
            ->assertSee('Extended &times;1', false)
            ->assertSee('Ride Extended', false);

        // The history page only lists finished rides, so its chip is checked
        // on a completed rental that was extended while it was running.
        $completed = $this->activeRental();
        app(RentalService::class)->extendRental($completed, 30, $completed->rider);
        $completed->update(['status' => Rental::STATUS_COMPLETED]);

        $this->actingAs($this->admin)
            ->get(route('admin.rentals.history') . '?status=completed')
            ->assertOk()
            ->assertSee('Extended &times;1', false);

        $this->actingAs($this->admin)
            ->get(route('admin.rentals.show', $rental->id))
            ->assertOk()
            ->assertSee('Ride Extended', false)
            ->assertSee('Extension #1: +1h 0m', false);
    }

    public function test_rider_dashboard_offers_the_extend_button(): void
    {
        $rider = $this->makeRider();
        $rental = $this->activeRental(['riderId' => $rider->id]);

        $this->actingAs($rider)
            ->get(route('rider.dashboard'))
            ->assertOk()
            ->assertSee('Extend Ride')
            ->assertSee('extendRideModal');

        // An already extended ride shows the extension badge too.
        app(RentalService::class)->extendRental($rental, 30, $rider);

        $this->actingAs($rider)
            ->get(route('rider.dashboard'))
            ->assertOk()
            ->assertSee('Extended &times;1', false);
    }
}
