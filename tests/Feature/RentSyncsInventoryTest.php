<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\Rental;
use App\Models\User;
use App\Services\CacheRegistry;
use App\Services\RentalService;
use App\Services\RiderCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class RentSyncsInventoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function test_starting_a_rental_syncs_the_bicycle_inventory(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => 'PDY-001',
        ]);

        $cache = app(RiderCacheService::class);

        // Warm the cache so we can prove renting invalidates it.
        $cache->availableBicycles();
        $versionBefore = CacheRegistry::bicyclesVersion();

        $rental = app(RentalService::class)->startRental($rider, $bicycle->id, 60, 'cash');

        $after = $bicycle->fresh();
        $versionAfter = CacheRegistry::bicyclesVersion();
        $availableNow = $cache->availableBicycles();

        $this->assertSame(Bicycle::STATUS_RENTED, $after->status);
        $this->assertSame($rider->id, $after->currentRider);
        $this->assertEquals($rental->id, $after->currentRentalId);
        $this->assertGreaterThan($versionBefore, $versionAfter, 'cache version must bump on rent');
        $this->assertFalse($availableNow->contains('id', $bicycle->id), 'rented bike must leave the available list');

        // What the admin inventory actually renders
        $admin = $this->makeAdmin();
        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->getContent();

        $this->assertStringContainsString($rider->name, $html, 'inventory must show who is riding');
        $this->assertStringContainsString($rental->rentalId, $html, 'inventory must show which rental');
        $this->assertMatchesRegularExpression('/<th[^>]*>\s*Current Renter/i', $html);
        $this->assertMatchesRegularExpression('/<th[^>]*>\s*Current Rental/i', $html);
    }

    public function test_available_bicycles_show_no_renter_in_the_inventory(): void
    {
        $admin = $this->makeAdmin();
        $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE, 'serialNumber' => 'PDY-009']);

        $html = $this->actingAs($admin)->get(route('admin.bicycles.index'))->getContent();

        // An idle bike must render the em dash placeholder, not stale data.
        $this->assertStringContainsString('PDY-009', $html);
        $this->assertStringContainsString('Current Renter', $html);
        $this->assertStringNotContainsString(
            'View Rental',
            $html,
            'an available bicycle must not offer a View Rental action'
        );
    }
}
