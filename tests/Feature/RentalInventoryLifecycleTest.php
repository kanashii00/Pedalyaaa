<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\MaintenanceRecord;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\User;
use App\Services\MaintenanceService;
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

    private function maintenanceService(): MaintenanceService
    {
        return app(MaintenanceService::class);
    }

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

    public function test_inventory_withholds_end_ride_which_lives_in_active_rentals(): void
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

        // Ending a ride settles a rental, so it lives in Active Rentals only.
        // Offering it from the inventory row duplicated the action and made it
        // one mis-click away from terminating a ride.
        $this->assertStringNotContainsString(
            'data-end-ride',
            $html,
            'the inventory row no longer offers End Ride'
        );
        $this->assertStringNotContainsString(
            'data-rental-id="' . $rental->id . '"',
            $html,
            'no End Ride opener names the in-progress rental'
        );
        $this->assertStringNotContainsString(
            'endRideModal',
            $html,
            'the inventory page carries no End Ride dialog'
        );
        $this->assertStringNotContainsString(
            'end-ride',
            $html,
            'the inventory page exposes no end-ride endpoint'
        );

        // The rental itself is still fully visible and linked from the row.
        $this->assertStringContainsString(
            route('admin.rentals.show', $rental->id),
            $html,
            'the row still links to the live rental'
        );
        $this->assertStringNotContainsString(
            'data-modal-close>Return',
            $html,
            'Return Bicycle is withheld until the ride has ended'
        );

        // And the action is reachable from its single home.
        $queue = $this->actingAs($admin)->get(route('admin.rentals.index'))->getContent();
        $this->assertStringContainsString(
            'data-end-ride',
            $queue,
            'Active Rentals is where a ride is ended'
        );
        $this->assertStringContainsString(
            'data-rental-id="' . $rental->id . '"',
            $queue,
            'the Active Rentals opener names the rental it acts on'
        );
    }

    public function test_inventory_offers_no_view_rental_affordance(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => self::SERIAL,
        ]);

        // A live rental is the worst case: it is what used to add the button and
        // the dropdown item.
        app(RentalService::class)->startRental($rider, $bicycle->id, 60, 'cash');

        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->getContent();

        $this->assertStringNotContainsString(
            'View Rental',
            $html,
            'the inventory no longer offers a View Rental button or dropdown item'
        );
    }

    public function test_inventory_row_dialogs_are_rendered_outside_the_table(): void
    {
        $admin = $this->makeAdmin();
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => self::SERIAL,
        ]);

        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->getContent();

        // Browsers foster-parent non-table content written inside <tbody>, which
        // silently drops the dialogs out of the row layout.
        $tbodyStart = strpos($html, '<tbody>');
        $tbodyEnd = strpos($html, '</tbody>');
        $this->assertIsInt($tbodyStart, 'the inventory renders a table body');
        $this->assertIsInt($tbodyEnd);

        $body = substr($html, $tbodyStart, $tbodyEnd - $tbodyStart);
        $this->assertStringNotContainsString(
            'admin-modal',
            $body,
            'no dialog markup may live inside the table body'
        );

        // The remaining row dialogs (Edit, Return, Mark Maintenance) are still
        // hoisted out of the table, and none of them is the End Ride dialog,
        // which belongs to Active Rentals.
        $this->assertStringContainsString(
            'id="editBicycleModal',
            $html,
            'the row dialogs are still rendered on the page'
        );
        $this->assertStringNotContainsString(
            'id="endRideModal"',
            $html,
            'the End Ride dialog is not rendered on the inventory page'
        );
        $this->assertStringNotContainsString(
            'id="endRideForm"',
            $html,
            'no End Ride form is rendered on the inventory page'
        );
    }

    public function test_ending_a_ride_restores_the_inventory_row_in_one_step(): void
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
            ->put(route('admin.rentals.end-ride', $rental->id), [
                'condition' => Rental::CONDITION_GOOD,
                'note' => 'Returned in good shape.',
            ])
            ->assertSessionHasNoErrors();

        $after = $bicycle->fresh();
        $rental->refresh();

        $this->assertSame(Rental::STATUS_RETURNED, $rental->status, 'End Ride settles the rental outright');
        $this->assertSame(
            Rental::CONDITION_GOOD,
            $rental->returnCondition,
            'the inspected condition is recorded on the rental'
        );
        $this->assertSame('Returned in good shape.', $rental->returnNote);
        $this->assertNotNull($rental->returnProcessedAt, 'the settlement is timestamped');
        $this->assertSame('paid', $rental->paymentStatus, 'the final fee is settled on the spot');
        $this->assertSame(Bicycle::STATUS_AVAILABLE, $after->status, 'a good return frees the bicycle');
        $this->assertNull($after->currentRider, 'the renter pointer is cleared on return');
        $this->assertNull($after->currentRentalId, 'the rental pointer is cleared on return');
        $this->assertSame(Bicycle::LOCK_LOCKED, $after->lockStatus, 'a returned bicycle is locked again');

        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->getContent();
        $this->assertStringNotContainsString('View Rental', $html, 'a freed bicycle drops its rental actions');
        $this->assertStringNotContainsString(
            'data-end-ride',
            $html,
            'the inventory never offers End Ride, settled or not'
        );

        // The settled rental has left Active Rentals along with the row.
        $queue = $this->actingAs($admin)->get(route('admin.rentals.index'))->getContent();
        $this->assertStringNotContainsString(
            'data-rental-id="' . $rental->id . '"',
            $queue,
            'a settled rental is no longer offered an End Ride'
        );
    }

    public function test_ending_a_ride_routes_a_damaged_bicycle_to_maintenance(): void
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
            ->put(route('admin.rentals.end-ride', $rental->id), [
                'condition' => Rental::CONDITION_DAMAGED,
                'note' => 'Rear wheel is bent.',
            ])
            ->assertSessionHasNoErrors();

        $after = $bicycle->fresh();

        $this->assertSame(
            Bicycle::STATUS_MAINTENANCE,
            $after->status,
            'a damaged return takes the bicycle out of service'
        );
        $this->assertNull($after->currentRider);
        $this->assertNull($after->currentRentalId);

        // A status change on its own is a dead end: nothing appears in the
        // Maintenance module and the bicycle can be released again with no work
        // ever having been tracked.
        $record = MaintenanceRecord::where('bicycleId', $bicycle->id)
            ->whereIn('status', [MaintenanceRecord::STATUS_SCHEDULED, MaintenanceRecord::STATUS_IN_PROGRESS])
            ->latest()
            ->first();

        $this->assertNotNull($record, 'the inspection opens a work order');
        $this->assertStringContainsString($rental->rentalId, $record->description);
        $this->assertStringContainsString(Rental::CONDITION_DAMAGED, $record->description);
        $this->assertSame(
            $admin->id,
            $record->createdBy,
            'the work order is attributed to the administrator who inspected it'
        );
        $this->assertFalse(
            $this->maintenanceService()->canReleaseBicycle($after),
            'the bicycle stays held until the maintenance is completed'
        );
    }

    public function test_a_settled_rental_cannot_be_ended_twice(): void
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
            ->put(route('admin.rentals.end-ride', $rental->id), ['condition' => Rental::CONDITION_GOOD])
            ->assertSessionHasNoErrors();

        $afterFirst = $rental->fresh();
        $spendAfterFirst = $rider->fresh()->totalSpent;
        $paymentsAfterFirst = Payment::where('rental_id', $rental->id)->count();

        // A second click on an already-settled rental must be refused outright.
        $this->actingAs($admin)
            ->from(route('admin.bicycles.index'))
            ->put(route('admin.rentals.end-ride', $rental->id), ['condition' => Rental::CONDITION_GOOD])
            ->assertSessionHasErrors('rental');

        $this->assertSame(
            $afterFirst->totalFee,
            $rental->fresh()->totalFee,
            'the final fee must not be recalculated'
        );
        $this->assertSame(
            $spendAfterFirst,
            $rider->fresh()->totalSpent,
            'the rider is not charged twice'
        );
        $this->assertSame(
            $paymentsAfterFirst,
            Payment::where('rental_id', $rental->id)->count(),
            'no duplicate payment is recorded'
        );
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'rental_ended_by_admin',
            'details' => json_encode(['rentalId' => $rental->id, 'fee' => $afterFirst->totalFee]),
        ]);
    }

    public function test_ending_a_ride_settles_the_final_fee_and_moves_it_to_history(): void
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
            ->put(route('admin.rentals.end-ride', $rental->id), ['condition' => Rental::CONDITION_GOOD])
            ->assertSessionHasNoErrors();

        $settled = $rental->fresh();

        $this->assertSame(Rental::STATUS_RETURNED, $settled->status);
        $this->assertGreaterThan(0, (float) $settled->totalFee, 'the final fee is recorded');
        $this->assertSame((float) $settled->totalFee, (float) $settled->finalFee);
        $this->assertNotNull($settled->paidAt);

        // The settled rental must no longer sit in the active queue, and it must
        // show up in history instead. Scope the check to the table because the
        // success flash legitimately names the rental it just settled.
        $active = $this->actingAs($admin)
            ->get(route('admin.rentals.index', ['status' => 'active']))
            ->getContent();
        $tableStart = strpos($active, '<tbody');
        $tableEnd = strpos($active, '</tbody>');
        $this->assertIsInt($tableStart, 'the active queue renders a table body');
        $this->assertIsInt($tableEnd);
        $this->assertStringNotContainsString(
            $rental->rentalId,
            substr($active, $tableStart, $tableEnd - $tableStart),
            'a settled rental leaves the active queue'
        );

        $history = $this->actingAs($admin)
            ->get(route('admin.rentals.history'))
            ->getContent();
        $this->assertStringContainsString(
            $rental->rentalId,
            $history,
            'a settled rental is retained in history'
        );
    }

    public function test_the_inventory_page_renders_its_mark_maintenance_dialog(): void
    {
        $admin = $this->makeAdmin();
        $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => self::SERIAL,
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.bicycles.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'maintenanceBicycleModal',
            $html,
            'the Mark Maintenance dialog is rendered'
        );
        $this->assertStringContainsString(
            'name="bicycleId"',
            $html,
            'the dialog names the bicycle it acts on'
        );
    }

    public function test_the_inventory_page_loads_when_a_bicycle_is_already_in_maintenance(): void
    {
        $admin = $this->makeAdmin();
        $this->makeBicycle([
            'status' => Bicycle::STATUS_MAINTENANCE,
            'serialNumber' => self::SERIAL,
        ]);

        // This is the exact case that threw. The broken dialog was guarded by
        // @if($inMaintenanceFor($bike)), so a bicycle already under maintenance
        // was the only one that evaluated the unregistered route() call and blew
        // up the whole page.
        $this->actingAs($admin)
            ->get(route('admin.bicycles.index'))
            ->assertOk();
    }

    public function test_the_mark_maintenance_form_posts_to_the_registered_maintenance_route(): void
    {
        $admin = $this->makeAdmin();
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => self::SERIAL,
        ]);

        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->getContent();

        // Every route() call in the page must resolve to a real route.
        preg_match_all("/route\('([^']+)'/", $html, $m);
        foreach (array_unique($m[1]) as $name) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Route::has($name),
                "the inventory page references route [{$name}], which is not registered"
            );
        }

        // The form targets the existing maintenance endpoint, which validates
        // description + type and moves the bicycle to Maintenance.
        $this->assertStringContainsString(
            "action=\"" . e(route('admin.maintenance.store')) . '"',
            $html,
            'the Mark Maintenance form posts to the maintenance endpoint'
        );

        // Submitting it does the whole job in one request.
        $this->actingAs($admin)
            ->from(route('admin.bicycles.index'))
            ->post(route('admin.maintenance.store'), [
                'bicycleId' => $bicycle->id,
                'type' => 'repair',
                'severity' => 'high',
                'description' => 'Rear brake not responding.',
            ])
            ->assertRedirect(route('admin.bicycles.index'))
            ->assertSessionHasNoErrors();

        $after = $bicycle->fresh();

        $this->assertSame(Bicycle::STATUS_MAINTENANCE, $after->status, 'the bicycle is taken out of service');
        $this->assertSame(Bicycle::LOCK_LOCKED, $after->lockStatus);
        $this->assertDatabaseHas('maintenance_records', [
            'bicycleId' => $bicycle->id,
            'type' => 'repair',
            'severity' => 'high',
            'description' => 'Rear brake not responding.',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'maintenance_created']);
    }

    public function test_a_rented_bicycle_is_not_offered_the_maintenance_dialog(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => self::SERIAL,
        ]);

        app(RentalService::class)->startRental($rider, $bicycle->id, 60, 'cash');

        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->getContent();

        // A rented bicycle comes back through the return workflow first, so the
        // dialog must not be rendered for it.
        $this->assertStringNotContainsString(
            'maintenanceBicycleModal' . $bicycle->id,
            $html,
            'a bicycle on a ride must not offer Mark Maintenance'
        );
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
            route('admin.rentals.history', ['bicycle_id' => $bicycle->id]),
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
