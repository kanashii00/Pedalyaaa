<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\CacheRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * Customer side rental duration is chosen in minutes.
 *
 * The chosen minutes must flow through untouched: form -> controller ->
 * rentals.durationMinutes -> expectedEndTime -> device API, so the LCD
 * countdown runs for exactly what the customer picked.
 */
class RiderRentalDurationMinutesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function rentFor(int $durationMinutes, ?string $paymentMethod = 'cash'): Rental
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        // Bicycle 1 is the featured bicycle under single-bike mode.
        $bicycle = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE, 'serialNumber' => 'PDY-001']);

        $response = $this->actingAs($rider)->post(route('rider.rent.store'), [
            'bicycleId' => $bicycle->id,
            'durationMinutes' => $durationMinutes,
            'paymentMethod' => $paymentMethod,
            'paymentReference' => $paymentMethod === 'gcash' ? 'GC1234567890' : null,
        ]);

        $response->assertSessionHasNoErrors();

        return Rental::latest('id')->firstOrFail();
    }

    public static function durationProvider(): array
    {
        return [
            '15 minutes' => [15, '0h 15m'],
            '30 minutes' => [30, '0h 30m'],
            '60 minutes' => [60, '1h 0m'],
        ];
    }

    /**
     * @dataProvider durationProvider
     */
    public function test_customer_can_rent_for_a_custom_number_of_minutes(int $minutes, string $formatted): void
    {
        $rental = $this->rentFor($minutes);

        $this->assertSame(
            $minutes,
            (int) $rental->durationMinutes,
            'the selected minutes must be stored verbatim'
        );
        $this->assertSame(
            $formatted,
            $rental->durationFormatted,
            'the stored label must match the selected minutes'
        );
    }

    /**
     * @dataProvider durationProvider
     */
    public function test_return_deadline_is_start_plus_selected_minutes(int $minutes): void
    {
        $rental = $this->rentFor($minutes);

        $this->assertNotNull($rental->startTime);
        $this->assertNotNull($rental->expectedEndTime);

        // Return Deadline = Rental Start Time + Selected Duration (minutes)
        $this->assertSame(
            $rental->startTime->copy()->addMinutes($minutes)->timestamp,
            $rental->expectedEndTime->timestamp,
            'the deadline must be the start time plus the selected duration'
        );
    }

    /**
     * @dataProvider durationProvider
     */
    public function test_device_api_reports_a_countdown_matching_the_selected_minutes(int $minutes): void
    {
        $rental = $this->rentFor($minutes);
        $bicycle = Bicycle::findOrFail(1);

        $payload = $this->withHeaders([
            'X-API-Key' => $bicycle->issueDeviceKey(),
            'Accept' => 'application/json',
        ])->getJson("/api/iot/bicycle/{$bicycle->id}/rental");

        $payload->assertOk();

        $deviceRental = $payload->json('data.rental');

        // The ESP32 derives its countdown from returnDeadlineTimestamp, so this
        // is the number the LCD starts counting down from.
        $this->assertSame(
            $rental->expectedEndTime->timestamp,
            $deviceRental['returnDeadlineTimestamp'],
            'the device must receive the same return deadline'
        );
        $this->assertSame(
            $rental->startTime->timestamp,
            $deviceRental['startTimestamp'],
            'the device must receive the same start time'
        );

        // A fresh rental still has its whole duration ahead of it.
        $this->assertGreaterThanOrEqual(
            ($minutes - 5) * 60,
            $deviceRental['remainingSeconds'],
            'the countdown must begin near the full selected duration'
        );
        $this->assertLessThanOrEqual(
            $minutes * 60,
            $deviceRental['remainingSeconds'],
            'the countdown must not exceed the selected duration'
        );
        $this->assertFalse($deviceRental['isOverdue']);
    }

    public function test_odd_minute_durations_are_accepted(): void
    {
        $rental = $this->rentFor(37);

        $this->assertSame(37, (int) $rental->durationMinutes);
        $this->assertSame(
            $rental->startTime->copy()->addMinutes(37)->timestamp,
            $rental->expectedEndTime->timestamp
        );
    }

    public function test_duration_beyond_the_administrator_maximum_is_rejected(): void
    {
        SystemSetting::create([
            'key' => 'rentalMaxDurationHours',
            'value' => 2,
        ]);
        CacheRegistry::bumpSettingsVersion();

        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE, 'serialNumber' => 'PDY-001']);

        // 121 minutes is past the 2 hour (120 minute) ceiling.
        $this->actingAs($rider)
            ->post(route('rider.rent.store'), [
                'bicycleId' => $bicycle->id,
                'durationMinutes' => 121,
                'paymentMethod' => 'cash',
            ])
            ->assertSessionHasErrors('durationMinutes');

        $this->assertSame(0, Rental::count(), 'no rental may be created past the ceiling');
        $this->assertSame(Bicycle::STATUS_AVAILABLE, $bicycle->fresh()->status);
    }

    public function test_the_administrator_maximum_is_inclusive(): void
    {
        SystemSetting::create([
            'key' => 'rentalMaxDurationHours',
            'value' => 2,
        ]);
        CacheRegistry::bumpSettingsVersion();

        $rental = $this->rentFor(120);

        $this->assertSame(120, (int) $rental->durationMinutes);
    }

    public function test_720_minutes_is_accepted_and_721_is_rejected(): void
    {
        // Default ceiling is 12 hours (720 minutes): the boundary itself must
        // pass, and anything past it must fail, so a crafted POST cannot
        // bypass the limit the form enforces.
        $rental = $this->rentFor(720);

        $this->assertSame(720, (int) $rental->durationMinutes);

        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE, 'serialNumber' => 'PDY-002']);

        foreach ([721, 1000] as $tooLong) {
            $this->actingAs($rider)
                ->post(route('rider.rent.store'), [
                    'bicycleId' => $bicycle->id,
                    'durationMinutes' => $tooLong,
                    'paymentMethod' => 'cash',
                ])
                ->assertSessionHasErrors('durationMinutes');
        }

        $this->assertSame(1, Rental::count(), 'only the 720 minute rental may exist');
    }

    public function test_non_positive_and_non_numeric_durations_are_rejected(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE, 'serialNumber' => 'PDY-001']);

        foreach ([0, -30, '12.5', '60.9'] as $bad) {
            $this->actingAs($rider)
                ->post(route('rider.rent.store'), [
                    'bicycleId' => $bicycle->id,
                    'durationMinutes' => $bad,
                    'paymentMethod' => 'cash',
                ])
                ->assertSessionHasErrors('durationMinutes');
        }

        $this->actingAs($rider)
            ->post(route('rider.rent.store'), [
                'bicycleId' => $bicycle->id,
                'durationMinutes' => 'abc',
                'paymentMethod' => 'cash',
            ])
            ->assertSessionHasErrors('durationMinutes');

        $this->assertSame(0, Rental::count());
    }

    public function test_duration_is_required(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE, 'serialNumber' => 'PDY-001']);

        $this->actingAs($rider)
            ->post(route('rider.rent.store'), [
                'bicycleId' => $bicycle->id,
                'paymentMethod' => 'cash',
            ])
            ->assertSessionHasErrors('durationMinutes');

        $this->assertSame(0, Rental::count());
    }

    public function test_the_form_offers_a_minutes_field_with_presets(): void
    {
        $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE, 'serialNumber' => 'PDY-001']);
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);

        $html = $this->actingAs($rider)->get(route('rider.rentals.create'))->getContent();

        $this->assertStringContainsString('Rental Duration (minutes)', $html, 'the label must be in minutes');
        $this->assertStringNotContainsString('Rental Duration (hours)', $html, 'the hours field is gone');
        $this->assertStringContainsString('name="durationMinutes"', $html, 'the form posts minutes');

        // The requested presets are all offered.
        foreach ([15, 30, 45, 60, 90, 120] as $preset) {
            $this->assertStringContainsString(
                "setDuration({$preset})",
                $html,
                "a {$preset} minute preset must be offered"
            );
        }

        // The client-side guardrails must be rendered with the form.
        $this->assertStringContainsString('id="durationError"', $html, 'an inline error slot must exist');
        $this->assertStringContainsString(
            'Maximum rental duration is 720 minutes.',
            $html,
            'the over-limit message must be wired into the page script'
        );
        $this->assertStringContainsString(
            'Max 720 min (12 hours)',
            $html,
            'the ceiling hint must name the 12 hour equivalent'
        );
    }

    public function test_rentals_are_charged_at_150_per_minute_without_rounding(): void
    {
        $rental = $this->rentFor(20);

        $this->assertSame(20, (int) $rental->durationMinutes);
        $this->assertSame(30.00, (float) $rental->totalFee);
    }

    public function test_rider_history_shows_the_saved_duration(): void
    {
        $rental = $this->rentFor(30);
        $rider = $rental->rider;

        $html = $this->actingAs($rider)->get(route('rider.rentals.index'))->getContent();

        $this->assertStringContainsString(
            $rental->durationFormatted,
            $html,
            'the customer must see the duration that is actually stored'
        );
    }

    public function test_rider_history_uses_the_final_amount_collected_not_the_base_fee(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'status' => Rental::STATUS_RETURNED,
            'totalFee' => 15.00,
            'overdueFee' => 20.00,
            'finalFee' => 35.00,
            'paymentStatus' => 'paid',
        ]);

        Payment::create([
            'rentalId' => $rental->id,
            'userId' => $rider->id,
            'bicycleId' => $rental->bicycleId,
            'paymentReference' => 'PMT-HISTORY-FINAL',
            'paymentMethod' => 'cash',
            'amount' => 15.00,
            'latePenalty' => 20.00,
            'totalAmount' => 35.00,
            'amountPaid' => 35.00,
            'balanceDue' => 0,
            'currency' => 'PHP',
            'status' => 'paid',
        ]);

        $html = $this->actingAs($rider)->get(route('rider.rentals.index'))->getContent();

        $this->assertSame(35.00, $rental->fresh()->finalAmountCollected());
        $this->assertStringContainsString('<td>₱35.00</td>', $html);
        $this->assertStringNotContainsString('<td>₱15.00</td>', $html);
        $this->assertStringContainsString('"cost":"\u20b135.00"', $html);
    }
}
