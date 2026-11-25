<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\Rental;
use App\Models\User;
use App\Services\RentalService;
use App\Services\RiderCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * The Rent page must describe the bicycle that exists right now.
 *
 * Two separate failures are covered here. First, availability: the page used to
 * be served from a 300 second cache, so it could advertise a bicycle that was
 * already claimed or hide one that had just been freed, and the POST trusted the
 * card it was given. Second, rendering: bicycle rate/battery/name were
 * interpolated straight into an inline onclick(), where a null column or a quote
 * in a name produced NaN or a syntax error that killed the whole script block,
 * taking every card on the page down with it.
 */
class RentLiveAvailabilityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pedalya.single_bike_mode.enabled' => false]);
    }

    private function rider(array $overrides = []): User
    {
        return $this->makeRider(array_merge([
            'verified' => true,
            'role' => User::ROLE_RIDER,
        ], $overrides));
    }

    /**
     * Assert no rendered value is NaN or Infinity.
     *
     * The word "NaN" appears legitimately in the page's own guard code
     * (isNaN checks), so only values outside script/style are inspected.
     */
    private function assertNoNonFinite(string $html): void
    {
        // Drop <script> and <style> bodies; the guards legitimately mention NaN.
        $markup = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/si', '', $html);

        $this->assertDoesNotMatchRegularExpression(
            '/\bNaN\b/',
            $markup,
            'no rendered value may be NaN'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\bInfinity\b/',
            $markup,
            'no rendered value may be Infinity'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\bundefined\b/',
            $markup,
            'no rendered value may be undefined'
        );
    }

    /** The JSON payload a card hands to selectBicycle(), decoded as a browser would. */
    private function cardPayload(string $html, string $serial): array
    {
        $this->assertMatchesRegularExpression(
            '/data-bike-payload="([^"]*)"/',
            $html,
            "bicycle {$serial} offers a rent button carrying a payload"
        );

        preg_match('/data-bike-payload="([^"]*)"/', $html, $m);

        // Blade HTML-escapes the attribute, exactly as the browser decodes it.
        $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

        $this->assertIsArray(
            $decoded,
            "the payload for {$serial} must be valid JSON once the browser unescapes it"
        );

        return $decoded;
    }

    // ---------------- Availability is read live ----------------

    public function test_the_rent_page_reflects_status_changes_made_after_it_rendered(): void
    {
        $rider = $this->rider();
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => 'PDY-A1',
        ]);

        // The rider loads the page and picks up this bicycle's card.
        $this->actingAs($rider)
            ->get(route('rider.rentals.create'))
            ->assertOk()
            ->assertSee('PDY-A1');

        // Someone else takes it before the rider submits.
        $other = $this->rider();
        app(RentalService::class)->startRental($other, $bicycle->id, 60, 'cash');

        $this->actingAs($rider)
            ->from(route('rider.rentals.create'))
            ->post(route('rider.rent.store'), [
                'bicycleId' => $bicycle->id,
                'durationMinutes' => 60,
                'paymentMethod' => 'cash',
            ])
            ->assertSessionHasErrors('bicycleId');

        $this->assertSame(
            1,
            Rental::count(),
            'the stale card must not produce a second rental on one bicycle'
        );
        $this->assertSame(
            $other->id,
            $bicycle->fresh()->currentRider,
            'the bicycle stays with the rider who actually claimed it'
        );
    }

    public function test_a_stale_card_cannot_rent_a_bicycle_sent_to_maintenance(): void
    {
        $rider = $this->rider();
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => 'PDY-A2',
        ]);

        $this->actingAs($rider)->get(route('rider.rentals.create'))->assertOk();

        $bicycle->update(['status' => Bicycle::STATUS_MAINTENANCE]);

        $this->actingAs($rider)
            ->from(route('rider.rentals.create'))
            ->post(route('rider.rent.store'), [
                'bicycleId' => $bicycle->id,
                'durationMinutes' => 60,
                'paymentMethod' => 'cash',
            ])
            ->assertSessionHasErrors('bicycleId');

        $this->assertSame(0, Rental::count());
    }

    public function test_a_bicycle_freed_by_end_ride_can_be_rented_on_the_next_page_load(): void
    {
        $admin = $this->makeAdmin();
        $holder = $this->rider();
        $taker = $this->rider();
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => 'PDY-A3',
        ]);

        $rental = app(RentalService::class)->startRental($holder, $bicycle->id, 60, 'cash');

        $this->actingAs($admin)
            ->put(route('admin.rentals.end-ride', $rental->id), ['condition' => Rental::CONDITION_GOOD])
            ->assertSessionHasNoErrors();

        // The freed bicycle must be offered, and renting it must actually work.
        $this->actingAs($taker)
            ->get(route('rider.rentals.create'))
            ->assertOk()
            ->assertSee('PDY-A3');

        $this->actingAs($taker)
            ->post(route('rider.rent.store'), [
                'bicycleId' => $bicycle->id,
                'durationMinutes' => 60,
                'paymentMethod' => 'cash',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(Bicycle::STATUS_RENTED, $bicycle->fresh()->status);
        $this->assertSame($taker->id, $bicycle->fresh()->currentRider);
    }

    public function test_a_bicycle_routed_to_maintenance_by_end_ride_is_not_offered(): void
    {
        $admin = $this->makeAdmin();
        $holder = $this->rider();
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => 'PDY-A4',
        ]);

        $rental = app(RentalService::class)->startRental($holder, $bicycle->id, 60, 'cash');

        $this->actingAs($admin)
            ->put(route('admin.rentals.end-ride', $rental->id), ['condition' => Rental::CONDITION_DAMAGED])
            ->assertSessionHasNoErrors();

        // The card is still listed - the page shows the fleet and marks an
        // unselectable bicycle rather than hiding it - but it is labelled
        // Maintenance and offers no enabled button.
        $this->actingAs($this->rider())
            ->get(route('rider.rentals.create'))
            ->assertOk()
            ->assertDontSee('Select & Rent', escape: false)
            ->assertSee('Maintenance');
    }

    public function test_the_availability_list_is_never_written_to_the_cache(): void
    {
        $rider = $this->rider();
        $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);

        app(RiderCacheService::class)->availableBicycles();

        $this->assertSame(
            1,
            app(RiderCacheService::class)->availableBicycles()->count(),
            'the service reads live rows rather than replaying a cached list'
        );

        $this->actingAs($rider)->get(route('rider.rentals.create'))->assertOk();

        $this->assertFalse(
            \Illuminate\Support\Facades\Cache::has(\App\Services\CacheRegistry::availableBicyclesKey(false)),
            'no availability entry may be left behind for a later request to reuse'
        );
    }

    // ---------------- Rendering cannot produce NaN ----------------

    public function test_a_zero_hourly_rate_bicycle_uses_the_fixed_minute_rate(): void
    {
        $rider = $this->rider();
        $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => 'PDY-N1',
            'hourlyRate' => 0,
            'batteryLevel' => 90,
        ]);

        $html = $this->actingAs($rider)->get(route('rider.rentals.create'))->getContent();

        $payload = $this->cardPayload($html, 'PDY-N1');

        $this->assertIsNumeric($payload['ratePerMinute'], 'the rate reaches the client as a number');
        $this->assertSame(RentalService::RENTAL_RATE_PER_MINUTE, (float) $payload['ratePerMinute']);
        $this->assertSame(90, $payload['battery']);
        $this->assertNoNonFinite($html);
    }

    public function test_bicycle_without_reported_battery_is_not_shown_as_rentable(): void
    {
        $rider = $this->rider();
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => 'PDY-N5',
            'batteryLevel' => null,
            'lockStatus' => null,
        ]);

        $html = $this->actingAs($rider)->get(route('rider.rentals.create'))->getContent();

        $this->assertStringContainsString('Battery data unavailable', $html);
        $this->assertStringNotContainsString('data-bike-payload="{&quot;id&quot;:'.$bicycle->id, $html);
        $this->actingAs($rider)->post(route('rider.rent.store'), [
            'bicycleId' => $bicycle->id,
            'paymentMethod' => 'cash',
            'durationMinutes' => 60,
        ])->assertSessionHasErrors('bicycleId');

        $this->assertSame(0, Rental::count());
    }

    public function test_bicycle_hourly_rate_does_not_override_the_fixed_minute_estimate(): void
    {
        $rider = $this->rider();
        $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => 'PDY-N2',
            'hourlyRate' => 0,
        ]);

        $html = $this->actingAs($rider)->get(route('rider.rentals.create'))->getContent();

        $this->assertStringContainsString('"ratePerMinute":1.5', $html);
        $this->assertNoNonFinite($html);
    }

    public function test_the_estimate_rate_matches_the_rate_that_is_charged(): void
    {
        $rider = $this->rider();
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => 'PDY-N4',
            'hourlyRate' => 27.5,
        ]);

        $html = $this->actingAs($rider)->get(route('rider.rentals.create'))->getContent();
        $payload = $this->cardPayload($html, 'PDY-N4');

        $rental = app(RentalService::class)->startRental($rider, $bicycle->id, 60, 'cash');

        $this->assertSame(
            RentalService::RENTAL_RATE_PER_MINUTE,
            (float) $payload['ratePerMinute'],
            'the card uses the same fixed per-minute rate as rental creation'
        );
        $this->assertSame(RentalService::RENTAL_RATE_PER_HOUR, (float) $rental->ratePerHour);
        $this->assertSame(90.0, (float) $rental->totalFee);
    }

    public function test_a_quote_in_a_bicycle_name_cannot_break_the_script_block(): void
    {
        $rider = $this->rider();
        $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => 'PDY-N3',
            // A name like this used to terminate the inline string and take the
            // entire <script> block down with it.
            'name' => "Bob's \"Flagship\" <b>",
        ]);

        $html = $this->actingAs($rider)->get(route('rider.rentals.create'))->getContent();

        $payload = $this->cardPayload($html, 'PDY-N3');

        $this->assertSame(
            "PDY-N3 - Bob's \"Flagship\" <b>",
            $payload['name'],
            'the name survives the round trip unchanged rather than being truncated at the quote'
        );
        $this->assertIsInt($payload['id']);
    }

    public function test_the_cost_estimate_is_computed_from_finite_numbers_only(): void
    {
        $html = file_get_contents(base_path('resources/views/rider/rent.blade.php'));

        // Every path that produces money goes through the single finite guard, so
        // a bad duration or rate cannot render "NaN" or "Infinity" into the DOM.
        $this->assertSame(
            1,
            substr_count($html, 'if (!isFinite(n) || isNaN(n)) n = 0;'),
            'money() is the single place that formats an amount'
        );
        $this->assertStringContainsString('function money(amount)', $html);
        $this->assertStringContainsString("document.getElementById('costDisplay').textContent = money(total);", $html);
    }

    public function test_rent_card_shows_the_bicycle_category_and_iot_enabled_flag(): void
    {
        $rider = $this->rider();
        $this->makeBicycle([
            'serialNumber' => 'PDY-001',
            'name' => 'Beach Cruiser 001',
            'model' => 'Urban Cruiser',
            'status' => Bicycle::STATUS_AVAILABLE,
        ]);

        $html = $this->actingAs($rider)->get(route('rider.rentals.create'))->getContent();

        $this->assertStringContainsString('Category:</strong> Urban Cruiser', $html);
        $this->assertStringContainsString('IoT-enabled:</strong> Yes', $html);
    }

    public function test_the_map_only_markers_available_bicycles_with_a_usable_position(): void
    {
        $rider = $this->rider();

        $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => 'PDY-M1',
            'currentLat' => 7.1001,
            'currentLng' => 125.6480,
        ]);
        $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => 'PDY-M2',
            // No fix yet: a 0,0 pin is not a real location.
            'currentLat' => 0,
            'currentLng' => 0,
        ]);

        $html = $this->actingAs($rider)->get(route('rider.rentals.create'))->getContent();

        $this->assertMatchesRegularExpression('/var bicycles = (\[.*?\]);/s', $html, 'the map data is emitted as JSON');
        preg_match('/var bicycles = (\[.*?\]);/s', $html, $m);

        $markers = json_decode($m[1], true);

        $this->assertIsArray($markers, 'the map payload must decode cleanly');
        $this->assertCount(1, $markers, 'a bicycle with no usable position is not pinned');

        $serials = array_map(fn ($m) => explode(' ', $m['name'])[0], $markers);
        $this->assertSame(['PDY-M1'], $serials);

        $this->assertIsFloat($markers[0]['lat']);
        $this->assertIsFloat($markers[0]['lng']);
        $this->assertSame(7.1001, round($markers[0]['lat'], 4));
        $this->assertSame(125.648, round($markers[0]['lng'], 3));
    }

    public function test_a_maintenance_bicycle_is_never_pinned_on_the_map(): void
    {
        $rider = $this->rider();
        $this->makeBicycle([
            'status' => Bicycle::STATUS_MAINTENANCE,
            'serialNumber' => 'PDY-M3',
            'currentLat' => 7.1001,
            'currentLng' => 125.6480,
        ]);

        $html = $this->actingAs($rider)->get(route('rider.rentals.create'))->getContent();

        preg_match('/var bicycles = (\[.*?\]);/s', $html, $m);
        $markers = json_decode($m[1], true);

        $this->assertIsArray($markers);
        $this->assertSame([], $markers, 'a bicycle out of service is not offered as Available');
    }

    // ---------------- One rental per submission ----------------

    public function test_a_rider_cannot_start_a_second_rental_by_submitting_twice(): void
    {
        $rider = $this->rider();
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => 'PDY-D1',
        ]);

        $payload = [
            'bicycleId' => $bicycle->id,
            'durationMinutes' => 60,
            'paymentMethod' => 'cash',
        ];

        $this->actingAs($rider)->post(route('rider.rent.store'), $payload)
            ->assertSessionHasNoErrors();

        // The second click arrives while the first is still being processed.
        $this->actingAs($rider)->post(route('rider.rent.store'), $payload)
            ->assertSessionHasErrors('bicycleId');

        $this->assertSame(
            1,
            Rental::count(),
            'a double submission must not start a second rental'
        );
        $this->assertSame(
            1,
            $rider->fresh()->totalRentals,
            'the rider is counted once'
        );
    }

    public function test_the_rent_page_locks_the_form_against_resubmission(): void
    {
        $rider = $this->rider();
        $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);

        $html = $this->actingAs($rider)->get(route('rider.rentals.create'))->getContent();

        $this->assertStringContainsString(
            'rentalSubmitting',
            $html,
            'the page tracks that a submission is in flight'
        );
        $this->assertStringContainsString(
            "form.dataset.submitting = '1'",
            $html,
            'the form itself is flagged, so a second Enter keypress is refused'
        );
        $this->assertStringContainsString(
            'btn.disabled = true',
            $html,
            'the confirm button is disabled while the request is out'
        );
    }

    public function test_only_one_rider_can_claim_a_bicycle(): void
    {
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => 'PDY-D2',
        ]);

        $first = $this->rider();
        $second = $this->rider();

        app(RentalService::class)->startRental($first, $bicycle->id, 60, 'cash');

        $thrown = null;

        try {
            app(RentalService::class)->startRental($second, $bicycle->id, 60, 'cash');
        } catch (\App\Exceptions\RentalException $e) {
            $thrown = $e->getMessage();
        }

        $this->assertNotNull($thrown, 'the second rider is refused at the service layer too');
        $this->assertSame(1, Rental::count());
        $this->assertSame($first->id, $bicycle->fresh()->currentRider);
    }
}