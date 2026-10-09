<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class ReportRentalDetailModalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdmin();
    }

    private function reportsPage(): string
    {
        return $this->actingAs($this->admin)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->getContent();
    }

    public function test_rental_modal_uses_the_compact_accessible_shell(): void
    {
        $html = $this->reportsPage();

        $this->assertStringContainsString('id="rentalDetailModal"', $html);
        $this->assertStringContainsString('aria-labelledby="rentalDetailTitle"', $html);
        $this->assertStringContainsString('id="rentalDetailTitle"', $html);
        $this->assertStringContainsString('id="rentalDetailSubtitle"', $html);
        $this->assertStringContainsString('rental-detail__close', $html);
        $this->assertStringContainsString('aria-label="Close rental details"', $html);
        $this->assertStringContainsString('modal-dialog-scrollable', $html);
        $this->assertStringContainsString('id="rentalDetailBody"', $html);

        // The oversized green header is gone from the rental modal only.
        $this->assertStringNotContainsString(
            '<i class="bi bi-bicycle me-2"></i>Rental Details',
            $html
        );
    }

    public function test_rental_renderer_keeps_its_binding_and_sections(): void
    {
        $html = $this->reportsPage();

        // Same entry point, same record source, same dialog call.
        $this->assertStringContainsString('function viewRentalDetail(index)', $html);
        $this->assertStringContainsString('_reportData.rental[index]', $html);
        $this->assertStringContainsString('onclick="viewRentalDetail(', $html);
        $this->assertStringContainsString(
            "new bootstrap.Modal(document.getElementById('rentalDetailModal')).show()",
            $html
        );

        // Structured enterprise sections fed by the record.
        foreach (['Rental Information', 'Rider Information', 'Bicycle Information', 'Payment Summary', 'Final Fee'] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        // Fee breakdown reads the settled fields, not placeholders.
        foreach (['finalFee', 'overdueFee', 'totalFee', 'ratePerHour', 'paymentStatus', 'bicycleSerial'] as $field) {
            $this->assertStringContainsString($field, $html);
        }
    }

    public function test_customer_modal_uses_the_matching_dark_shell(): void
    {
        $html = $this->reportsPage();

        $this->assertStringContainsString('id="customerDetailModal"', $html);
        $this->assertStringContainsString('aria-labelledby="customerDetailTitle"', $html);
        $this->assertStringContainsString('id="customerDetailTitle"', $html);
        $this->assertStringContainsString('id="customerDetailSubtitle"', $html);
        $this->assertStringContainsString('customer-detail__close', $html);
        $this->assertStringContainsString('aria-label="Close customer details"', $html);
        $this->assertStringContainsString('id="customerDetailBody"', $html);

        // The bright green header is gone from the customer modal.
        $this->assertStringNotContainsString(
            '<i class="bi bi-person-circle me-2"></i>Customer Details',
            $html
        );
    }

    public function test_customer_renderer_keeps_its_binding_and_sections(): void
    {
        $html = $this->reportsPage();

        // Same entry point, same record source, same dialog call.
        $this->assertStringContainsString('function viewCustomerDetail(index)', $html);
        $this->assertStringContainsString('_reportData.customer[index]', $html);
        $this->assertStringContainsString('onclick="viewCustomerDetail(', $html);
        $this->assertStringContainsString(
            "new bootstrap.Modal(document.getElementById('customerDetailModal')).show()",
            $html
        );

        // Structured sections fed by the record, with honest missing values.
        // (The avatar-led profile header needs no text label; its block is
        // asserted via its class below.)
        foreach (['Contact Information', 'Account Status', 'Rental Summary', 'Total Spent'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString('cd-profile', $html);
        foreach (['Not provided', 'Never'] as $fallback) {
            $this->assertStringContainsString($fallback, $html);
        }

        // Record fields read from the row, not placeholders.
        foreach (['phoneNumber', 'studentId', 'totalSpent', 'rentals_count', 'last_login_at', 'blacklistReason'] as $field) {
            $this->assertStringContainsString($field, $html);
        }

        // Verification and status badges use the shared badge system.
        $this->assertStringContainsString('badge-admin--success', $html);
        $this->assertStringContainsString('Unverified', $html);
    }

    public function test_accident_modal_is_untouched(): void
    {
        $html = $this->reportsPage();

        $this->assertStringContainsString('id="accidentDetailModal"', $html);
        $this->assertStringContainsString('function viewAccidentDetail(index)', $html);

        // Only the accident modal keeps the legacy green header now.
        $this->assertSame(1, substr_count($html, 'background: var(--brand); color: #fff;'));
    }

    public function test_rental_table_filters_and_pagination_are_intact(): void
    {
        $html = $this->reportsPage();

        $this->assertStringContainsString('function renderRentalTable(records, page)', $html);
        $this->assertStringContainsString("buildPagination('rental',", $html);

        // The rental filter form renders on the rental tab.
        $rentalTab = $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['tab' => 'rental']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('rentalReportForm', $rentalTab);
    }

    public function test_pagination_uses_the_shared_dark_pattern(): void
    {
        $html = $this->reportsPage();

        // Record count bottom-left, controls bottom-right in one footer row.
        $this->assertStringContainsString('admin-table-foot report-pagination', $html);
        $this->assertStringContainsString('admin-table-foot__info', $html);
        $this->assertStringContainsString('of \' + total + \' records', $html);

        // Navy buttons with Previous/Next, active + disabled states.
        $this->assertStringContainsString('admin-pagination', $html);
        $this->assertStringContainsString('Previous', $html);
        $this->assertStringContainsString('is-active', $html);
        $this->assertStringContainsString('is-disabled', $html);
        $this->assertStringContainsString('aria-current="page"', $html);

        // Paging behaviour unchanged: same page size, state and entry point.
        $this->assertStringContainsString('paginateTable(', $html);
        $this->assertStringContainsString('_reportPage[type]', $html);
        $this->assertStringContainsString('_pageSize', $html);

        // No Bootstrap white pagination buttons remain in the builder.
        $this->assertStringNotContainsString('page-link', $html);

        // Green-text tuning is scoped; shared states come from the system.
        $this->assertStringContainsString('.report-pagination .admin-pagination__item', $html);

        // Footer stays inside the table card for every report module, since
        // all of them render through the same two injection points.
        $this->assertSame(2, substr_count($html, 'admin-table-wrap report-table-wrap'));
        $this->assertSame(7, substr_count($html, "buildPagination('"));
    }
}
