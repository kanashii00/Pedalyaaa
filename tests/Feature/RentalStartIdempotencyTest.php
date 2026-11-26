<?php

namespace Tests\Feature;

use App\Exceptions\RentalException;
use App\Models\Bicycle;
use App\Models\DeviceCommand;
use App\Models\Notification;
use App\Models\Rental;
use App\Services\RentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class RentalStartIdempotencyTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pedalya.single_bike_mode' => ['enabled' => false]]);
    }

    public function test_replaying_a_rental_request_does_not_duplicate_side_effects(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $requestKey = (string) Str::uuid();
        $service = app(RentalService::class);

        $first = $service->startRental($rider, $bike->id, 60, 'cash', null, $requestKey);
        $retry = $service->startRental($rider, $bike->id, 60, 'cash', null, $requestKey);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, Rental::where('rentalRequestKey', $requestKey)->count());
        $this->assertSame(
            1,
            DeviceCommand::where('bicycleId', $bike->id)->where('command', 'unlock')->count()
        );
        $this->assertSame(1, Notification::where('userId', $rider->id)->where('type', 'rental_started')->count());
        $this->assertSame(1, (int) $rider->fresh()->totalRentals);
    }

    public function test_request_key_cannot_be_reused_for_different_rental_parameters(): void
    {
        $rider = $this->makeRider();
        $firstBike = $this->makeBicycle();
        $secondBike = $this->makeBicycle();
        $requestKey = (string) Str::uuid();
        $service = app(RentalService::class);

        $service->startRental($rider, $firstBike->id, 60, 'cash', null, $requestKey);

        try {
            $service->startRental($rider, $secondBike->id, 60, 'cash', null, $requestKey);
            $this->fail('A request key must not be reusable for a different bicycle.');
        } catch (RentalException $exception) {
            $this->assertStringContainsString('already used', $exception->getMessage());
        }

        $this->assertSame(1, Rental::where('rentalRequestKey', $requestKey)->count());
        $this->assertSame(0, DeviceCommand::where('bicycleId', $secondBike->id)->count());
    }

    public function test_json_confirmation_returns_authoritative_utc_timestamps_and_request_key(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $requestKey = (string) Str::uuid();

        $response = $this->actingAs($rider)->postJson(route('rider.rent.store'), [
            'bicycleId' => $bike->id,
            'paymentMethod' => 'cash',
            'durationMinutes' => 60,
            'rentalRequestKey' => $requestKey,
        ])->assertOk();

        $response->assertJsonPath('rental.requestKey', $requestKey)
            ->assertJsonStructure([
                'rental' => ['id', 'rentalId', 'requestKey', 'status', 'startTimeUtc', 'rentalDeadlineUtc', 'returnDeadlineUtc'],
                'serverTimeUtc',
            ]);

        $rental = Rental::where('rentalRequestKey', $requestKey)->firstOrFail();
        $this->assertSame($rental->startTime->copy()->utc()->toIso8601String(), $response->json('rental.startTimeUtc'));
        $this->assertSame($rental->expectedEndTime->copy()->utc()->toIso8601String(), $response->json('rental.rentalDeadlineUtc'));

        $this->actingAs($rider)
            ->getJson(route('rider.rentals.active-status'))
            ->assertOk()
            ->assertJsonPath('rental.requestKey', $requestKey);
    }
}
