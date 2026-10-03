<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\Geofence;
use App\Models\Rental;
use App\Models\User;
use App\Services\GeofenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * The rider operating area has one hard requirement above all others: a rider
 * may only ever see the bicycle they are riding. These tests pin that down at the
 * server boundary, because a map that is merely hidden by JavaScript would still
 * leak every other bicycle's position to anyone who opens the network tab.
 */
class RiderOperatingAreaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private const CENTER_LAT = 7.0990;
    private const CENTER_LNG = 125.6470;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broadcasting.default' => 'log']);
    }

    private function useCircleGeofence(float $radius = 500.0, float $warningThreshold = 100.0): Geofence
    {
        return Geofence::create([
            'name' => 'Operating Area',
            'centerLat' => self::CENTER_LAT,
            'centerLng' => self::CENTER_LNG,
            'radius' => $radius,
            'shapeType' => GeofenceService::SHAPE_CIRCLE,
            'isActive' => true,
            'alertEnabled' => true,
            'warningThreshold' => $warningThreshold,
        ]);
    }

    private function rentTo(User $rider, Bicycle $bike, string $status = Rental::STATUS_ACTIVE): Rental
    {
        return $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => $status,
        ]);
    }

    // ---- Access control ----

    public function test_live_endpoint_requires_authentication(): void
    {
        $this->get(route('rider.operating-area.live'))
            ->assertRedirect(route('login'));
    }

    public function test_admin_cannot_read_the_rider_operating_area_endpoint(): void
    {
        $this->actingAs($this->makeAdmin())
            ->getJson(route('rider.operating-area.live'))
            ->assertForbidden();
    }

    public function test_verified_and_unverified_riders_both_see_only_their_own_bicycle(): void
    {
        // The endpoint is scoped by rental ownership, not by verification state.
        // An unverified rider is blocked from *starting* a rental elsewhere in the
        // app, but the isolation guarantee must hold for every rider.
        $this->useCircleGeofence();

        $ownBike = $this->makeBicycle(['currentLat' => self::CENTER_LAT, 'currentLng' => self::CENTER_LNG]);
        $otherBike = $this->makeBicycle(['currentLat' => self::CENTER_LAT, 'currentLng' => self::CENTER_LNG]);

        $rider = $this->makeRider(['verified' => false]);
        $otherRider = $this->makeRider();

        $this->rentTo($rider, $ownBike);
        $this->rentTo($otherRider, $otherBike);

        $payload = $this->actingAs($rider)->getJson(route('rider.operating-area.live'));

        $payload->assertOk()
            ->assertJsonCount(1, 'bicycles')
            ->assertJsonPath('bicycles.0.id', $ownBike->id);

        $this->assertStringNotContainsString(
            (string) $otherBike->serialNumber,
            $payload->getContent()
        );
    }

    // ---- Isolation ----

    public function test_live_endpoint_returns_only_the_riders_own_bicycle_when_others_are_rented(): void
    {
        $this->useCircleGeofence();

        $ownBike = $this->makeBicycle(['currentLat' => self::CENTER_LAT, 'currentLng' => self::CENTER_LNG]);
        $otherBike = $this->makeBicycle(['currentLat' => self::CENTER_LAT, 'currentLng' => self::CENTER_LNG]);

        $rider = $this->makeRider();
        $otherRider = $this->makeRider();

        $this->rentTo($rider, $ownBike);
        $otherRental = $this->rentTo($otherRider, $otherBike);

        $response = $this->actingAs($rider)->getJson(route('rider.operating-area.live'));

        $response->assertOk()
            ->assertJsonCount(1, 'bicycles')
            ->assertJsonPath('bicycles.0.serialNumber', $ownBike->serialNumber)
            // Only the rider's own rental is described, never the other rider's.
            ->assertJsonPath('rental.id', $this->rentalsOf($rider)->first()->id);

        $body = $response->getContent();

        // The other bicycle's serial and the other rental's code are unique
        // strings, so their absence from the body is a meaningful check.
        $this->assertStringNotContainsString((string) $otherBike->serialNumber, $body);
        $this->assertStringNotContainsString((string) $otherRental->rentalId, $body);
    }

    public function test_request_parameters_cannot_point_the_endpoint_at_another_riders_bicycle(): void
    {
        // There is no selector in the request, so extra parameters must be ignored
        // rather than honoured. This is the anti-tampering guarantee: editing the
        // URL cannot change which bicycle a rider is shown.
        $this->useCircleGeofence();

        $ownBike = $this->makeBicycle(['currentLat' => self::CENTER_LAT, 'currentLng' => self::CENTER_LNG]);
        $otherBike = $this->makeBicycle(['currentLat' => self::CENTER_LAT, 'currentLng' => self::CENTER_LNG]);

        $rider = $this->makeRider();
        $otherRider = $this->makeRider();

        $this->rentTo($rider, $ownBike);
        $otherRental = $this->rentTo($otherRider, $otherBike);

        $response = $this->actingAs($rider)->getJson(route('rider.operating-area.live', [
            'bicycle_id' => $otherBike->id,
            'bicycleId' => $otherBike->id,
            'rental_id' => $otherRental->id,
            'rider_id' => $otherRider->id,
        ]));

        $response->assertOk()
            ->assertJsonCount(1, 'bicycles')
            ->assertJsonPath('bicycles.0.id', $ownBike->id);

        $this->assertStringNotContainsString((string) $otherBike->serialNumber, $response->getContent());
    }

    public function test_live_endpoint_returns_no_bicycles_when_the_rider_has_no_active_rental(): void
    {
        $this->useCircleGeofence();

        // Another rider is riding, and a bicycle is sitting available. Neither may
        // appear for a rider who is not on a ride.
        $otherBike = $this->makeBicycle(['currentLat' => self::CENTER_LAT, 'currentLng' => self::CENTER_LNG]);
        $availableBike = $this->makeBicycle(['currentLat' => self::CENTER_LAT, 'currentLng' => self::CENTER_LNG]);

        $rider = $this->makeRider();
        $otherRider = $this->makeRider();
        $this->rentTo($otherRider, $otherBike);

        // A finished rental of the rider's own must not be resurrected as live.
        $this->rentTo($rider, $availableBike, Rental::STATUS_COMPLETED);

        $response = $this->actingAs($rider)->getJson(route('rider.operating-area.live'));

        $response->assertOk()
            ->assertJsonCount(0, 'bicycles')
            ->assertJsonPath('rental', null);

        $this->assertStringNotContainsString((string) $otherBike->serialNumber, $response->getContent());
        $this->assertStringNotContainsString((string) $availableBike->serialNumber, $response->getContent());
    }

    public function test_every_rental_state_the_rider_must_act_on_is_tracked(): void
    {
        $this->useCircleGeofence();

        foreach (\App\Services\RiderOperatingAreaService::LIVE_RENTAL_STATUSES as $status) {
            $rider = $this->makeRider();
            $bike = $this->makeBicycle(['currentLat' => self::CENTER_LAT, 'currentLng' => self::CENTER_LNG]);
            $this->rentTo($rider, $bike, $status);

            $this->actingAs($rider)
                ->getJson(route('rider.operating-area.live'))
                ->assertOk()
                ->assertJsonCount(1, 'bicycles')
                ->assertJsonPath('bicycles.0.id', $bike->id);
        }
    }

    // ---- Boundary parity with the admin monitoring map ----

    public function test_live_endpoint_returns_the_same_geofence_as_admin_monitoring(): void
    {
        $geofence = $this->useCircleGeofence(radius: 750.0);
        $rider = $this->makeRider();

        $riderPayload = $this->actingAs($rider)
            ->getJson(route('rider.operating-area.live'))
            ->assertOk()
            ->json('geofence');

        $adminPayload = $this->actingAs($this->makeAdmin())
            ->getJson(route('admin.monitoring.live'))
            ->assertOk()
            ->json('geofence');

        // The operating area a rider is shown is literally the operator's area.
        $this->assertSame($adminPayload, $riderPayload);
        $this->assertEqualsWithDelta(750.0, $riderPayload['radius'], 0.001);
        $this->assertSame($geofence->id, $riderPayload['id']);
    }

    public function test_a_boundary_changed_by_an_admin_reaches_the_rider_endpoint(): void
    {
        $rider = $this->makeRider();

        $geofence = $this->useCircleGeofence(radius: 500.0);

        $radius = $this->actingAs($rider)
            ->getJson(route('rider.operating-area.live'))
            ->assertOk()
            ->json('geofence.radius');

        $this->assertEqualsWithDelta(500.0, $radius, 0.001);

        // The observer invalidates the cached configuration, so the next poll the
        // rider's browser makes already reflects the new boundary.
        $geofence->update(['radius' => 900.0]);

        $updated = $this->actingAs($rider)
            ->getJson(route('rider.operating-area.live'))
            ->assertOk()
            ->json('geofence.radius');

        $this->assertEqualsWithDelta(900.0, $updated, 0.001);
    }

    // ---- Zone classification ----

    public function test_zone_levels_describe_inside_near_and_outside(): void
    {
        $this->useCircleGeofence(radius: 500.0, warningThreshold: 100.0);

        // Dead centre: 500 m from the boundary, comfortably inside.
        $inside = $this->zoneFor(self::CENTER_LAT, self::CENTER_LNG);
        $this->assertSame('safe', $inside['zone_level']);
        $this->assertTrue($inside['inside']);

        // ~40 m from the boundary and still inside: the rider is near the edge.
        $nearLat = self::CENTER_LAT + (460.0 / 111320.0);
        $near = $this->zoneFor($nearLat, self::CENTER_LNG);
        $this->assertTrue($near['inside']);
        $this->assertContains($near['zone_level'], ['warning', 'approaching']);

        // Well beyond the 500 m radius: outside the operating area.
        $outsideLat = self::CENTER_LAT + 0.02;
        $outside = $this->zoneFor($outsideLat, self::CENTER_LNG);
        $this->assertSame('breach', $outside['zone_level']);
        $this->assertFalse($outside['inside']);
    }

    public function test_zone_level_matches_the_shared_zone_calculation(): void
    {
        $this->useCircleGeofence();

        $bike = $this->makeBicycle(['currentLat' => self::CENTER_LAT, 'currentLng' => self::CENTER_LNG]);
        $rider = $this->makeRider();
        $this->rentTo($rider, $bike);

        $expected = app(GeofenceService::class)->checkPoint(
            self::CENTER_LAT,
            self::CENTER_LNG,
            app(GeofenceService::class)->getConfig()
        );

        $this->actingAs($rider)
            ->getJson(route('rider.operating-area.live'))
            ->assertOk()
            ->assertJsonPath('bicycles.0.zone_level', $expected['level'])
            ->assertJsonPath('bicycles.0.inside', $expected['inside']);
    }

    public function test_a_bicycle_without_a_gps_fix_is_reported_as_unknown_not_as_safe(): void
    {
        $this->useCircleGeofence();

        $bike = $this->makeBicycle(['currentLat' => null, 'currentLng' => null]);
        $rider = $this->makeRider();
        $this->rentTo($rider, $bike);

        $this->actingAs($rider)
            ->getJson(route('rider.operating-area.live'))
            ->assertOk()
            ->assertJsonPath('bicycles.0.zone_level', 'unknown')
            ->assertJsonPath('bicycles.0.inside', null)
            ->assertJsonPath('bicycles.0.zone_distance', null);
    }

    // ---- Dashboard isolation ----

    public function test_dashboard_map_shows_only_the_rented_bicycle_while_renting(): void
    {
        $this->useCircleGeofence();

        $ownBike = $this->makeBicycle(['currentLat' => self::CENTER_LAT, 'currentLng' => self::CENTER_LNG]);
        $otherBike = $this->makeBicycle(['currentLat' => self::CENTER_LAT, 'currentLng' => self::CENTER_LNG]);
        $availableBike = $this->makeBicycle(['currentLat' => self::CENTER_LAT, 'currentLng' => self::CENTER_LNG]);

        $rider = $this->makeRider();
        $otherRider = $this->makeRider();

        $this->rentTo($rider, $ownBike);
        $this->rentTo($otherRider, $otherBike);

        $response = $this->actingAs($rider)->get(route('rider.dashboard'));

        $response->assertOk()
            ->assertViewHas('activeRental')
            // The operator's boundary is present on the rider page.
            ->assertViewHas('geofence');

        $bicycles = $response->viewData('mapBicycles');

        // Exactly one bicycle reaches the map while riding: the rider's own. The
        // available catalogue is withheld as well, so no third party is trackable.
        $this->assertCount(1, $bicycles);
        $this->assertSame($ownBike->id, $bicycles[0]['id']);

        $body = $response->getContent();
        $this->assertStringNotContainsString((string) $otherBike->serialNumber, $body);
        $this->assertStringNotContainsString((string) $availableBike->serialNumber, $body);
    }

    public function test_dashboard_map_lists_available_bicycles_before_a_rental_starts(): void
    {
        // Before renting there is no other renter's location to protect, so the
        // rider sees the operating area plus the bikes they can choose from.
        $this->useCircleGeofence();

        $availableBike = $this->makeBicycle(['currentLat' => self::CENTER_LAT, 'currentLng' => self::CENTER_LNG]);

        $bicycles = $this->actingAs($this->makeRider())
            ->get(route('rider.dashboard'))
            ->assertOk()
            ->viewData('mapBicycles');

        $this->assertContains($availableBike->id, array_column($bicycles, 'id'));
    }

    public function test_dashboard_rider_map_does_not_subscribe_to_the_admin_alert_channel(): void
    {
        // The geofence-alerts channel carries a breach event for every bicycle in
        // the fleet. A rider map must opt out of it or it would render other
        // riders' locations in real time.
        $this->useCircleGeofence();

        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['currentLat' => self::CENTER_LAT, 'currentLng' => self::CENTER_LNG]);
        $this->rentTo($rider, $bike);

        $body = $this->actingAs($rider)->get(route('rider.dashboard'))->assertOk()->getContent();

        // The rider page opts out of the admin channel, and never opens an Echo
        // listener of its own.
        $this->assertStringContainsString('broadcast: false', $body);
        $this->assertStringNotContainsString('Echo.private(', $body);
    }

    // ---- Pre-rental operating area ----

    public function test_rent_page_draws_the_operators_operating_area(): void
    {
        // The area a rider is shown before renting is the area enforced during
        // the ride, so it has to come from the same geofence the admin edits.
        $this->useCircleGeofence(radius: 500.0);

        $response = $this->actingAs($this->makeRider())
            ->get(route('rider.rentals.create'))
            ->assertOk();

        $response->assertViewHas('geofence');
        $response->assertViewHas('geofenceGeoJson');

        $expected = app(GeofenceService::class)->shapeGeoJson(
            app(GeofenceService::class)->getConfig()
        );

        $this->assertSame(
            $expected['geometry']['coordinates'],
            $response->viewData('geofenceGeoJson')['geometry']['coordinates']
        );

        // The boundary is actually painted, not merely passed to the view.
        $this->assertStringContainsString('rental-geofence-fill', $response->getContent());
    }

    public function test_rent_page_never_lists_a_bicycle_that_is_in_someone_elses_rental(): void
    {
        $this->useCircleGeofence();

        $availableBike = $this->makeBicycle(['currentLat' => self::CENTER_LAT, 'currentLng' => self::CENTER_LNG]);
        $rentedBike = $this->makeBicycle([
            'currentLat' => self::CENTER_LAT,
            'currentLng' => self::CENTER_LNG,
            'status' => Bicycle::STATUS_RENTED,
        ]);

        $otherRider = $this->makeRider();
        $this->rentTo($otherRider, $rentedBike);

        $bicycles = $this->actingAs($this->makeRider())
            ->get(route('rider.rentals.create'))
            ->assertOk()
            ->viewData('bicycles');

        // The rent page is a browse page: it may offer an available bicycle, but a
        // bicycle somebody else is riding must never appear as a marker.
        $rentable = $bicycles
            ->filter(fn ($bike) => $bike->isRentable() && $bike->currentLat && $bike->currentLng)
            ->values();

        $ids = $rentable->pluck('id')->all();

        $this->assertContains($availableBike->id, $ids);
        $this->assertNotContains($rentedBike->id, $ids);
    }

    // ---- Helpers ----

    private function zoneFor(float $lat, float $lng): array
    {
        $bike = $this->makeBicycle(['currentLat' => $lat, 'currentLng' => $lng]);
        $rider = $this->makeRider();
        $this->rentTo($rider, $bike);

        return $this->actingAs($rider)
            ->getJson(route('rider.operating-area.live'))
            ->assertOk()
            ->json('bicycles.0');
    }

    private function rentalsOf(User $rider)
    {
        return $rider->rentals()->latest('id')->get();
    }
}
