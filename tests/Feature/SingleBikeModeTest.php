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
 * Single-bike demo mode.
 *
 * Only the featured bicycle is offered on the rider rent page; the rest of the
 * available fleet stays on screen but greyed out and unselectable. The flag and
 * the bicycle's live availability are two independent gates - a card is only
 * actionable when both pass - and the same limit is re-checked in
 * RentController::store() so a crafted POST cannot rent a greyed-out bicycle.
 */
class SingleBikeModeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function makeFleet(int $count = 4): array
    {
        $bicycles = collect();

        for ($i = 1; $i <= $count; $i++) {
            $bicycles->push($this->makeBicycle([
                'serialNumber' => 'PDY-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'name' => 'Test Bike ' . $i,
                'status' => Bicycle::STATUS_AVAILABLE,
                'batteryLevel' => 90,
            ]));
        }

        return $bicycles->all();
    }

    public function test_only_the_featured_bicycle_is_selectable(): void
    {
        config(['pedalya.single_bike_mode' => ['enabled' => true, 'bicycle_id' => 1]]);

        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $this->makeFleet();

        $html = $this->actingAs($rider)->get(route('rider.rentals.create'))->getContent();

        // The featured bike keeps its working button. Identity now travels as a
        // JSON payload on the trigger rather than as inline JS arguments, so the
        // card is matched on its payload, not on a call signature.
        $this->assertStringContainsString('&quot;id&quot;:1', $html, 'the featured bicycle offers a rent button');

        // Every other bike is rendered but inert.
        for ($id = 2; $id <= 4; $id++) {
            $this->assertStringNotContainsString(
                '&quot;id&quot;:'.$id . ',',
                $html,
                "bicycle {$id} must not be selectable"
            );
        }

        $this->assertSame(
            1,
            substr_count($html, 'Select & Rent'),
            'exactly one bicycle may offer the rent button'
        );
        // Counted on the badge markup so each withheld card contributes exactly one
// hit; the label also appears on its disabled button.
        $this->assertSame(
            3,
            substr_count($html, 'badge-unavailable">Unavailable'),
            'the other three are withheld by the feature flag'
        );

        $this->assertSame(
            0,
            substr_count($html, 'Not In Service'),
            'a withheld bicycle is still in service - only the feature flag gates it'
        );

        // Matched on the card markup only: the <style> block always ships the
        // .bicycle-card-unavailable rules, whether or not any card uses them.
        $this->assertSame(
            3,
            substr_count($html, 'fade-in-up bicycle-card-unavailable'),
            'the other three cards should be dimmed'
        );
    }

    public function test_storing_a_greyed_out_bicycle_is_rejected(): void
    {
        config(['pedalya.single_bike_mode' => ['enabled' => true, 'bicycle_id' => 1]]);

        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $this->makeFleet();

        $response = $this->actingAs($rider)->post(route('rider.rent.store'), [
            'bicycleId' => 3,
            'paymentMethod' => 'cash',
            'durationMinutes' => 60,
        ]);

        $response->assertSessionHasErrors('bicycleId');
        $this->assertSame(0, Rental::count(), 'no rental should be created for a greyed-out bicycle');
    }

    public function test_the_featured_bicycle_can_still_be_rented(): void
    {
        config(['pedalya.single_bike_mode' => ['enabled' => true, 'bicycle_id' => 1]]);

        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $this->makeFleet();

        $this->actingAs($rider)->post(route('rider.rent.store'), [
            'bicycleId' => 1,
            'paymentMethod' => 'cash',
            'durationMinutes' => 60,
        ]);

        $this->assertSame(1, Rental::count());
        $this->assertSame(1, Rental::first()->bicycleId);
    }

    public function test_disabling_the_mode_restores_the_whole_fleet(): void
    {
        config(['pedalya.single_bike_mode' => ['enabled' => false, 'bicycle_id' => 1]]);

        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $this->makeFleet();

        $html = $this->actingAs($rider)->get(route('rider.rentals.create'))->getContent();

        for ($id = 1; $id <= 4; $id++) {
            $this->assertStringContainsString('&quot;id&quot;:'.$id . ',', $html);
        }

        $this->assertStringNotContainsString('fade-in-up bicycle-card-unavailable', $html);
        $this->assertSame(4, substr_count($html, 'Select & Rent'));

        // And the server-side guard is off too.
        $this->actingAs($rider)->post(route('rider.rent.store'), [
            'bicycleId' => 3,
            'paymentMethod' => 'cash',
            'durationMinutes' => 60,
        ]);

        $this->assertSame(1, Rental::count());
    }
}
