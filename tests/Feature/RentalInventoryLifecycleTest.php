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
 * End-to-end contract between Bicycle Inventory and Rental Management.
 *
 * The bicycle row is the single live record: it must reflect the rental
 * lifecycle (renter, rental pointer, lock, status) at every step, and the
 * actions the inventory offers must match the state the server accepts.
 */
class RentalInventoryLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private const SERIAL = 'PDY-042';

    public function test_inventory_surfaces_the_full_live_rental_state(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => self::SERIAL,
        ]);

        $rental = app(RentalService::class)->startRental($rider, $bicycle->id, 120, 'cash');

        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->getContent();

        $this->assertStringContainsString(self::SERIAL, $html, 'serial identifies the bicycle');

        // Identity of the renter and the rental, resolved via the bicycleId relation.
        $this->assertStringContainsString($rider->name, $html, 'inventory shows the current renter');
        $this->assertStringContainsString($rental->rentalId, $html, 'inventory shows the current rental');

        // Start, expected return and duration so the row is readable at a glance.
        $this->assertStringContainsString(
            $rental->startTime->format('M j, g:i A'),
            $html,
            'inventory shows when the rental started'
        );
        $this->assertStringContainsString(
            $rental->expectedEndTime->format('M j, g:i A'),
            $html,
            'inventory shows the expected return time'
        );
        $this->assertNotNull(
            $rental->fresh()->durationFormatted,
            'the rental stores a human readable duration'
        );
        $this->assertStringContainsString(
            $rental->fresh()->durationFormatted,
            $html,
            'inventory shows the rental duration'
        );

        // The row must link across to the rental it is holding.
        $this->assertStringContainsString(
            route('admin.rentals.show', $rental->id),
            $html,
            'inventory links to the live rental'
        );
    }

    public function test_inventory_offers_end_ride_while_a_rental_is_running(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => self::SERIAL,
        ]);

        $rental = app(RentalService::class)->startRental($rider, $bicycle->id, 60, 'cash');
        $this->assertSame(Rental::STATUS_ACTIVE, $rental->fresh()->status);

        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->getContent();

        $this->assertStringContainsString(
            route('admin.rentals.end-ride', $rental->id),
            $html,
            'an in-progress rental offers End Ride'
        );
        $this->assertStringNotContainsString(
            route('admin.rentals.process-return', $rental->id),
            $html,
            'Return Bicycle is withheld until the ride has ended'
        );
    }

    public function test_returning_a_bicycle_restores_the_inventory_row(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => self::SERIAL,
        ]);

        $rental = app(RentalService::class)->startRental($rider, $bicycle->id, 60, 'cash');
        $this->actingAs($admin)
            ->from(route('admin.bicycles.index'))
            ->put(route('admin.rentals.end-ride', $rental->id))
            ->assertSessionHasNoErrors();

        $rental->refresh();
        $this->assertContains(
            $rental->status,
            [Rental::STATUS_EXPIRED, Rental::STATUS_AWAITING_RETURN, Rental::STATUS_OVERDUE_RETURN],
            'ending the ride must move the rental into the return workflow'
        );

        $this->actingAs($admin)->put(route('admin.rentals.process-return', $rental->id), [
            'condition' => Rental::CONDITION_GOOD,
            'note' => 'Returned in good shape.',
        ]);

        $after = $bicycle->fresh();
        $rental->refresh();

        $this->assertSame(Rental::STATUS_RETURNED, $rental->status);
        $this->assertSame(Bicycle::STATUS_AVAILABLE, $after->status, 'a good return frees the bicycle');
        $this->assertNull($after->currentRider, 'the renter pointer is cleared on return');
        $this->assertNull($after->currentRentalId, 'the rental pointer is cleared on return');
        $this->assertSame(Bicycle::LOCK_LOCKED, $after->lockStatus, 'a returned bicycle is locked again');

        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->getContent();
        $this->assertStringNotContainsString('View Rental', $html, 'a freed bicycle drops its rental actions');
    }

    public function test_returning_a_damaged_bicycle_routes_it_to_maintenance(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => self::SERIAL,
        ]);

        $rental = app(RentalService::class)->startRental($rider, $bicycle->id, 60, 'cash');
        $this->actingAs($admin)
            ->from(route('admin.bicycles.index'))
            ->put(route('admin.rentals.end-ride', $rental->id))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->put(route('admin.rentals.process-return', $rental->id), [
            'condition' => Rental::CONDITION_DAMAGED,
            'note' => 'Rear wheel is bent.',
        ]);

        $after = $bicycle->fresh();

        $this->assertSame(
            Bicycle::STATUS_MAINTENANCE,
            $after->status,
            'a damaged return takes the bicycle out of service'
        );
        $this->assertNull($after->currentRider);
        $this->assertNull($after->currentRentalId);
    }

    public function test_cancelling_a_rental_frees_the_bicycle(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => self::SERIAL,
        ]);

        $rental = app(RentalService::class)->startRental($rider, $bicycle->id, 60, 'cash');

        $this->actingAs($admin)->put(route('admin.rentals.cancel', $rental->id));

        $after = $bicycle->fresh();
        $rental->refresh();

        $this->assertSame(Rental::STATUS_CANCELLED, $rental->status);
        $this->assertSame(Bicycle::STATUS_AVAILABLE, $after->status);
        $this->assertNull($after->currentRider);
        $this->assertNull($after->currentRentalId);
        $this->assertSame(Bicycle::LOCK_LOCKED, $after->lockStatus);
    }

    public function test_cancelling_never_pulls_a_bicycle_out_of_maintenance(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => self::SERIAL,
        ]);

        $rental = app(RentalService::class)->startRental($rider, $bicycle->id, 60, 'cash');

        // Something takes the bicycle out of service while the rental is open.
        $bicycle->update([
            'status' => Bicycle::STATUS_MAINTENANCE,
            'lockStatus' => Bicycle::LOCK_LOCKED,
        ]);

        $this->actingAs($admin)->put(route('admin.rentals.cancel', $rental->id));

        $after = $bicycle->fresh();

        $this->assertSame(Rental::STATUS_CANCELLED, $rental->fresh()->status);
        $this->assertSame(
            Bicycle::STATUS_MAINTENANCE,
            $after->status,
            'cancellation must not silently return a broken bicycle to service'
        );
        $this->assertNull($after->currentRider);
        $this->assertNull($after->currentRentalId);
    }

    public function test_lock_commands_are_refused_while_a_bicycle_is_rented(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => self::SERIAL,
        ]);

        app(RentalService::class)->startRental($rider, $bicycle->id, 60, 'cash');

        $this->actingAs($admin)
            ->from(route('admin.bicycles.index'))
            ->post(route('admin.bicycles.lock', $bicycle->id), ['action' => 'unlock'])
            ->assertSessionHasErrors('bicycle');

        $this->assertSame(
            Bicycle::LOCK_LOCKED,
            $bicycle->fresh()->lockStatus,
            'a rented bicycle must not be remotely unlocked'
        );
    }

    public function test_marking_a_bicycle_for_maintenance_takes_it_out_of_service(): void
    {
        $admin = $this->makeAdmin();
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => self::SERIAL,
        ]);

        $this->actingAs($admin)->post(route('admin.maintenance.store'), [
            'bicycleId' => $bicycle->id,
            'type' => 'repair',
            'severity' => 'high',
            'description' => 'Brake pads worn through.',
        ])->assertSessionHasNoErrors();

        $after = $bicycle->fresh();

        $this->assertSame(Bicycle::STATUS_MAINTENANCE, $after->status);
        $this->assertSame(Bicycle::LOCK_LOCKED, $after->lockStatus);
    }

    public function test_inventory_row_history_and_detail_links_follow_the_serial_key(): void
    {
        $admin = $this->makeAdmin();
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => self::SERIAL,
        ]);

        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->getContent();

        $this->assertStringContainsString(
            route('admin.rentals.index', ['bicycle_id' => $bicycle->id]),
            $html,
            'every bicycle can be traced to its rental history'
        );
        $this->assertStringContainsString(
            route('admin.bicycles.show', $bicycle->id),
            $html,
            'every bicycle can be opened in the inventory detail view'
        );
    }

    public function test_rentals_module_filters_and_links_bicycles_by_serial(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => self::SERIAL,
            'name' => 'Harbour Cruiser',
        ]);

        // A live rental so the module renders a row for that bicycle.
        app(RentalService::class)->startRental($rider, $bicycle->id, 60, 'cash');

        $html = $this->actingAs($admin)->get(route('admin.rentals.index'))->getContent();

        $this->assertStringContainsString(
            self::SERIAL . ' - Harbour Cruiser',
            $html,
            'the rentals bicycle filter is keyed by the serial number'
        );
        $this->assertStringContainsString(
            route('admin.bicycles.show', $bicycle->id),
            $html,
            'a rental row links back to the inventory record for that bicycle'
        );

        // Filtering by the bicycle key must scope the module to that bicycle.
        $filtered = $this->actingAs($admin)
            ->get(route('admin.rentals.index', ['bicycle_id' => $bicycle->id]))
            ->getContent();

        $this->assertStringContainsString(
            self::SERIAL,
            $filtered,
            'the serial-keyed filter keeps the bicycle identifiable'
        );
    }
}
