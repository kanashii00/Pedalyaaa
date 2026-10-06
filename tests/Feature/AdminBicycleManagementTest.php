<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bicycle;
use App\Models\DeviceCommand;
use App\Models\DeviceStatus;
use App\Models\MaintenanceRecord;
use App\Models\Rental;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class AdminBicycleManagementTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function test_index_with_filters(): void
    {
        $admin = $this->makeAdmin();
        $this->makeBicycle(['name' => 'Alpha', 'status' => Bicycle::STATUS_AVAILABLE, 'batteryLevel' => 40]);
        $this->makeBicycle(['name' => 'Beta', 'status' => Bicycle::STATUS_RENTED, 'batteryLevel' => 90]);

        $this->actingAs($admin)->get(route('admin.bicycles.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.bicycles.index', ['status' => 'rented', 'battery' => 50, 'search' => 'Beta']))->assertOk();
    }

    public function test_show_and_create_pages(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle();

        $this->actingAs($admin)->get(route('admin.bicycles.show', $bike->id))->assertOk();
        $this->actingAs($admin)->get(route('admin.bicycles.create'))->assertOk();
    }

    public function test_store_creates_bicycle_and_audit_log(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.bicycles.store'), [
                'name' => 'New Bike',
                'model' => 'Mountain',
                'hourlyRate' => 25,
                'currentLat' => 14.6,
                'currentLng' => 120.99,
            ])
            ->assertRedirect(route('admin.bicycles.index'));

        // A bicycle added to the inventory is parked, not deployed: it has no
        // smart lock fitted, so it must come in out of service and must never
        // be rentable until it is explicitly provisioned.
        $this->assertDatabaseHas('bicycles', [
            'name' => 'New Bike',
            'status' => Bicycle::STATUS_OUT_OF_SERVICE,
            'iotEnabled' => false,
        ]);

        $created = Bicycle::where('name', 'New Bike')->firstOrFail();

        $this->assertFalse($created->isInService(), 'a newly added bicycle is not in service');
        $this->assertFalse($created->isRentable(), 'a newly added bicycle is not rentable');
        $this->assertDatabaseHas('audit_logs', ['action' => 'bicycle_created']);
    }

    public function test_generated_serial_numbers_never_reuse_a_deleted_one(): void
    {
        $admin = $this->makeAdmin();
        $year = date('Y');

        // A gap in the sequence: 00001 and 00003 exist, 00002 was deleted.
        $this->makeBicycle(['serialNumber' => "PDY-{$year}-00001"]);
        $this->makeBicycle(['serialNumber' => "PDY-{$year}-00003"]);

        $this->actingAs($admin)
            ->post(route('admin.bicycles.store'), [
                'name' => 'After The Gap',
                'model' => 'City',
                'hourlyRate' => 15,
                'currentLat' => 14.6,
                'currentLng' => 120.99,
            ])
            ->assertSessionHasNoErrors();

        $serial = Bicycle::where('name', 'After The Gap')->value('serialNumber');

        $this->assertNotSame(
            "PDY-{$year}-00003",
            $serial,
            'a row-count based sequence would hand back the live 00003 serial'
        );
        $this->assertSame(
            "PDY-{$year}-00004",
            $serial,
            'the sequence continues past the highest serial in use'
        );

        // The serial is the unique key both modules are keyed on.
        $this->assertSame(
            1,
            Bicycle::where('serialNumber', $serial)->count(),
            'serial numbers must stay unique'
        );
    }

    public function test_store_validation_fails_without_name(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.bicycles.store'), ['hourlyRate' => 10])
            ->assertSessionHasErrors('name');
    }

    public function test_update_fields(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['batteryLevel' => 50, 'hourlyRate' => 15]);

        $this->from(route('admin.bicycles.index'))
            ->actingAs($admin)
            ->put(route('admin.bicycles.update', $bike->id), [
                'name' => 'Renamed',
                'hourlyRate' => 20,
                'batteryLevel' => 80,
            ])
            ->assertRedirect(route('admin.bicycles.index'));

        $fresh = $bike->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame(20.0, (float) $fresh->hourlyRate);
        $this->assertSame(80, (int) $fresh->batteryLevel);
    }

    public function test_update_to_maintenance_places_bicycle_in_maintenance(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);

        $this->from(route('admin.bicycles.index'))
            ->actingAs($admin)
            ->put(route('admin.bicycles.update', $bike->id), ['status' => 'maintenance'])
            ->assertRedirect(route('admin.bicycles.index'));

        $fresh = $bike->fresh();
        $this->assertSame(Bicycle::STATUS_MAINTENANCE, $fresh->status);
        $this->assertSame(Bicycle::LOCK_LOCKED, $fresh->lockStatus);
        $this->assertDatabaseHas('maintenance_records', ['bicycleId' => $bike->id]);
    }

    public function test_update_rented_to_maintenance_is_blocked(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);

        $this->from(route('admin.bicycles.index'))
            ->actingAs($admin)
            ->put(route('admin.bicycles.update', $bike->id), ['status' => 'maintenance'])
            ->assertSessionHasErrors('status');

        $this->assertSame(Bicycle::STATUS_RENTED, $bike->fresh()->status);
    }

    public function test_update_from_maintenance_to_available_blocked_when_active_records(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_MAINTENANCE]);
        $this->makeMaintenanceRecord([
            'bicycleId' => $bike->id,
            'status' => MaintenanceRecord::STATUS_IN_PROGRESS,
        ]);

        $this->from(route('admin.bicycles.index'))
            ->actingAs($admin)
            ->put(route('admin.bicycles.update', $bike->id), ['status' => 'available'])
            ->assertSessionHasErrors('status');
    }

    public function test_destroy_removed_available_bicycle(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);

        $this->from(route('admin.bicycles.index'))
            ->actingAs($admin)
            ->delete(route('admin.bicycles.destroy', $bike->id))
            ->assertRedirect(route('admin.bicycles.index'));

        $fresh = $bike->fresh();
        $this->assertSame(Bicycle::STATUS_REMOVED, $fresh->status);
        $this->assertNotNull($fresh->removedAt);
    }

    public function test_destroy_blocked_while_in_maintenance(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_MAINTENANCE]);

        $this->from(route('admin.bicycles.index'))
            ->actingAs($admin)
            ->delete(route('admin.bicycles.destroy', $bike->id))
            ->assertSessionHasErrors('bicycle');

        $this->assertSame(Bicycle::STATUS_MAINTENANCE, $bike->fresh()->status);
    }

    public function test_destroy_blocked_with_active_rental(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);

        $this->from(route('admin.bicycles.index'))
            ->actingAs($admin)
            ->delete(route('admin.bicycles.destroy', $bike->id))
            ->assertSessionHasErrors('bicycle');
    }

    public function test_lock_sends_remote_command(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);

        $this->from(route('admin.bicycles.index'))
            ->actingAs($admin)
            ->post(route('admin.bicycles.lock', $bike->id), ['action' => 'unlock'])
            ->assertRedirect(route('admin.bicycles.index'));

        $this->assertDatabaseHas('device_commands', ['bicycleId' => $bike->id, 'command' => 'unlock', 'status' => 'pending']);
    }

    /**
     * The Actions cell of one row, so a test can assert what sits outside the
     * dropdown and what sits inside it.
     */
    private function actionsCell(string $html, string $rowMarker): string
    {
        $rowStart = strpos($html, $rowMarker);
        $this->assertNotFalse($rowStart, 'the row for ' . $rowMarker . ' is missing');

        $cellStart = strpos($html, '<td data-label="Actions">', $rowStart);
        $this->assertNotFalse($cellStart);

        $cellEnd = strpos($html, '</td>', $cellStart);
        $this->assertNotFalse($cellEnd);

        return substr($html, $cellStart, $cellEnd - $cellStart);
    }

    public function test_the_inventory_table_is_sized_to_fit_instead_of_scrolling_sideways(): void
    {
        $css = (string) file_get_contents(public_path('css/admin.css'));

        // A fixed layout with percentage columns fills the card exactly, so the
        // table can never demand more width than the viewport provides.
        $this->assertMatchesRegularExpression(
            '/\.admin-table--inventory\s*\{[^}]*table-layout:\s*fixed/',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.admin-table--inventory\s*\{[^}]*min-width:\s*\d+px/',
            $css
        );
        $this->assertStringNotContainsString('min-width: 1570px', $css);

        preg_match_all(
            '/\.admin-table--inventory \.(col-[a-z]+) \{ width: ([\d.]+)%; \}/',
            $css,
            $matches,
            PREG_SET_ORDER
        );

        $this->assertCount(11, $matches, 'all 11 inventory columns need a percentage width');

        $total = array_sum(array_map(static fn (array $column) => (float) $column[2], $matches));
        $this->assertEqualsWithDelta(100.0, $total, 0.001, 'the column widths must add up to the full width');

        // The widest Actions cell is Edit + Delete + the rental action + the
        // three-dots menu, and it has to stay on one line on every viewport.
        $this->assertMatchesRegularExpression(
            '/\.admin-table--inventory \.actions-row \{ flex-wrap: nowrap/',
            $css
        );

        // Density comes from this table's own padding, not from a wider layout.
        $this->assertMatchesRegularExpression('/\.admin-table--inventory tbody td \{ padding: 8px 6px/', $css);
    }

    public function test_the_inventory_markup_keeps_every_column(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle();

        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->assertOk()->getContent();

        foreach (['Name', 'Serial', 'Status', 'Current Renter', 'Current Rental', 'Battery', 'Lock', 'Condition', 'Hourly Rate', 'Last Updated', 'Actions'] as $column) {
            $this->assertStringContainsString('data-label="' . $column . '"', $html, $column . ' column is missing');
        }

        // Values that can truncate keep their full text in a title attribute.
        $this->assertStringContainsString('title="' . $bike->name . '"', $html);
        $this->assertStringContainsString('title="' . $bike->serialNumber . '"', $html);
    }

    public function test_the_actions_dropdown_is_placed_against_the_viewport(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);

        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->assertOk()->getContent();
        $cell = $this->actionsCell($html, $bike->serialNumber);

        // The menu is wired to Bootstrap and flagged for the controller that
        // gives it Popper's fixed strategy, so it can leave the cell.
        $this->assertStringContainsString('<div class="dropdown" data-row-menu>', $cell);
        $this->assertStringContainsString('data-bs-toggle="dropdown"', $cell);
        $this->assertStringContainsString('data-bs-boundary="viewport"', $cell);
        $this->assertStringContainsString('aria-expanded="false"', $cell);

        $css = (string) file_get_contents(public_path('css/admin.css'));

        // Nothing between the menu and the page may hide its overflow: the cell
        // used to clip it, which cut the menu off after the first items.
        $this->assertDoesNotMatchRegularExpression(
            '/\.admin-table--inventory tbody td \{[^}]*overflow/',
            $css
        );

        // The menu paints above the table itself (its sticky header is z-index 20).
        $this->assertMatchesRegularExpression(
            '/\.admin-table--inventory \.dropdown-menu \{[^}]*z-index: 1035/',
            $css
        );

        $js = (string) file_get_contents(public_path('js/admin.js'));

        $this->assertStringContainsString("strategy: 'fixed'", $js);
        $this->assertStringContainsString("boundary: 'viewport'", $js);
        $this->assertStringContainsString("el.addEventListener('show.bs.dropdown'", $js);
        $this->assertStringContainsString('other.pedalyaMenu.hide()', $js);
    }

    public function test_each_row_menu_only_acts_on_its_own_bicycle(): void
    {
        $admin = $this->makeAdmin();
        $first = $this->makeBicycle(['serialNumber' => 'PDY-ROW-A', 'status' => Bicycle::STATUS_AVAILABLE]);
        $second = $this->makeBicycle(['serialNumber' => 'PDY-ROW-B', 'status' => Bicycle::STATUS_AVAILABLE]);

        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->assertOk()->getContent();

        foreach ([$first, $second] as $bike) {
            $cell = $this->actionsCell($html, $bike->serialNumber);
            $menuStart = strpos($cell, '<ul class="dropdown-menu dropdown-menu-end">');
            $this->assertNotFalse($menuStart);
            $menu = substr($cell, $menuStart);

            // Every control in the row points at this bicycle and nothing else.
            $this->assertStringContainsString(route('admin.bicycles.show', $bike->id), $menu);
            $this->assertStringContainsString(route('admin.bicycles.lock', $bike->id), $menu);
            $this->assertStringContainsString('maintenanceBicycleModal' . $bike->id, $cell);

            $other = $bike->is($first) ? $second : $first;
            $this->assertStringNotContainsString(route('admin.bicycles.lock', $other->id), $cell);
            $this->assertStringNotContainsString(route('admin.bicycles.show', $other->id), $menu);
            $this->assertStringNotContainsString('maintenanceBicycleModal' . $other->id, $cell);
        }
    }

    public function test_row_keeps_edit_and_delete_while_lock_lives_in_the_actions_dropdown(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE, 'lockStatus' => Bicycle::LOCK_UNLOCKED]);

        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->assertOk()->getContent();

        $cell = $this->actionsCell($html, $bike->serialNumber);

        // Edit and Delete stay directly in the row.
        $this->assertStringContainsString("PedalyaModal.open('editBicycleModal{$bike->id}')", $cell);
        $this->assertStringContainsString(route('admin.bicycles.destroy', $bike->id), $cell);

        $menuStart = strpos($cell, '<ul class="dropdown-menu dropdown-menu-end">');
        $this->assertNotFalse($menuStart, 'the row still needs the actions dropdown');

        // The lock control is offered exactly once, inside that dropdown.
        $lockForm = strpos($cell, route('admin.bicycles.lock', $bike->id));
        $this->assertNotFalse($lockForm, 'an in-service bicycle must still offer a lock control');
        $this->assertGreaterThan($menuStart, $lockForm);
        $this->assertStringNotContainsString('bi-unlock-fill', substr($cell, 0, $menuStart));
        $this->assertStringContainsString('value="lock"', substr($cell, $lockForm));
        $this->assertStringContainsString('>Unlock', substr($cell, $lockForm));
        $this->assertStringContainsString('dropdown-item', substr($cell, $lockForm));
    }

    public function test_locked_row_moves_the_lock_action_into_the_dropdown(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE, 'lockStatus' => Bicycle::LOCK_LOCKED]);

        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->assertOk()->getContent();

        $cell = $this->actionsCell($html, $bike->serialNumber);
        $menuStart = strpos($cell, '<ul class="dropdown-menu dropdown-menu-end">');
        $lockForm = strpos($cell, route('admin.bicycles.lock', $bike->id));

        $this->assertNotFalse($menuStart);
        $this->assertNotFalse($lockForm);
        $this->assertGreaterThan($menuStart, $lockForm);
        $this->assertStringContainsString('value="unlock"', substr($cell, $lockForm));
        $this->assertStringContainsString('>Lock', substr($cell, $lockForm));
    }

    public function test_parked_row_reports_no_device_inside_the_dropdown(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeOutOfServiceBicycle([
            'serialNumber' => 'PDY-PARKED',
            'name' => 'Parked Unit',
            'status' => Bicycle::STATUS_AVAILABLE,
        ]);

        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->assertOk()->getContent();

        $cell = $this->actionsCell($html, $bike->serialNumber);
        $menuStart = strpos($cell, '<ul class="dropdown-menu dropdown-menu-end">');
        $this->assertNotFalse($menuStart);

        $noDevice = strpos($cell, 'No Device');
        $this->assertNotFalse($noDevice);
        $this->assertGreaterThan($menuStart, $noDevice);

        $menu = substr($cell, $menuStart);
        $this->assertStringContainsString('dropdown-item disabled', $menu);
        $this->assertStringContainsString('No Device', $menu);

        // A parked bicycle has no device, so the menu must not offer a lock
        // command and the row must not link to device management either.
        $this->assertStringNotContainsString(route('admin.bicycles.lock', $bike->id), $cell);
        $this->assertStringNotContainsString('Device Management', $cell);
    }

    public function test_rented_row_moves_the_in_use_notice_into_the_dropdown(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);

        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->assertOk()->getContent();

        $cell = $this->actionsCell($html, $bike->serialNumber);
        $menuStart = strpos($cell, '<ul class="dropdown-menu dropdown-menu-end">');
        $inUse = strpos($cell, 'In Use');

        $this->assertNotFalse($menuStart);
        $this->assertNotFalse($inUse);
        $this->assertGreaterThan($menuStart, $inUse);
        $this->assertStringNotContainsString(route('admin.bicycles.lock', $bike->id), $cell);
        $this->assertStringContainsString('Device Management', substr($cell, $menuStart, 2000));
    }

    public function test_lock_blocked_when_rented(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);

        $this->from(route('admin.bicycles.index'))
            ->actingAs($admin)
            ->post(route('admin.bicycles.lock', $bike->id), ['action' => 'lock'])
            ->assertSessionHasErrors('bicycle');
    }

    public function test_telemetry_returns_json(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle();

        DeviceStatus::create([
            'bicycleId' => $bike->id,
            'type' => 'heartbeat',
            'eventTimestamp' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.bicycles.telemetry', $bike->id))
            ->assertOk()
            ->assertJsonFragment(['bicycleId' => $bike->id]);
    }
}
