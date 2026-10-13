<?php

namespace Tests\Feature;

use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * Sidebar count badges stay wired to their own queries: blacklisted riders
 * vs. maintenance items requiring attention. Each badge renders its own
 * count (never a shared/hardcoded number), caps at 99+, hides at zero, and
 * the collapsed-rail group pills carry the same per-group totals.
 */
class AdminSidebarBadgeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function adminPage(): string
    {
        return $this->actingAs($this->makeAdmin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();
    }

    /**
     * Find the pill rendered directly after a nav label, e.g.
     * `<span>Blacklisted Customers</span><span ...badge...>3</span>`.
     */
    private function navBadge(string $html, string $label): ?string
    {
        $matched = preg_match(
            '/' . preg_quote($label, '/') . '<\/span>\s*<span[^>]*class="[^"]*admin-nav__badge[^"]*"[^>]*>([^<]*)</',
            $html,
            $matches
        );

        return $matched ? trim($matches[1]) : null;
    }

    private function groupBadges(string $html): array
    {
        preg_match_all(
            '/admin-nav__group-badge"[^>]*>([^<]*)</',
            $html,
            $matches
        );

        return array_map('trim', $matches[1]);
    }

    public function test_blacklisted_badge_counts_blacklisted_and_suspended_riders(): void
    {
        $this->makeRider(['status' => User::STATUS_ACTIVE]);
        $this->makeRider(['status' => User::STATUS_SUSPENDED]);
        $this->makeRider(['status' => User::STATUS_SUSPENDED]);
        $this->makeRider(['status' => User::STATUS_BLACKLISTED]);

        $this->assertSame('3', $this->navBadge($this->adminPage(), 'Blacklisted Customers'));
    }

    private function makeMaintenance(string $status): void
    {
        $this->makeMaintenanceRecord([
            'bicycleId' => $this->makeBicycle()->id,
            'status' => $status,
        ]);
    }

    public function test_maintenance_badge_counts_scheduled_and_in_progress_items(): void
    {
        $this->makeMaintenance(MaintenanceRecord::STATUS_SCHEDULED);
        $this->makeMaintenance(MaintenanceRecord::STATUS_SCHEDULED);
        $this->makeMaintenance(MaintenanceRecord::STATUS_IN_PROGRESS);
        $this->makeMaintenance(MaintenanceRecord::STATUS_COMPLETED);

        $this->assertSame('3', $this->navBadge($this->adminPage(), 'Maintenance Schedule'));
    }

    public function test_each_badge_uses_its_own_data_source(): void
    {
        $this->makeRider(['status' => User::STATUS_BLACKLISTED]);
        $this->makeRider(['status' => User::STATUS_BLACKLISTED]);
        for ($i = 0; $i < 5; $i++) {
            $this->makeMaintenance(MaintenanceRecord::STATUS_SCHEDULED);
        }

        $html = $this->adminPage();

        $this->assertSame('2', $this->navBadge($html, 'Blacklisted Customers'));
        $this->assertSame('5', $this->navBadge($html, 'Maintenance Schedule'));
    }

    public function test_large_counts_cap_at_99_plus(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->makeRider(['status' => User::STATUS_SUSPENDED]);
        }

        $html = $this->adminPage();

        $this->assertSame('99+', $this->navBadge($html, 'Blacklisted Customers'));
        $this->assertContains('99+', $this->groupBadges($html));
    }

    public function test_badges_hide_when_counts_are_zero(): void
    {
        $html = $this->adminPage();

        // Zero-count pills render display:none ...
        $matched = preg_match(
            '/Blacklisted Customers<\/span>\s*<span([^>]*)>/',
            $html,
            $matches
        );
        $this->assertSame(1, $matched);
        $this->assertStringContainsString('display:none', $matches[1]);

        // ... and no rail group pill renders at all.
        $this->assertSame([], $this->groupBadges($html));
    }

    public function test_collapsed_rail_group_pills_carry_each_group_total(): void
    {
        $this->makeRider(['status' => User::STATUS_BLACKLISTED]);
        $this->makeRider(['status' => User::STATUS_SUSPENDED]);
        $this->makeMaintenance(MaintenanceRecord::STATUS_SCHEDULED);

        $groups = $this->groupBadges($this->adminPage());

        // Customer Management (blacklisted only) renders before Bicycle
        // Management (maintenance only) in sidebar order.
        $this->assertSame(['2', '1'], $groups);
    }

    public function test_sidebar_badge_pill_styles_exist(): void
    {
        $css = file_get_contents(public_path('css/admin.css'));

        $this->assertStringContainsString('.admin-nav__link span.admin-nav__badge', $css);
        $this->assertStringContainsString('.admin-nav__link > .admin-nav__badge.admin-nav__group-badge', $css);
    }
}
