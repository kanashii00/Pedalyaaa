<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bicycle;
use App\Models\DeviceCommand;
use App\Models\DeviceStatus;
use App\Models\GpsLog;
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
        $alpha = $this->makeBicycle([
            'name' => 'Alpha',
            'serialNumber' => 'PDY-SEARCH-ALPHA',
            'status' => Bicycle::STATUS_AVAILABLE,
            'batteryLevel' => 40,
        ]);
        $this->makeBicycle([
            'name' => 'Beta',
            'serialNumber' => 'PDY-SEARCH-BETA',
            'status' => Bicycle::STATUS_RENTED,
            'batteryLevel' => 90,
        ]);

        $this->actingAs($admin)->get(route('admin.bicycles.index'))->assertOk();

        $response = $this->actingAs($admin)->get(route('admin.bicycles.index', [
            'status' => 'available',
            'search' => $alpha->serialNumber,
        ]))->assertOk();

        $response->assertSee($alpha->serialNumber)->assertDontSee('PDY-SEARCH-BETA');
        $response->assertSee('form="inventoryFilters"', false);
        $response->assertSee('name="search"', false);
        $response->assertSee('value="' . $alpha->serialNumber . '"', false);
    }

    public function test_show_and_create_pages(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle();

        $this->actingAs($admin)
            ->get(route('admin.bicycles.show', $bike->id))
            ->assertOk()
            ->assertSee(route('admin.bicycles.lock', $bike->id), false);
        $this->actingAs($admin)
            ->get(route('admin.bicycles.create'))
            ->assertOk()
            ->assertSee('Wheel Circumference (m)')
            ->assertSee('name="wheelCircumferenceM"', false);

        $this->actingAs($admin)
            ->get(route('admin.bicycles.index'))
            ->assertOk()
            ->assertSee('id="editBicycleModal'.$bike->id.'"', false)
            ->assertSee('name="wheelCircumferenceM"', false);
    }

    public function test_details_hide_lock_controls_when_the_bicycle_cannot_be_controlled(): void
    {
        $admin = $this->makeAdmin();

        foreach ([
            ['status' => Bicycle::STATUS_RENTED],
            ['status' => Bicycle::STATUS_MAINTENANCE],
            ['iotEnabled' => false],
        ] as $attributes) {
            $bike = $this->makeBicycle($attributes);
            $html = $this->actingAs($admin)
                ->get(route('admin.bicycles.show', $bike->id))
                ->assertOk()
                ->getContent();

            $this->assertStringNotContainsString(route('admin.bicycles.lock', $bike->id), $html);
        }
    }

    public function test_bicycle_details_do_not_fabricate_a_map_position_without_a_gps_log(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['currentLat' => null, 'currentLng' => null]);

        $html = $this->actingAs($admin)
            ->get(route('admin.bicycles.show', $bike->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No GPS position has been reported', $html);
        $this->assertStringNotContainsString('<div class="bike-map" id="bicycleDetailMap"', $html);
    }

    public function test_bicycle_details_use_the_latest_recorded_gps_position(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle([
            'currentLat' => 1,
            'currentLng' => 2,
        ]);
        GpsLog::create([
            'bicycleId' => $bike->id,
            'lat' => 14.6,
            'lng' => 120.99,
            'timestamp' => now(),
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.bicycles.show', $bike->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-lat="14.6000000"', $html);
        $this->assertStringContainsString('data-lng="120.9900000"', $html);
        $this->assertStringNotContainsString('data-lat="1.0000000"', $html);
    }

    public function test_store_creates_bicycle_and_audit_log(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.bicycles.store'), [
                'name' => 'New Bike',
                'model' => 'Mountain',
                'hourlyRate' => 25,
                'wheelCircumferenceM' => 2.1,
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
            'wheelCircumferenceM' => 2.1,
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

    public function test_wheel_circumference_rejects_nonpositive_and_unreasonable_values(): void
    {
        $admin = $this->makeAdmin();

        foreach ([0, -0.5, 4.001] as $circumference) {
            $this->actingAs($admin)
                ->post(route('admin.bicycles.store'), [
                    'name' => 'Invalid circumference',
                    'hourlyRate' => 10,
                    'wheelCircumferenceM' => $circumference,
                ])
                ->assertSessionHasErrors('wheelCircumferenceM');
        }

        $bike = $this->makeBicycle();
        $this->from(route('admin.bicycles.index'))
            ->actingAs($admin)
            ->put(route('admin.bicycles.update', $bike->id), [
                '_editBicycleId' => $bike->id,
                'wheelCircumferenceM' => 0,
            ])
            ->assertRedirect(route('admin.bicycles.index'))
            ->assertSessionHasErrors('wheelCircumferenceM');

        $this->actingAs($admin)
            ->get(route('admin.bicycles.index'))
            ->assertOk()
            ->assertSee('class="admin-modal open" id="editBicycleModal'.$bike->id.'"', false)
            ->assertSee('value="0"', false);
    }

    public function test_provided_pdy001_circumference_migration_sets_the_configured_value(): void
    {
        $this->makeBicycle([
            'serialNumber' => 'PDY-001',
            'wheelCircumferenceM' => null,
        ]);

        $migration = require database_path('migrations/2026_11_28_000003_set_pdy001_wheel_circumference.php');
        $migration->up();

        $this->assertDatabaseHas('bicycles', [
            'serialNumber' => 'PDY-001',
            'wheelCircumferenceM' => 2.086,
        ]);
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
                'wheelCircumferenceM' => 2.25,
            ])
            ->assertRedirect(route('admin.bicycles.index'));

        $fresh = $bike->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame(20.0, (float) $fresh->hourlyRate);
        $this->assertSame(80, (int) $fresh->batteryLevel);
        $this->assertSame(2.25, (float) $fresh->wheelCircumferenceM);
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

    public function test_edit_cannot_change_a_rented_bicycle_to_available(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);
        $rental = $this->makeRental([
            'bicycleId' => $bike->id,
            'riderId' => $rider->id,
            'status' => Rental::STATUS_ACTIVE,
        ]);
        $bike->update(['currentRentalId' => $rental->id, 'currentRider' => $rider->id]);

        $this->from(route('admin.bicycles.index'))
            ->actingAs($admin)
            ->put(route('admin.bicycles.update', $bike->id), ['status' => Bicycle::STATUS_AVAILABLE])
            ->assertSessionHasErrors('status');

        $this->assertSame(Bicycle::STATUS_RENTED, $bike->fresh()->status);
        $this->assertSame((string) $rental->id, $bike->fresh()->currentRentalId);
        $this->assertSame(Rental::STATUS_ACTIVE, $rental->fresh()->status);
    }

    public function test_edit_cannot_manually_mark_an_available_bicycle_as_rented(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);

        $this->from(route('admin.bicycles.index'))
            ->actingAs($admin)
            ->put(route('admin.bicycles.update', $bike->id), ['status' => Bicycle::STATUS_RENTED])
            ->assertSessionHasErrors('status');

        $this->assertSame(Bicycle::STATUS_AVAILABLE, $bike->fresh()->status);
        $this->assertNull($bike->fresh()->currentRentalId);
    }

    public function test_edit_cannot_mark_an_unprovisioned_bicycle_available(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeOutOfServiceBicycle();

        $this->from(route('admin.bicycles.index'))
            ->actingAs($admin)
            ->put(route('admin.bicycles.update', $bike->id), ['status' => Bicycle::STATUS_AVAILABLE])
            ->assertSessionHasErrors('status');

        $this->assertSame(Bicycle::STATUS_OUT_OF_SERVICE, $bike->fresh()->status);
        $this->assertFalse($bike->fresh()->isInService());
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

    public function test_destroy_is_blocked_while_a_return_is_unsettled(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();

        foreach ([Rental::STATUS_AWAITING_RETURN, Rental::STATUS_OVERDUE_RETURN] as $status) {
            $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
            $this->makeRental([
                'bicycleId' => $bike->id,
                'riderId' => $rider->id,
                'status' => $status,
            ]);

            $this->from(route('admin.bicycles.index'))
                ->actingAs($admin)
                ->delete(route('admin.bicycles.destroy', $bike->id))
                ->assertSessionHasErrors('bicycle');

            $this->assertSame(Bicycle::STATUS_AVAILABLE, $bike->fresh()->status);
        }
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

    public function test_the_inventory_table_balances_columns_and_stacks_before_it_scrolls(): void
    {
        $css = (string) file_get_contents(public_path('css/admin.css'));

        // Table mode uses a shared CSS Grid per row: ten fractional data tracks
        // and one content-sized Actions track. The grid fills the card exactly,
        // while the action group gets only the width its widest real row needs.
        // The 940px floor is the narrowest table where the badges, rate and the
        // full four-button action group still fit without clipping.
        $this->assertMatchesRegularExpression(
            '/\.admin-table--inventory\s*\{[^}]*min-width:\s*940px/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/--inventory-actions-width:\s*140px/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 1800px\) \{.*?--inventory-actions-width:\s*176px/s',
            $css
        );
        $this->assertStringNotContainsString('min-width: 1570px', $css);

        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 1016px\) and \(max-width: 1199\.98px\),\s*' .
            '\(min-width: 1280px\) \{/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.admin-table--inventory thead tr,\s*\.admin-table--inventory tbody tr \{\s*' .
            'display: grid;\s*grid-template-columns:/',
            $css
        );

        $grid = null;
        if (preg_match(
            '/\.admin-table--inventory thead tr,\s*\.admin-table--inventory tbody tr \{.*?' .
            'grid-template-columns:\s*(.*?);/s',
            $css,
            $gridMatch
        )) {
            $grid = preg_replace('/\s+/', ' ', trim($gridMatch[1]));
        }

        $this->assertNotNull($grid, 'the inventory grid template must be present');
        $this->assertStringEndsWith('var(--inventory-actions-width)', $grid);

        preg_match_all('/minmax\(0, ([\d.]+)fr\)/', $grid, $trackMatches);
        $tracks = array_map('floatval', $trackMatches[1]);
        $this->assertCount(10, $tracks, 'ten data tracks share the remaining table width');
        $this->assertEqualsWithDelta(
            10.0,
            array_sum($tracks),
            0.001,
            'the data tracks must fill the remaining table width'
        );

        $weights = array_combine(
            ['name', 'serial', 'status', 'renter', 'rental', 'battery', 'lock', 'condition', 'rate', 'updated'],
            $tracks
        );

        // The data-bearing columns must not be squeezed back into a narrow
        // share while sparse columns absorb the extra space. Lock and rate are
        // checked too: they hold the widest badge and the currency value.
        $this->assertGreaterThanOrEqual(1.20, $weights['name']);
        $this->assertGreaterThanOrEqual(1.16, $weights['serial']);
        $this->assertGreaterThanOrEqual(1.25, $weights['status']);
        $this->assertGreaterThanOrEqual(0.77, $weights['renter']);
        $this->assertGreaterThanOrEqual(1.13, $weights['rental']);
        $this->assertGreaterThanOrEqual(0.76, $weights['battery']);
        $this->assertGreaterThanOrEqual(1.05, $weights['lock']);
        $this->assertGreaterThanOrEqual(0.85, $weights['condition']);
        $this->assertGreaterThanOrEqual(0.88, $weights['rate']);
        $this->assertGreaterThanOrEqual(0.95, $weights['updated']);

        // The widest Actions cell is Edit + Delete + the rental action + the
        // three-dots menu. It stays on one row, starts at the header's left
        // edge, and spreads through the content-sized track so the menu ends
        // flush with the card's right gutter instead of leaving a dead band.
        $this->assertMatchesRegularExpression(
            '/\.admin-table--inventory \.actions-row \{ flex-wrap: nowrap/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.admin-table--inventory \.actions-row \{ justify-content: space-between; \}/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.admin-table--inventory tbody td > \.actions-row \{ width: 100%; \}/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.admin-table--inventory thead th:last-child,\s*' .
            '\.admin-table--inventory tbody td:last-child:not\(\[colspan\]\) \{ text-align: left; \}/',
            $css
        );

        // The first and last cells share the toolbar's 16px gutter so names
        // never touch the card edge and the action row stops before it.
        $this->assertMatchesRegularExpression(
            '/\.admin-table--inventory thead th:first-child,\s*' .
            '\.admin-table--inventory tbody td:first-child \{ padding-left: 16px; \}/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.admin-table--inventory thead th:last-child,\s*' .
            '\.admin-table--inventory tbody td:last-child:not\(\[colspan\]\) \{ padding-right: 16px; \}/',
            $css
        );

        // Headers may wrap inside their own column instead of painting over
        // the next one; a long single label breaks only when necessary.
        $this->assertMatchesRegularExpression(
            '/\.admin-table--inventory thead th \{[^}]*white-space: normal/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.admin-table--inventory thead th \{[^}]*overflow-wrap: anywhere/',
            $css
        );

        // Below the table view's floor the inventory reuses the labelled-card
        // presentation. Both the tablet band and the post-sidebar band (until
        // the card's 940px content floor is available) must stack; otherwise
        // those layouts would produce a wide inner scroll area or clip the
        // rightmost columns.
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 1015\.98px\) \{\s*' .
            '\.admin-table--inventory \{ table-layout: auto; min-width: 0; \}/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 768px\) and \(max-width: 1015\.98px\),\s*' .
            '\(min-width: 1200px\) and \(max-width: 1279\.98px\) \{/',
            $css
        );
        $this->assertStringContainsString('.admin-table--inventory thead { display: none; }', $css);
        $this->assertStringContainsString(
            '.admin-table--inventory tbody td[data-label="Battery"] > .d-flex',
            $css
        );
        $this->assertStringContainsString(
            '.admin-table-wrap--inventory .admin-table-foot { justify-content: center; }',
            $css
        );

        // Density comes from this table's own padding, not from a wider layout.
        $this->assertMatchesRegularExpression('/\.admin-table--inventory tbody td \{ padding: 8px 6px/', $css);
    }

    public function test_the_inventory_page_header_and_toolbar_share_one_aligned_layout(): void
    {
        $admin = $this->makeAdmin();

        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->assertOk()->getContent();

        // The layout already wraps the page-header section in .admin-pagehead;
        // the view must not nest a second one, which doubled the bottom gap and
        // misaligned the title against the table card.
        $this->assertSame(1, substr_count($html, 'class="admin-pagehead"'));
        $this->assertSame(1, substr_count($html, 'class="admin-pagehead__title"'));

        // The inventory card has its own hook for the tablet card layout and
        // the footer alignment, without changing the shared card styling.
        $this->assertStringContainsString('class="admin-table-wrap admin-table-wrap--inventory"', $html);

        // Search and filters share one responsive toolbar, with stable hooks
        // for the alignment rules.
        $this->assertStringContainsString('class="admin-table-toolbar inventory-toolbar"', $html);
        $this->assertStringContainsString('class="grow inventory-toolbar__search"', $html);
        $this->assertStringContainsString('class="inventory-toolbar__filters"', $html);
        $this->assertStringContainsString('class="btn-admin btn-admin--secondary btn-admin--sm inventory-toolbar__submit"', $html);
        $this->assertStringContainsString('aria-label="Search bicycles"', $html);

        $css = (string) file_get_contents(public_path('css/admin.css'));
        $this->assertStringContainsString('.admin-table-toolbar.inventory-toolbar {', $css);
        $this->assertMatchesRegularExpression(
            '/\.admin-table-toolbar\.inventory-toolbar \{.*?' .
            'display: grid;.*?grid-template-columns: minmax\(0, 1fr\) auto;/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.inventory-toolbar__filters \{.*?display: grid;.*?' .
            'grid-template-columns: minmax\(150px, 180px\) auto;/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.inventory-toolbar \.inventory-toolbar__submit \{.*?height: 34px;/s',
            $css
        );
        $this->assertStringContainsString('@media (max-width: 575.98px)', $css);
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 575\.98px\) \{\s*' .
            '\.admin-table-toolbar\.inventory-toolbar \{ grid-template-columns: minmax\(0, 1fr\); \}/',
            $css
        );
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

        // Plain-text values get a wrapper so they can ellipsize inside their
        // own column instead of painting over the neighbouring cell when the
        // table is at its floor width.
        $this->assertStringContainsString('class="cell-value"', $html);
        $this->assertStringContainsString('title="' . ucfirst($bike->condition ?? 'good') . '"', $html);
        $this->assertStringContainsString('title="₱' . number_format($bike->hourlyRate, 2) . '/hr"', $html);
    }

    public function test_the_actions_dropdown_is_placed_against_the_viewport(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);

        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->assertOk()->getContent();
        $cell = $this->actionsCell($html, $bike->serialNumber);

        // The menu is wired to Bootstrap; its controller portals it out of the
        // clipped inventory card while open.
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
            '/\.admin-table--inventory \.dropdown-menu,\s*\.admin-shell > \.inventory-row-menu \{[^}]*z-index: 1035/',
            $css
        );

        $js = (string) file_get_contents(public_path('js/admin.js'));

        $this->assertStringContainsString("strategy: 'fixed'", $js);
        $this->assertStringContainsString("boundary: 'viewport'", $js);
        $this->assertStringContainsString("el.addEventListener('show.bs.dropdown'", $js);
        $this->assertStringContainsString("document.body.appendChild(menu)", $js);
        $this->assertStringContainsString("el.addEventListener('hidden.bs.dropdown'", $js);
        $this->assertStringContainsString("menuPlaceholder.parentNode.insertBefore(menu, menuPlaceholder)", $js);
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
            $menuStart = strpos($cell, '<ul class="dropdown-menu dropdown-menu-end inventory-row-menu">');
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

    public function test_rental_history_menu_opens_the_filtered_history_page(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['serialNumber' => 'PDY-HISTORY-LINK']);
        $rental = $this->makeRental([
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_RETURNED,
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.bicycles.index'))
            ->assertOk()
            ->getContent();
        $cell = $this->actionsCell($html, $bike->serialNumber);

        $this->assertStringContainsString(
            route('admin.rentals.history', ['bicycle_id' => $bike->id]),
            $cell
        );

        $this->actingAs($admin)
            ->get(route('admin.rentals.history', ['bicycle_id' => $bike->id]))
            ->assertOk()
            ->assertSee($rental->rentalId);
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

        $menuStart = strpos($cell, '<ul class="dropdown-menu dropdown-menu-end inventory-row-menu">');
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
        $menuStart = strpos($cell, '<ul class="dropdown-menu dropdown-menu-end inventory-row-menu">');
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
        $menuStart = strpos($cell, '<ul class="dropdown-menu dropdown-menu-end inventory-row-menu">');
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
        $menuStart = strpos($cell, '<ul class="dropdown-menu dropdown-menu-end inventory-row-menu">');
        $inUse = strpos($cell, 'In Use');

        $this->assertNotFalse($menuStart);
        $this->assertNotFalse($inUse);
        $this->assertGreaterThan($menuStart, $inUse);
        $this->assertStringNotContainsString(route('admin.bicycles.lock', $bike->id), $cell);
        $this->assertStringContainsString('Device Management', substr($cell, $menuStart, 2000));
    }

    public function test_lock_is_refused_while_bicycle_is_rented(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);

        $this->from(route('admin.bicycles.index'))
            ->actingAs($admin)
            ->post(route('admin.bicycles.lock', $bike->id), ['action' => 'lock'])
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('device_commands', [
            'bicycleId' => $bike->id,
            'command' => 'lock',
        ]);
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
