<?php

namespace Tests\Feature;

use App\Exceptions\RentalException;
use App\Models\Bicycle;
use App\Models\DeviceCommand;
use App\Models\DeviceStatus;
use App\Models\MaintenanceRecord;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\User;
use App\Services\DeviceCommandService;
use App\Services\MaintenanceService;
use App\Services\RentalService;
use App\Services\RiderCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * Only PDY-001 - Beach Cruiser 001 - is deployed.
 *
 * Every other bicycle in the inventory is parked: no smart lock, no servo, no
 * ESP32. Its recorded lockStatus is the physical truth and is intentional, so
 * nothing in the rental or availability logic may rewrite it, and no hardware
 * command may ever be queued for it.
 *
 * These tests pin that contract from both directions: the deployed unit behaves
 * exactly as before, and a parked unit is inert under every code path that used
 * to touch its lock.
 */
class FleetServicePolicyTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    /**
     * A parked bicycle keeps this exact lock status through every transition.
     * Deliberately `unlocked`, because a parked bicycle is not necessarily
     * locked - that is precisely why the logic must stop assuming it is.
     */
    private const PARKED_LOCK_STATUS = Bicycle::LOCK_UNLOCKED;

    private function deployBeachCruiser(): Bicycle
    {
        return $this->makeBicycle([
            'serialNumber' => 'PDY-001',
            'name' => 'Beach Cruiser 001',
            'status' => Bicycle::STATUS_AVAILABLE,
            'lockStatus' => Bicycle::LOCK_LOCKED,
        ]);
    }

    private function parkBicycle(string $serial = 'PDY-002'): Bicycle
    {
        return $this->makeOutOfServiceBicycle([
            'serialNumber' => $serial,
            'name' => 'Parked Unit',
            // Available status but no hardware: the exact shape that used to be
            // advertised to riders and resurrected by lock acknowledgements.
            'status' => Bicycle::STATUS_AVAILABLE,
            'lockStatus' => self::PARKED_LOCK_STATUS,
        ]);
    }

    // ---------------- rentability ----------------

    public function test_only_the_in_service_bicycle_is_rentable(): void
    {
        $deployed = $this->deployBeachCruiser();
        $parked = $this->parkBicycle();

        $this->assertTrue($deployed->isInService());
        $this->assertTrue($deployed->isRentable());

        $this->assertFalse($parked->isInService());
        $this->assertFalse(
            $parked->isRentable(),
            'a bicycle parked out of service carries status=available and must still be excluded'
        );

        $rentable = app(RiderCacheService::class)->availableBicycles();

        $this->assertTrue($rentable->contains('id', $deployed->id));
        $this->assertFalse($rentable->contains('id', $parked->id));
    }

    public function test_a_parked_bicycle_cannot_start_a_rental(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $parked = $this->parkBicycle();

        $this->expectException(RentalException::class);
        $this->expectExceptionMessage('not in service');

        app(RentalService::class)->startRental($rider, $parked->id, 60, 'cash');
    }

    public function test_a_crafted_rent_post_cannot_rent_a_parked_bicycle(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $parked = $this->parkBicycle();

        $this->actingAs($rider)
            ->post(route('rider.rent.store'), [
                'bicycleId' => $parked->id,
                'paymentMethod' => 'cash',
                'durationMinutes' => 60,
            ])
            ->assertSessionHasErrors('bicycleId');

        $this->assertDatabaseMissing('rentals', ['bicycleId' => $parked->id]);
    }

    // ---------------- IoT commands ----------------

    public function test_no_device_command_is_ever_queued_for_a_parked_bicycle(): void
    {
        $parked = $this->parkBicycle();

        $commands = app(DeviceCommandService::class);

        foreach (['lock', 'unlock', 'restart', 'buzzer'] as $command) {
            $this->assertNull(
                $commands->sendCommand($parked->id, $command, []),
                "a {$command} command must be refused for a bicycle with no device"
            );
        }

        $this->assertDatabaseMissing('device_commands', ['bicycleId' => $parked->id]);
        $this->assertDatabaseMissing('device_status', [
            'bicycleId' => $parked->id,
            'type' => 'command',
        ]);
    }

    public function test_the_in_service_bicycle_still_receives_commands(): void
    {
        $deployed = $this->deployBeachCruiser();

        $command = app(DeviceCommandService::class)->sendCommand($deployed->id, 'unlock', []);

        $this->assertNotNull($command);
        $this->assertDatabaseHas('device_commands', [
            'bicycleId' => $deployed->id,
            'command' => 'unlock',
            'status' => 'pending',
        ]);
    }

    /**
     * The regression that motivated all of this: acknowledging a lock used to
     * rewrite `status` to Available for any bicycle, which is how a parked unit
     * could be dragged back into the rentable fleet by an unrelated ack.
     */
    public function test_acknowledging_a_lock_cannot_resurrect_a_parked_bicycle(): void
    {
        $parked = $this->parkBicycle();
        $parked->update(['status' => Bicycle::STATUS_OUT_OF_SERVICE]);

        // A command row that somehow already exists for the parked unit.
        $command = DeviceCommand::create([
            'bicycleId' => $parked->id,
            'command' => 'lock',
            'status' => 'pending',
        ]);

        $this->assertTrue(app(DeviceCommandService::class)->acknowledgeDeviceCommand($command->id, 'ok'));

        $fresh = $parked->fresh();

        $this->assertSame(
            Bicycle::STATUS_OUT_OF_SERVICE,
            $fresh->status,
            'a parked bicycle must never be promoted to available by a lock ack'
        );
        $this->assertFalse($fresh->isRentable());
    }

    public function test_acknowledging_a_lock_never_overrides_maintenance(): void
    {
        $deployed = $this->deployBeachCruiser();
        $deployed->update(['status' => Bicycle::STATUS_MAINTENANCE]);

        $command = DeviceCommand::create([
            'bicycleId' => $deployed->id,
            'command' => 'unlock',
            'status' => 'pending',
        ]);

        app(DeviceCommandService::class)->acknowledgeDeviceCommand($command->id, 'ok');

        $this->assertSame(
            Bicycle::STATUS_MAINTENANCE,
            $deployed->fresh()->status,
            'maintenance outranks the lock state'
        );
    }

    public function test_the_admin_lock_screen_refuses_a_parked_bicycle(): void
    {
        $admin = $this->makeAdmin();
        $parked = $this->parkBicycle();

        $this->actingAs($admin)
            ->post(route('admin.bicycles.lock', $parked->id), ['action' => 'lock'])
            ->assertSessionHasErrors('bicycle');

        $this->assertDatabaseMissing('device_commands', ['bicycleId' => $parked->id]);
        $this->assertSame(
            self::PARKED_LOCK_STATUS,
            $parked->fresh()->lockStatus,
            'the recorded lock status is untouched'
        );
    }

    public function test_the_iot_api_reports_a_parked_bicycle_as_not_in_service(): void
    {
        $admin = $this->makeAdmin();
        $parked = $this->parkBicycle();

        \Laravel\Sanctum\Sanctum::actingAs($admin);

        $response = $this->postJson("/api/iot/bicycle/{$parked->id}/command", ['command' => 'lock']);

        $response->assertStatus(409);
        $this->assertDatabaseMissing('device_commands', ['bicycleId' => $parked->id]);
        $this->assertSame(
            self::PARKED_LOCK_STATUS,
            $parked->fresh()->lockStatus
        );
    }

    // ---------------- End Ride ----------------

    /**
     * The headline requirement: ending PDY-001's ride updates its records and
     * returns it to Available so the rider can rent it again, and issues exactly
     * one physical lock command for that unit.
     */
    public function test_ending_the_deployed_ride_settles_records_and_returns_it_to_available(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $admin = $this->makeAdmin();
        $deployed = $this->deployBeachCruiser();

        $rental = app(RentalService::class)->startRental($rider, $deployed->id, 60, 'cash');

        $this->actingAs($admin)
            ->put(route('admin.rentals.end-ride', $rental->id), ['condition' => Rental::CONDITION_GOOD])
            ->assertSessionHasNoErrors();

        $fresh = $deployed->fresh();

        $this->assertSame(Bicycle::STATUS_AVAILABLE, $fresh->status, 'PDY-001 returns to the rentable fleet');
        $this->assertTrue($fresh->isRentable(), 'the renter can rent it again');
        $this->assertNull($fresh->currentRentalId);
        $this->assertNull($fresh->currentRider);
        $this->assertSame(Bicycle::LOCK_LOCKED, $fresh->lockStatus);

        // Rental history and payment were both written.
        $settled = $rental->fresh();
        $this->assertSame(Rental::STATUS_RETURNED, $settled->status);
        $this->assertSame('paid', $settled->paymentStatus);
        $this->assertNotNull($settled->endTime);
        $this->assertDatabaseHas('payments', [
            'rentalId' => $rental->id,
            'status' => 'paid',
        ]);

        // Exactly one lock command, for the deployed unit.
        $this->assertSame(
            1,
            DeviceCommand::where('bicycleId', $deployed->id)->where('command', 'lock')->count()
        );

        // And the rider sees it on the Rent page immediately.
        $this->actingAs($this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]))
            ->get(route('rider.rentals.create'))
            ->assertOk()
            ->assertSee('PDY-001', escape: false)
            ->assertSee('Select & Rent', escape: false);
    }

    public function test_ending_a_ride_leaves_a_parked_bicycle_completely_untouched(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $admin = $this->makeAdmin();

        // A unit that was deployed when the ride started and has been parked
        // since. startRental refuses a bicycle that is already out of service, so
        // the only way to reach this state is to revoke provisioning mid-rental -
        // which is exactly the revocation path that must be survived.
        $parked = $this->makeBicycle([
            'serialNumber' => 'PDY-002',
            'name' => 'Parked Unit',
            'status' => Bicycle::STATUS_AVAILABLE,
            'lockStatus' => Bicycle::LOCK_UNLOCKED,
        ]);

        $rental = app(RentalService::class)->startRental($rider, $parked->id, 60, 'cash');

        // Parked while the ride is live: no device, back to locked.
        $parked->sendOutOfService();
        $parked->update([
            'status' => Bicycle::STATUS_OUT_OF_SERVICE,
            'lockStatus' => self::PARKED_LOCK_STATUS,
        ]);

        $before = $parked->fresh();

        // startRental legitimately queued an unlock while the unit was still
        // deployed. What must not happen is any *further* command.
        $commandsBefore = DeviceCommand::where('bicycleId', $parked->id)->count();

        $this->actingAs($admin)
            ->put(route('admin.rentals.end-ride', $rental->id), ['condition' => Rental::CONDITION_GOOD])
            ->assertSessionHasNoErrors();

        $after = $parked->fresh();

        $this->assertSame(self::PARKED_LOCK_STATUS, $after->lockStatus, 'the lock status is never rewritten');
        $this->assertSame(Bicycle::STATUS_OUT_OF_SERVICE, $after->status, 'a parked unit is never returned to Available');
        $this->assertFalse($after->isRentable());

        $this->assertSame(
            $commandsBefore,
            DeviceCommand::where('bicycleId', $parked->id)->count(),
            'ending a ride must not queue a command for a bicycle with no device'
        );
        $this->assertDatabaseMissing('device_commands', [
            'bicycleId' => $parked->id,
            'reason' => 'rental_settled',
        ]);

        // The rider's money and history are still settled correctly - only the
        // hardware side is skipped.
        $this->assertSame(Rental::STATUS_RETURNED, $rental->fresh()->status);
        $this->assertDatabaseHas('payments', ['rentalId' => $rental->id, 'status' => 'paid']);
    }

    public function test_cancelling_a_rental_leaves_a_parked_bicycle_untouched(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);

        $parked = $this->makeBicycle([
            'serialNumber' => 'PDY-002',
            'name' => 'Parked Unit',
            'status' => Bicycle::STATUS_AVAILABLE,
            'lockStatus' => Bicycle::LOCK_UNLOCKED,
        ]);

        $rental = app(RentalService::class)->startRental($rider, $parked->id, 60, 'cash');

        $parked->sendOutOfService();
        $parked->update([
            'status' => Bicycle::STATUS_OUT_OF_SERVICE,
            'lockStatus' => self::PARKED_LOCK_STATUS,
        ]);

        $commandsBefore = DeviceCommand::where('bicycleId', $parked->id)->count();

        app(RentalService::class)->releaseBicycleOnCancel($rental, $rider);

        $after = $parked->fresh();

        $this->assertSame(self::PARKED_LOCK_STATUS, $after->lockStatus);
        $this->assertSame(Bicycle::STATUS_OUT_OF_SERVICE, $after->status);
        $this->assertNull($after->currentRentalId, 'the rental pointer is still cleared');
        $this->assertSame(
            $commandsBefore,
            DeviceCommand::where('bicycleId', $parked->id)->count(),
            'cancelling must not queue a command for a bicycle with no device'
        );
    }

    // ---------------- maintenance still works ----------------

    public function test_a_parked_bicycle_can_still_be_sent_to_maintenance(): void
    {
        $parked = $this->parkBicycle();

        $record = app(MaintenanceService::class)->placeBicycleInMaintenance($parked, 'Wheel buckled');

        $this->assertSame(Bicycle::STATUS_MAINTENANCE, $parked->fresh()->status);
        $this->assertDatabaseHas('maintenance_records', [
            'id' => $record->id,
            'bicycleId' => $parked->id,
        ]);

        $this->assertSame(
            self::PARKED_LOCK_STATUS,
            $parked->fresh()->lockStatus,
            'maintenance must not rewrite the lock status of a unit with no lock'
        );
    }

    public function test_completing_maintenance_parks_a_repaired_bicycle_again(): void
    {
        $parked = $this->parkBicycle();

        $record = app(MaintenanceService::class)->placeBicycleInMaintenance($parked, 'Wheel buckled');
        $this->assertSame(Bicycle::STATUS_MAINTENANCE, $parked->fresh()->status);

        $record->update([
            'status' => MaintenanceRecord::STATUS_COMPLETED,
            'completedDate' => now(),
        ]);

        $after = $parked->fresh();

        $this->assertNotSame(
            Bicycle::STATUS_AVAILABLE,
            $after->status,
            'repairing a parked bicycle does not put it on the road'
        );
        $this->assertFalse($after->isRentable());
        $this->assertSame(self::PARKED_LOCK_STATUS, $after->lockStatus);
        $this->assertSame('good', $after->condition, 'maintenance bookkeeping still applies');
        $this->assertNotNull($after->lastMaintenanceDate);
    }

    public function test_completing_maintenance_returns_the_deployed_bicycle_to_available(): void
    {
        $deployed = $this->deployBeachCruiser();

        $record = app(MaintenanceService::class)->placeBicycleInMaintenance($deployed, 'Brake pads worn');
        $record->update([
            'status' => MaintenanceRecord::STATUS_COMPLETED,
            'completedDate' => now(),
        ]);

        $after = $deployed->fresh();

        $this->assertSame(Bicycle::STATUS_AVAILABLE, $after->status);
        $this->assertTrue($after->isRentable());
        $this->assertSame(Bicycle::LOCK_LOCKED, $after->lockStatus);
    }

    // ---------------- rider-facing and admin views ----------------

    public function test_the_rent_page_shows_only_the_deployed_bicycle_as_selectable(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);

        $deployed = $this->deployBeachCruiser();
        $parkedA = $this->parkBicycle('PDY-002');
        $parkedB = $this->parkBicycle('PDY-003');

        $html = $this->actingAs($rider)->get(route('rider.rentals.create'))->assertOk()->getContent();

        // All three are listed, so the fleet a rider sees matches the inventory.
        foreach ([$deployed, $parkedA, $parkedB] as $bicycle) {
            $this->assertStringContainsString(
                $bicycle->serialNumber,
                $html,
                'every bicycle is still listed on the rent page'
            );
        }

        // Exactly one enabled button, and it belongs to the deployed unit.
        $this->assertSame(1, substr_count($html, 'Select & Rent'));
        $this->assertStringContainsString('&quot;id&quot;:' . $deployed->id . ',', $html);

        foreach ([$parkedA, $parkedB] as $bicycle) {
            $this->assertStringNotContainsString(
                '&quot;id&quot;' . $bicycle->id . ',',
                $html,
                "parked bicycle {$bicycle->serialNumber} must not be selectable"
            );
        }

        // Both parked units are labelled Not In Service.
        $this->assertSame(
            2,
            substr_count($html, 'badge-unavailable">Not In Service'),
            'each parked bicycle is badged Not In Service'
        );
    }

    public function test_a_parked_bicycle_is_never_pinned_on_the_rent_map(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);

        $parked = $this->parkBicycle();
        $parked->update([
            'currentLat' => 14.6100,
            'currentLng' => 121.0000,
        ]);

        $html = $this->actingAs($rider)->get(route('rider.rentals.create'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'var bicycles = ',
            $html,
            'the map payload is present'
        );

        // The payload is filtered by isRentable(), so a parked bicycle with good
        // coordinates must not appear in it.
        $this->assertStringNotContainsString(
            '"id":' . $parked->id . ',',
            $html,
            'a parked bicycle must not get a clickable map pin'
        );
    }

    public function test_the_admin_inventory_marks_parked_bicycles_and_hides_lock_control(): void
    {
        $admin = $this->makeAdmin();

        $this->deployBeachCruiser();
        $parked = $this->parkBicycle('PDY-002');

        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Not In Service', $html);
        $this->assertStringContainsString('No Device', $html);

        // No lock form is rendered for the parked bicycle.
        $this->assertStringNotContainsString(
            'action="' . route('admin.bicycles.lock', $parked->id) . '"',
            $html,
            'a parked bicycle must not offer a remote lock control'
        );
    }

    public function test_the_inventory_can_filter_and_edit_a_parked_bicycle(): void
    {
        $admin = $this->makeAdmin();

        $this->deployBeachCruiser();
        $parked = $this->makeBicycle([
            'serialNumber' => 'PDY-002',
            'name' => 'Parked Unit',
            'status' => Bicycle::STATUS_OUT_OF_SERVICE,
            'lockStatus' => self::PARKED_LOCK_STATUS,
        ]);

        // out_of_service is a real operational state, so it has to be reachable
        // through the inventory rather than being a value the UI cannot express.
        $filtered = $this->actingAs($admin)
            ->get(route('admin.bicycles.index', ['status' => Bicycle::STATUS_OUT_OF_SERVICE]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('PDY-002', $filtered, 'the filter selects parked units');
        $this->assertStringNotContainsString('PDY-001', $filtered, 'the deployed unit is filtered out');

        $this->actingAs($admin)
            ->from(route('admin.bicycles.index'))
            ->put(route('admin.bicycles.update', $parked->id), [
                'name' => $parked->name,
                'status' => Bicycle::STATUS_OUT_OF_SERVICE,
            ])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(
            Bicycle::STATUS_OUT_OF_SERVICE,
            $parked->fresh()->status,
            'out_of_service is an accepted status when updating'
        );
    }

    public function test_every_bicycle_status_is_accepted_and_locked_is_not_one_of_them(): void
    {
        $admin = $this->makeAdmin();
        $bicycle = $this->makeBicycle(['serialNumber' => 'PDY-003']);

        foreach (Bicycle::STATUSES as $index => $status) {
            // A separate row per status: some transitions are legitimately
            // guarded (a rented bicycle cannot be pushed into maintenance), and
            // that guard is not what this is checking.
            $target = $index === 0 ? $bicycle : $this->makeBicycle([
                'serialNumber' => 'PDY-10'.$index,
            ]);

            $this->actingAs($admin)
                ->from(route('admin.bicycles.index'))
                ->put(route('admin.bicycles.update', $target->id), ['status' => $status])
                ->assertSessionDoesntHaveErrors();

            $this->assertSame($status, $target->fresh()->status, "`{$status}` is a settable state");
        }

        // `locked` is a lock position, not an operational state: accepting it here
        // is how a bicycle ends up neither rentable nor held by a rental.
        $this->actingAs($admin)
            ->from(route('admin.bicycles.index'))
            ->put(route('admin.bicycles.update', $bicycle->id), ['status' => Bicycle::LOCK_LOCKED])
            ->assertSessionHasErrors('status');
    }

    // ---------------- provisioning API ----------------

    public function test_provisioning_is_not_mass_assignable(): void
    {
        // iotEnabled is kept out of $fillable, so a request payload can never
        // promote a parked bicycle into the rentable fleet.
        $bicycle = Bicycle::create([
            'name' => 'Sneaky',
            'serialNumber' => 'PDY-999',
            'hourlyRate' => 10,
            'status' => Bicycle::STATUS_AVAILABLE,
            'iotEnabled' => true,
        ]);

        $this->assertFalse(
            $bicycle->fresh()->isInService(),
            'iotEnabled must not be settable through mass assignment'
        );
    }

    public function test_the_reconcile_command_parks_the_fleet(): void
    {
        $deployed = $this->makeBicycle([
            'serialNumber' => 'PDY-001',
            'name' => 'Beach Cruiser 001',
            'status' => Bicycle::STATUS_OUT_OF_SERVICE,
        ]);
        $parked = $this->parkBicycle('PDY-002');

        $this->artisan('pedalya:fleet-reconcile')
            ->assertSuccessful();

        $this->assertTrue($deployed->fresh()->isInService(), 'PDY-001 is provisioned');
        $this->assertSame(Bicycle::STATUS_AVAILABLE, $deployed->fresh()->status);

        $this->assertFalse($parked->fresh()->isInService(), 'everything else is parked');
        $this->assertSame(Bicycle::STATUS_OUT_OF_SERVICE, $parked->fresh()->status);
        $this->assertSame(
            self::PARKED_LOCK_STATUS,
            $parked->fresh()->lockStatus,
            'reconcile never rewrites a lock status'
        );
    }

    public function test_the_reconcile_command_does_not_disturb_open_maintenance(): void
    {
        $this->makeBicycle([
            'serialNumber' => 'PDY-001',
            'name' => 'Beach Cruiser 001',
            'status' => Bicycle::STATUS_OUT_OF_SERVICE,
        ]);

        $inMaintenance = $this->makeBicycle([
            'serialNumber' => 'PDY-006',
            'name' => 'Urban Cruiser 003',
            'status' => Bicycle::STATUS_MAINTENANCE,
        ]);

        $this->makeMaintenanceRecord([
            'bicycleId' => $inMaintenance->id,
            'status' => MaintenanceRecord::STATUS_IN_PROGRESS,
        ]);

        $this->artisan('pedalya:fleet-reconcile')->assertSuccessful();

        $after = $inMaintenance->fresh();

        $this->assertSame(
            Bicycle::STATUS_MAINTENANCE,
            $after->status,
            'reconcile must not pull a bicycle out of maintenance'
        );
        $this->assertFalse($after->isInService(), 'maintenance does not mean provisioned');
        $this->assertFalse($after->isRentable());
        $this->assertSame(1, MaintenanceRecord::where('bicycleId', $inMaintenance->id)->count());
    }

    /**
     * Failing someone else's queued commands is an irreversible write to rows
     * this policy did not create, so it stays opt-in.
     */
    public function test_the_reconcile_command_leaves_pending_commands_alone_by_default(): void
    {
        $this->deployBeachCruiser();
        $parked = $this->parkBicycle('PDY-002');

        $queued = DeviceCommand::create([
            'bicycleId' => $parked->id,
            'command' => 'lock',
            'status' => 'pending',
        ]);

        $this->artisan('pedalya:fleet-reconcile')->assertSuccessful();

        $this->assertSame('pending', $queued->fresh()->status);

        // Opting in does clear them.
        $this->artisan('pedalya:fleet-reconcile', ['--clear-orphan-commands' => true])
            ->assertSuccessful();

        $this->assertSame('failed', $queued->fresh()->status);
    }

    public function test_the_reconcile_command_is_idempotent(): void
    {
        $this->deployBeachCruiser();
        $this->parkBicycle('PDY-002');

        $this->artisan('pedalya:fleet-reconcile')->assertSuccessful();
        $this->artisan('pedalya:fleet-reconcile')->assertSuccessful();

        $this->assertSame(
            1,
            Bicycle::inService()->count(),
            'still exactly one unit in service after two reconciles'
        );
    }

    public function test_heartbeat_cannot_change_the_lock_state_of_a_parked_bicycle(): void
    {
        $parked = $this->parkBicycle();

        // Parked and recorded as LOCKED, then told to report unlocked. Asserting
        // against a value the row already holds would pass even with no gate at
        // all, so the heartbeat has to claim the opposite of the current state.
        $parked->update(['status' => Bicycle::STATUS_OUT_OF_SERVICE]);
        $parked->update(['lockStatus' => Bicycle::LOCK_LOCKED]);

        app(\App\Services\IoTService::class)->processHeartbeat([
            'bicycleId' => $parked->id,
            'locked' => false,
            'batteryLevel' => 80,
        ]);

        $this->assertSame(
            Bicycle::LOCK_LOCKED,
            $parked->fresh()->lockStatus,
            'an out-of-band heartbeat must not move the lock of a bicycle with no device'
        );
        $this->assertFalse($parked->fresh()->isRentable());
        $this->assertSame(Bicycle::STATUS_OUT_OF_SERVICE, $parked->fresh()->status);
    }

    public function test_heartbeat_still_reports_the_lock_for_an_in_service_bicycle(): void
    {
        $deployed = $this->deployBeachCruiser();
        $deployed->update(['lockStatus' => Bicycle::LOCK_LOCKED]);

        app(\App\Services\IoTService::class)->processHeartbeat([
            'bicycleId' => $deployed->id,
            'locked' => false,
            'batteryLevel' => 80,
        ]);

        $this->assertSame(
            Bicycle::LOCK_UNLOCKED,
            $deployed->fresh()->lockStatus,
            'a deployed unit still owns its lock state'
        );
    }
}