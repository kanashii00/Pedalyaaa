<?php

namespace Tests\Feature;

use App\Models\Accident;
use App\Models\Bicycle;
use App\Models\Rental;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdmin();
    }

    public function test_index_renders(): void
    {
        $this->makeBicycle(['name' => 'Report Bike']);
        $this->makeRider(['name' => 'Report Rider']);

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index'))
            ->assertOk();
    }

    public function test_customer_tab_autoloads_and_filters_without_a_generate_step(): void
    {
        $this->makeRider(['name' => 'Auto Loaded Rider', 'email' => 'autoload@example.test']);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['tab' => 'customer']))
            ->assertOk();

        // The top section only filters + acts; there is no separate "Generate Report" step.
        $response->assertSee('customerReportForm')
            ->assertSee('Filter')
            ->assertSee('Print')
            ->assertSee('Export PDF')
            ->assertSee('Export Excel')
            ->assertSee('Export CSV')
            ->assertDontSee('Generate Report');

        // Filter controls the auto-load reads.
        $response->assertSee('customerSearch', false)
            ->assertSee('customerDateFrom', false)
            ->assertSee('customerDateTo', false)
            ->assertSee('customerStatus', false)
            ->assertSee('customerVerified', false);

        // The table renders itself on load and re-applies as filters change.
        $response->assertSee('applyNow();', false)
            ->assertSee('loadReport(form)', false);
    }

    public function test_customer_report_auto_load_query_returns_filtered_data(): void
    {
        $keep = $this->makeRider(['name' => 'Keep Me', 'status' => 'active']);
        $drop = $this->makeRider(['name' => 'Drop Me', 'status' => 'suspended']);

        $this->actingAs($this->admin)
            ->post(route('admin.reports.customer'), ['status' => 'active'])
            ->assertOk()
            ->assertJsonFragment(['email' => $keep->email])
            ->assertJsonMissing(['email' => $drop->email]);
    }

    public function test_customer_pagination_is_client_side_and_renders_one_table(): void
    {
        $this->makeRider(['name' => 'Paged Rider']);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['tab' => 'customer']))
            ->assertOk();

        $js = $response->getContent();

        // renderTable must forward the requested page, otherwise clicking page 2
        // silently re-renders page 1.
        $this->assertStringContainsString('function renderTable(type, records, page)', $js);
        $this->assertStringContainsString("case 'customer': return renderCustomerTable(records, target);", $js);

        // paginateTable must rebuild the results area, not splice a new table in
        // beside the old one (that produced two stacked tables).
        $this->assertStringContainsString("container.innerHTML = (_summaryHtml[type] || '') + renderTable(type, records, target);", $js);
        $this->assertStringNotContainsString('summaryEl.parentElement.outerHTML', $js);

        // Range text is always shown, page buttons appear once there are 2+ pages.
        $this->assertStringContainsString(">Showing ' + from + '-' + to + ' of ' + total", $js);
        $this->assertStringContainsString('if (totalPages <= 1) return html', $js);

        // Page numbers are generated from the record count, the Previous link
        // is dead on page 1, the Next link is dead on the last page, and a
        // stray page argument can never index outside the record set.
        $this->assertStringContainsString('for (var p = pStart; p <= pEnd; p++)', $js);
        $this->assertStringContainsString("(currentPage === 1 ? ' disabled' : '')", $js);
        $this->assertStringContainsString("(currentPage === totalPages ? ' disabled' : '')", $js);
        $this->assertStringContainsString('Math.min(Math.max(parseInt(page, 10) || 1, 1), totalPages)', $js);

        // Fixed page size of 10, and filters reset paging back to page 1.
        $this->assertStringContainsString('var _pageSize = 10;', $js);
        $this->assertStringContainsString('_reportData[type] = records; _reportPage[type] = 1;', $js);
    }

    public function test_customer_pagination_pages_eleven_records_of_ten_per_page(): void
    {
        $node = trim((string) shell_exec('node --version 2>&1'));
        if (!str_starts_with($node, 'v')) {
            $this->markTestSkipped('Node.js is required to execute the report page script.');
        }

        $script = base_path('tests/Js/reports-pagination-check.js');
        $output = [];
        $exitCode = 0;
        exec(escapeshellarg('node') . ' ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);
        $log = implode("\n", $output);

        $this->assertSame(0, $exitCode, $log);
        $this->assertStringContainsString('ALL CHECKS PASSED', $log);

        // The example the screen promises: 11 records, 10 per page.
        $this->assertStringContainsString('range reads "Showing 1-10 of 11"', $log);
        $this->assertStringContainsString('range reads "Showing 11-11 of 11"', $log);
        $this->assertStringContainsString('Previous (<<) is disabled on page 1', $log);
        $this->assertStringContainsString('Next (>>) is disabled on page 2', $log);
        $this->assertStringContainsString('paging serves cached records without a new request', $log);
        $this->assertStringContainsString('filters reset paging back to page 1', $log);
    }

    public function test_every_report_tab_autoloads_and_filters_without_a_generate_step(): void
    {
        $tabs = [
            'rental' => 'rentalSearch',
            'bicycle' => 'bicycleSearch',
            'theft' => 'theftSearch',
            'accident' => 'accidentSearch',
            'revenue' => 'revenueGroupBy',
        ];

        foreach ($tabs as $tab => $filterField) {
            $response = $this->actingAs($this->admin)
                ->get(route('admin.reports.index', ['tab' => $tab]))
                ->assertOk();

            // Top section is filters + actions only; the table needs no generate step.
            $response->assertSee($tab.'ReportForm')
                ->assertSee('bi-funnel me-1"></i>Filter', false)
                ->assertSee('Print')
                ->assertSee('Export PDF')
                ->assertSee('Export Excel')
                ->assertSee('Export CSV')
                ->assertDontSee('Generate Report');

            $response->assertSee($filterField, false);

            // Every tab is auto-loaded, re-run on filter change, and re-run
            // when its Clear Filters button resets the inputs.
            $js = $response->getContent();
            $this->assertStringContainsString(
                "var AUTO_TABS = ['customer', 'rental', 'bicycle', 'theft', 'accident', 'revenue'];",
                $js
            );
            $this->assertStringContainsString('window.applyActiveReportFilters = applyNow;', $js);
            $this->assertStringContainsString('loadReport(form);', $js);
            $this->assertStringContainsString('reloadActiveReport();', $js);
            $this->assertStringContainsString('function reloadActiveReport()', $js);
        }
    }

    public function test_revenue_and_incident_results_paginate_like_the_customer_table(): void
    {
        $js = $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['tab' => 'revenue']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('function renderRevenueTable(records, page)', $js);
        $this->assertStringContainsString("case 'revenue': return renderRevenueTable(records, target);", $js);
        $this->assertStringContainsString("html += buildPagination('revenue', total, totalPages);", $js);

        $this->assertStringContainsString('function renderIncidentTable(records, page)', $js);
        $this->assertStringContainsString("case 'incident': return renderIncidentTable(records, target);", $js);
        $this->assertStringContainsString("html += buildPagination('incident', total, totalPages);", $js);
    }

    public function test_rental_report_returns_json(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_COMPLETED, 'totalFee' => 50]);

        $this->actingAs($this->admin)
            ->post(route('admin.reports.rental'), [
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->addDay()->toDateString(),
                'user_id' => $rider->id,
                'bicycle_id' => $bike->id,
                'status' => Rental::STATUS_COMPLETED,
            ])
            ->assertOk()
            ->assertJsonStructure(['summary', 'data', 'reportId']);
    }

    public function test_revenue_report_returns_json(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_COMPLETED, 'totalFee' => 100, 'updated_at' => now()]);

        $this->actingAs($this->admin)
            ->post(route('admin.reports.revenue'), [
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->addDay()->toDateString(),
                'group_by' => 'day',
            ])
            ->assertOk()
            ->assertJsonStructure(['summary', 'data', 'reportId']);
    }

    public function test_incident_report_returns_json(): void
    {
        $bike = $this->makeBicycle();
        Accident::create([
            'bicycleId' => $bike->id,
            'type' => 'accident',
            'severity' => 'minor',
            'acknowledged' => false,
            'status' => 'open',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.reports.incident'), [
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->addDay()->toDateString(),
                'severity' => 'minor',
                'incident_type' => 'accident',
            ])
            ->assertOk()
            ->assertJsonStructure(['summary', 'data', 'reportId']);
    }

    public function test_export_csv_streams_rental_report(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_COMPLETED, 'totalFee' => 50]);

        $this->actingAs($this->admin)
            ->get(route('admin.reports.export.csv'))
            ->assertOk();
    }

    public function test_export_csv_revenue_and_incident_types(): void
    {
        $bike = $this->makeBicycle();
        Accident::create(['bicycleId' => $bike->id, 'type' => 'accident', 'severity' => 'minor', 'status' => 'open']);

        $this->actingAs($this->admin)->get(route('admin.reports.export.csv', ['type' => 'revenue']))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.reports.export.csv', ['type' => 'incident']))->assertOk();
    }

    public function test_export_excel_streams(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.export.excel'))
            ->assertOk();
    }

    public function test_rental_report_error_returns_500(): void
    {
        $service = Mockery::mock(ReportService::class);
        $service->shouldReceive('getRentalReport')->once()->andThrow(new \RuntimeException('DB down'));
        $this->app->instance(ReportService::class, $service);

        $this->actingAs($this->admin)
            ->post(route('admin.reports.rental'))
            ->assertStatus(500);
    }

    public function test_revenue_report_error_returns_500(): void
    {
        $service = Mockery::mock(ReportService::class);
        $service->shouldReceive('getRevenueReport')->once()->andThrow(new \RuntimeException('DB down'));
        $this->app->instance(ReportService::class, $service);

        $this->actingAs($this->admin)
            ->post(route('admin.reports.revenue'))
            ->assertStatus(500);
    }

    public function test_incident_report_error_returns_500(): void
    {
        $service = Mockery::mock(ReportService::class);
        $service->shouldReceive('getIncidentReport')->once()->andThrow(new \RuntimeException('DB down'));
        $this->app->instance(ReportService::class, $service);

        $this->actingAs($this->admin)
            ->post(route('admin.reports.incident'))
            ->assertStatus(500);
    }

    public function test_accident_report_error_returns_500(): void
    {
        $service = Mockery::mock(ReportService::class);
        $service->shouldReceive('getAccidentReport')->once()->andThrow(new \RuntimeException('DB down'));
        $this->app->instance(ReportService::class, $service);

        $this->actingAs($this->admin)
            ->post(route('admin.reports.accident'))
            ->assertStatus(500);
    }

    public function test_customer_report_error_returns_500(): void
    {
        $service = Mockery::mock(ReportService::class);
        $service->shouldReceive('getCustomerReport')->once()->andThrow(new \RuntimeException('DB down'));
        $this->app->instance(ReportService::class, $service);

        $this->actingAs($this->admin)
            ->post(route('admin.reports.customer'))
            ->assertStatus(500);
    }

    public function test_bicycle_report_error_returns_500(): void
    {
        $service = Mockery::mock(ReportService::class);
        $service->shouldReceive('getBicycleReport')->once()->andThrow(new \RuntimeException('DB down'));
        $this->app->instance(ReportService::class, $service);

        $this->actingAs($this->admin)
            ->post(route('admin.reports.bicycle'))
            ->assertStatus(500);
    }

    public function test_theft_report_error_returns_500(): void
    {
        $service = Mockery::mock(ReportService::class);
        $service->shouldReceive('getTheftReport')->once()->andThrow(new \RuntimeException('DB down'));
        $this->app->instance(ReportService::class, $service);

        $this->actingAs($this->admin)
            ->post(route('admin.reports.theft'))
            ->assertStatus(500);
    }

    public function test_export_csv_bicycle_and_theft_types(): void
    {
        $bike = $this->makeBicycle();

        $this->actingAs($this->admin)->get(route('admin.reports.export.csv', ['type' => 'bicycle']))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.reports.export.csv', ['type' => 'theft']))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.reports.export.csv', ['type' => 'customer']))->assertOk();
    }

    public function test_export_excel_bicycle_and_theft_types(): void
    {
        $this->actingAs($this->admin)->get(route('admin.reports.export.excel', ['type' => 'bicycle']))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.reports.export.excel', ['type' => 'theft']))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.reports.export.excel', ['type' => 'customer']))->assertOk();
    }

    public function test_export_csv_all_types_covers_build_report_match(): void
    {
        $this->actingAs($this->admin)->get(route('admin.reports.export.csv', ['type' => 'rental']))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.reports.export.csv', ['type' => 'accident']))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.reports.export.csv', ['type' => 'revenue']))->assertOk();
    }

    public function test_accident_report_returns_json(): void
    {
        $bike = $this->makeBicycle();
        Accident::create([
            'bicycleId' => $bike->id,
            'type' => 'accident',
            'severity' => 'minor',
            'acknowledged' => false,
            'status' => 'open',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.reports.accident'), [
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->addDay()->toDateString(),
            ])
            ->assertOk()
            ->assertJsonStructure(['summary', 'data', 'reportId']);
    }

    public function test_customer_report_returns_json(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.reports.customer'))
            ->assertOk()
            ->assertJsonStructure(['summary', 'data', 'reportId']);
    }

    public function test_bicycle_report_returns_json(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.reports.bicycle'))
            ->assertOk()
            ->assertJsonStructure(['summary', 'data', 'reportId']);
    }

    public function test_theft_report_returns_json(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.reports.theft'))
            ->assertOk()
            ->assertJsonStructure(['summary', 'data', 'reportId']);
    }
}
