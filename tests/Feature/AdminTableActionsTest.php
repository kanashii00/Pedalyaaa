<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class AdminTableActionsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    protected function setUp(): void
    {
        parent::setUp();
        // The payments index boots PayMongoService, which requires config
        // even though this suite never hits the API.
        config([
            'services.paymongo' => [
                'secret_key' => 'sk_test',
                'public_key' => 'pk_test',
                'webhook_secret' => 'test',
            ],
        ]);
    }

    private function adminPage(string $route, array $params = []): string
    {
        return $this->actingAs($this->makeAdmin())
            ->get(route($route, $params))
            ->assertOk()
            ->getContent();
    }

    public function test_customer_list_action_column_is_contained_and_labelled(): void
    {
        $this->makeRider(['name' => 'Action Rider', 'verified' => false]);

        $html = $this->adminPage('admin.riders.index');

        // Fixed-width column on header and body cells.
        $this->assertStringContainsString('<th class="col-actions">Actions</th>', $html);
        $this->assertStringContainsString('data-label="Actions" class="col-actions"', $html);

        // One flex row per record with a uniform gap.
        $this->assertStringContainsString('<div class="actions-row">', $html);

        // Icon-only buttons expose accessible names mirroring their tooltips.
        foreach (['Review Verification', 'Disable', 'Suspend'] as $action) {
            $this->assertStringContainsString('title="' . $action . '"', $html);
            $this->assertStringContainsString('aria-label="' . $action . '"', $html);
        }

        // Handlers, permissions and verification flow are untouched.
        $this->assertStringContainsString('openVerifyReview(this)', $html);
        $this->assertStringContainsString(route('admin.riders.status', 1), $html);
        $this->assertStringContainsString('data-confirm="Disable this rider?"', $html);
        $this->assertStringContainsString('data-confirm="Suspend this rider?"', $html);

        // Table scrolls instead of clipping buttons on narrow screens.
        $this->assertStringContainsString('<div class="table-responsive">', $html);
    }

    public function test_customer_tables_share_the_same_action_column(): void
    {
        $this->makeRider(['name' => 'Verified Vic', 'verified' => true]);
        $this->makeRider(['name' => 'Blocked Bob', 'status' => 'blacklisted']);

        foreach (['admin.riders.verified' => 'View Customer', 'admin.riders.blacklisted' => 'View Customer'] as $route => $label) {
            $html = $this->adminPage($route);

            $this->assertStringContainsString('<th class="col-actions">Actions</th>', $html);
            $this->assertStringContainsString('data-label="Actions" class="col-actions"', $html);
            $this->assertStringContainsString('<div class="actions-row">', $html);
            $this->assertStringContainsString('aria-label="' . $label . '"', $html);
            $this->assertStringContainsString('<div class="table-responsive">', $html);
        }

        // Blacklist flow keeps its confirm copy and manage dialog hook.
        $verified = $this->adminPage('admin.riders.verified');
        $this->assertStringContainsString('data-confirm="Blacklist this customer?', $verified);
        $blacklisted = $this->adminPage('admin.riders.blacklisted');
        $this->assertStringContainsString('openBlacklistManage(this)', $blacklisted);
        $this->assertStringContainsString('aria-label="Restore to Active"', $blacklisted);
    }

    public function test_incident_and_payment_tables_use_the_shared_component(): void
    {
        $bike = $this->makeBicycle();
        \App\Models\Accident::create(['bicycleId' => $bike->id, 'type' => 'accident', 'severity' => 'major', 'description' => 'Collision']);
        \App\Models\Accident::create(['bicycleId' => $bike->id, 'type' => 'theft', 'severity' => 'major', 'status' => 'open', 'acknowledged' => false]);
        $this->makePayment(['status' => 'paid']);
        $this->makeMaintenanceRecord(['bicycleId' => $bike->id]);

        $html = $this->adminPage('admin.accidents.index');
        $this->assertStringContainsString('<th class="col-actions">Actions</th>', $html);
        $this->assertStringContainsString('<div class="actions-row">', $html);
        $this->assertStringNotContainsString('<div class="d-flex gap-1">', $html);

        $html = $this->adminPage('admin.payments.index');
        $this->assertStringContainsString('<th class="col-actions">Actions</th>', $html);
        $this->assertStringContainsString('data-label="Actions" class="col-actions"', $html);
        $this->assertStringContainsString('<div class="actions-row">', $html);
        $this->assertStringContainsString('aria-label="View Receipt"', $html);

        $html = $this->adminPage('admin.theft-alerts.index');
        $this->assertStringContainsString('<th class="col-actions">Actions</th>', $html);
        $this->assertStringContainsString('aria-label="Acknowledge"', $html);

        $html = $this->adminPage('admin.maintenance.index');
        $this->assertStringContainsString('<th class="col-actions">Actions</th>', $html);
        $this->assertStringContainsString('data-label="Actions" class="col-actions"', $html);
    }

    public function test_shared_action_styles_define_the_fixed_box(): void
    {
        $css = file_get_contents(public_path('css/admin.css'));

        $this->assertStringContainsString('.admin-table th.col-actions', $css);
        $this->assertStringContainsString('min-width: 132px', $css);
        $this->assertStringContainsString('.actions-row > *', $css);
        $this->assertStringContainsString('box-sizing: border-box', $css);
        $this->assertStringContainsString('.actions-row .btn-admin--sm i', $css);
    }
}
