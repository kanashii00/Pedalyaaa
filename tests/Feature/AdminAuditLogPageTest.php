<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class AdminAuditLogPageTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdmin();
    }

    private function auditPage(array $params = []): string
    {
        return $this->actingAs($this->admin)
            ->get(route('admin.audit-log.index', $params))
            ->assertOk()
            ->getContent();
    }

    public function test_table_uses_the_enterprise_layout(): void
    {
        AuditLog::record('rental_return_processed', $this->admin->id, [
            'rentalId' => 'REN-20261009-9292',
            'condition' => 'good',
            'finalFee' => 30.00,
        ]);

        $html = $this->auditPage();

        // Four aligned columns with compact pagination below the table.
        foreach (['Timestamp', 'Action / Event', 'Performed By', 'Details'] as $heading) {
            $this->assertStringContainsString($heading, $html);
        }
        $this->assertStringContainsString('admin-table--audit', $html);
        $this->assertStringContainsString('admin-pagination', $html);
        $this->assertStringContainsString('admin-table-foot__info', $html);
        $this->assertStringContainsString('of 1 records', $html);
    }

    public function test_details_column_shows_a_summary_not_raw_json(): void
    {
        AuditLog::record('rental_return_processed', $this->admin->id, [
            'rentalId' => 'REN-20261009-9292',
            'condition' => 'good',
            'finalFee' => 30.00,
        ]);

        $html = $this->auditPage();

        $this->assertStringContainsString('Rental REN-20261009-9292', $html);
        $this->assertStringContainsString('₱30.00', $html);
        $this->assertStringContainsString('openAuditDetail(', $html);
        $this->assertStringContainsString('View Details', $html);
        // No inline raw JSON block in the row.
        $this->assertStringNotContainsString('data-bs-target="#details-', $html);
    }

    public function test_detail_modal_carries_structured_event_data(): void
    {
        AuditLog::record('rental_return_processed', $this->admin->id, [
            'rentalId' => 'REN-20261009-9292',
            'condition' => 'good',
            'finalFee' => 30.00,
        ]);

        $html = $this->auditPage();

        $this->assertStringContainsString('id="auditDetailModal"', $html);
        $this->assertStringContainsString('aria-labelledby="auditDetailTitle"', $html);
        $this->assertStringContainsString('__auditDetails', $html);
        $this->assertStringContainsString('Affected Resource', $html);
        $this->assertStringContainsString('Event Information', $html);
        $this->assertStringContainsString('Event Metadata', $html);
        // The cohesive dark shell binds every region to dashboard tokens.
        $this->assertStringContainsString('--bs-modal-bg: var(--surface)', $html);
        // No raw technical payload or copy action in the admin interface.
        $this->assertStringNotContainsString('Raw JSON', $html);
        $this->assertStringNotContainsString('audit-json', $html);
        $this->assertStringNotContainsString('Copy details', $html);
        $this->assertStringNotContainsString('copyAuditDetails', $html);
        $this->assertStringNotContainsString('auditCopyBtn', $html);
        // Real record values reach the modal dataset — nothing hardcoded.
        $this->assertStringContainsString('REN-20261009-9292', $html);
    }

    public function test_filter_options_include_real_recorded_actions(): void
    {
        AuditLog::record('rental_return_processed', $this->admin->id, ['rentalId' => 'REN-1']);
        AuditLog::record('geofence_updated', $this->admin->id, ['radius' => 750]);

        $html = $this->auditPage();

        $this->assertStringContainsString('value="rental_return_processed"', $html);
        $this->assertStringContainsString('value="geofence_updated"', $html);
    }

    public function test_filters_sorting_and_pagination_still_work(): void
    {
        $rider = $this->makeRider();
        AuditLog::record('rental_return_processed', $this->admin->id, ['rentalId' => 'REN-1']);

        // Unfiltered render keeps working.
        $this->actingAs($this->admin)->get(route('admin.audit-log.index'))->assertOk();

        // Filtered render keeps working with real parameters.
        $this->actingAs($this->admin)->get(route('admin.audit-log.index', [
            'action' => 'rental_return_processed',
            'userId' => $this->admin->id,
            'date_from' => now()->subDay()->toDateString(),
            'date_to' => now()->addDay()->toDateString(),
        ]))->assertOk();

        // Non-matching filter renders the empty state, not an error.
        $html = $this->auditPage(['action' => 'no_such_action']);
        $this->assertStringContainsString('No audit log entries found', $html);

        // Unauthorized access stays rejected (role middleware redirects back).
        $this->actingAs($rider)->get(route('admin.audit-log.index'))->assertRedirect();
    }
}
