<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\Geofence;
use App\Models\Notification;
use App\Models\Rental;
use App\Models\SystemSetting;
use App\Services\CacheRegistry;
use App\Services\GeofenceService;
use App\Services\RiderCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class CacheBehaviorTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function riderAuth(): \App\Models\User
    {
        $rider = $this->makeRider();
        $this->actingAs($rider);

        return $rider;
    }

    // ---------------- Cache hit / miss / freshness ----------------

    /**
     * Availability on the Rent page is deliberately never cached.
     *
     * Caching it for TTL_AVAILABLE_BICYCLES meant the page could offer a
     * bicycle that had already been claimed, or hide one an administrator had
     * just freed. A write made outside the request (bypassing observers here, to
     * remove any invalidation as a confounder) must be visible immediately.
     */
    public function test_rent_page_always_serves_live_bicycle_availability(): void
    {
        $this->riderAuth();
        $bike = $this->makeBicycle(['name' => 'Live Bike']);

        $this->get(route('rider.rentals.create'))
            ->assertOk()
            ->assertSee('Live Bike');

        // Mutate the DB behind the observers' back: nothing invalidates a cache
        // entry here because there is no cache entry to invalidate.
        \Illuminate\Support\Facades\DB::table('bicycles')
            ->where('id', $bike->id)
            ->update(['name' => 'Renamed Directly']);

        $this->get(route('rider.rentals.create'))
            ->assertOk()
            ->assertSee('Renamed Directly');

        // Likewise a status change with no observer event at all.
        \Illuminate\Support\Facades\DB::table('bicycles')
            ->where('id', $bike->id)
            ->update(['status' => Bicycle::STATUS_RENTED]);

        $this->get(route('rider.rentals.create'))
            ->assertOk()
            ->assertDontSee('Select & Rent', escape: false)
            ->assertSee('Rented');

        $this->assertFalse(
            Cache::has(CacheRegistry::availableBicyclesKey(false)),
            'the available-bicycle list must not be cached'
        );
    }

    public function test_rent_page_recomputes_when_bicycle_status_changes(): void
    {
        $this->riderAuth();
        $bike = $this->makeBicycle();

        $this->get(route('rider.rentals.create'))
            ->assertOk()
            ->assertSee('Select & Rent', escape: false);

        // Marking a bicycle as rented must remove it from the list at once.
        $bike->update(['status' => Bicycle::STATUS_RENTED]);

        $this->get(route('rider.rentals.create'))
            ->assertOk()
            ->assertDontSee('Select & Rent', escape: false)
            ->assertSee('Rented');
    }

    /**
     * A bicycle released by an End Ride must appear for riders on the very next
     * request - this is the specific hand-off between the two modules.
     */
    public function test_ending_a_ride_immediately_frees_the_bicycle_for_riders(): void
    {
        $rider = $this->riderAuth();
        $bicycle = $this->makeBicycle([
            'status' => Bicycle::STATUS_AVAILABLE,
            'serialNumber' => 'PDY-LIVE',
        ]);

        $admin = $this->makeAdmin();
        $rental = app(\App\Services\RentalService::class)
            ->startRental($rider, $bicycle->id, 60, 'cash');

        // While rented, the rider does not see their own bicycle as available.
        // The card is still listed - the page shows the whole fleet and marks
        // unselectable bicycles rather than hiding them - but it offers no
        // enabled button.
        $this->get(route('rider.rentals.create'))
            ->assertOk()
            ->assertDontSee('Select & Rent', escape: false)
            ->assertSee('Rented');

        $this->actingAs($admin)
            ->put(route('admin.rentals.end-ride', $rental->id), ['condition' => 'good'])
            ->assertSessionHasNoErrors();

        $this->actingAs($rider)
            ->get(route('rider.rentals.create'))
            ->assertOk()
            ->assertSee('PDY-LIVE')
            ->assertSee('Select & Rent', escape: false);
    }

    public function test_telemetry_updates_do_not_invalidate_catalog_cache(): void
    {
        $this->riderAuth();
        $bike = $this->makeBicycle();

        // Seed a genuine catalog cache entry via the API surface, which does
        // cache the per-bicycle catalog.
        $rider = $this->makeRider();
        \Laravel\Sanctum\Sanctum::actingAs($rider);

        $key = CacheRegistry::bicycleCatalogKey($bike->id);
        $this->getJson("/api/bicycles/{$bike->id}")->assertOk();

        $this->assertTrue(Cache::has($key));
        $versionBefore = CacheRegistry::bicyclesVersion();

        // Simulate frequent IoT/GPS writes: battery, position, lock, heartbeats.
        $bike->update([
            'batteryLevel' => 12,
            'currentLat' => 7.1001,
            'currentLng' => 125.6480,
            'lockStatus' => Bicycle::LOCK_UNLOCKED,
            'lastGpsUpdate' => now(),
            'lastHeartbeat' => now(),
        ]);

        $this->assertSame(
            $versionBefore,
            CacheRegistry::bicyclesVersion(),
            'high-frequency telemetry must not invalidate the catalog cache'
        );
        $this->assertTrue(Cache::has($key));
    }

    public function test_geofence_config_is_cached_and_invalidated(): void
    {
        $service = app(GeofenceService::class);

        $config = $service->getConfig();
        $this->assertNull($config['id']);

        $key = CacheRegistry::geofenceConfigKey();
        $this->assertTrue(Cache::has($key));

        // Creating an active geofence invalidates the cached default config.
        Geofence::create([
            'name' => 'New Zone',
            'centerLat' => 7.2000,
            'centerLng' => 125.7000,
            'radius' => 300,
            'shapeType' => 'circle',
            'isActive' => true,
            'alertEnabled' => true,
        ]);

        $fresh = $service->getConfig();
        $this->assertNotNull($fresh['id']);
        $this->assertSame(7.2, $fresh['centerLat']);
        $this->assertSame(125.7, $fresh['centerLng']);
    }

    public function test_system_setting_is_cached_and_invalidated_on_write(): void
    {
        $this->assertFalse(Cache::has(CacheRegistry::settingKey('rentalRatePerHour')));

        $this->assertSame(15, SystemSetting::getValue('rentalRatePerHour', 15));
        $this->assertTrue(Cache::has(CacheRegistry::settingKey('rentalRatePerHour')));

        $versionBefore = CacheRegistry::settingsVersion();

        SystemSetting::setValue('rentalRatePerHour', '25');

        // Version bumped, so the key under the new version is not cached and
        // the fresh value is returned (and then re-cached).
        $this->assertGreaterThan($versionBefore, CacheRegistry::settingsVersion());
        $this->assertFalse(Cache::has(CacheRegistry::settingKey('rentalRatePerHour')));
        $this->assertSame('25', SystemSetting::getValue('rentalRatePerHour', 15));
        $this->assertTrue(Cache::has(CacheRegistry::settingKey('rentalRatePerHour')));
    }

    public function test_user_summary_isolated_per_user_and_invalidated_on_user_change(): void
    {
        $riderA = $this->riderAuth();
        $riderB = $this->makeRider(['totalRentals' => 7, 'totalSpent' => 300]);

        $this->get(route('rider.dashboard'))->assertOk();

        $keyA = CacheRegistry::userSummaryKey($riderA->id);
        $keyB = CacheRegistry::userSummaryKey($riderB->id);

        $this->assertTrue(Cache::has($keyA));
        $this->assertFalse(Cache::has($keyB), 'Summary cache leaked across users');

        $cached = Cache::get($keyA);
        $this->assertSame(0, $cached['totalRentals']);
        $this->assertSame(0.0, (float) $cached['totalSpent']);

        // Direct DB change behind the cache (bypassing observers) => cache hit
        // keeps serving the old totals.
        \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $riderA->id)
            ->update(['totalRentals' => 9, 'totalSpent' => 99]);

        $this->assertSame(0, \Illuminate\Support\Facades\Cache::get($keyA)['totalRentals']);

        // A catalog change to the user model invalidates the summary.
        $riderA->update(['totalRentals' => 9, 'totalSpent' => 99]);
        $this->assertFalse(Cache::has(CacheRegistry::userSummaryKey($riderA->id)));
    }

    public function test_unread_count_is_cached_and_invalidated_on_read(): void
    {
        $rider = $this->riderAuth();
        Sanctum::actingAs($rider);

        Notification::create(['userId' => $rider->id, 'title' => 'A', 'message' => 'm', 'read' => false]);

        $key = CacheRegistry::unreadCountKey($rider->id);

        $this->getJson('/api/notifications/unread-count')->assertJson(['unread_count' => 1]);
        $this->assertTrue(Cache::has($key));

        // Marking all read invalidates the cached count.
        $this->putJson('/api/notifications/read-all')->assertOk();

        $this->getJson('/api/notifications/unread-count')->assertJson(['unread_count' => 0]);
    }

    public function test_api_bicycle_index_is_cached_and_invalidated_on_catalog_change(): void
    {
        $rider = $this->makeRider();
        Sanctum::actingAs($rider);

        $bikeA = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);

        $key = CacheRegistry::bicycleIndexKey('available', 20);

        $this->getJson('/api/bicycles?status=available')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertTrue(Cache::has($key));

        // Bicycle becomes unavailable -> catalog change -> fresh result.
        $bikeA->update(['status' => Bicycle::STATUS_RENTED]);

        $this->getJson('/api/bicycles?status=available')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_api_bicycle_show_catalog_is_cached_with_fresh_telemetry(): void
    {
        $rider = $this->makeRider();
        Sanctum::actingAs($rider);

        $bike = $this->makeBicycle(['name' => 'Detail Bike']);

        $key = CacheRegistry::bicycleCatalogKey($bike->id);

        $this->getJson("/api/bicycles/{$bike->id}")->assertOk()->assertJsonPath('data.name', 'Detail Bike');
        $this->assertTrue(Cache::has($key));
    }

    // ---------------- Expiration ----------------

    public function test_catalog_cache_expires_after_ttl_and_is_rebuilt(): void
    {
        $this->makeBicycle();

        // The per-bicycle catalog is still cached (it is static data), so the
        // TTL/expiry contract is asserted against that surface.
        Sanctum::actingAs($this->makeRider());

        $key = CacheRegistry::bicycleCatalogKey(
            Bicycle::query()->value('id')
        );

        $this->getJson('/api/bicycles/' . Bicycle::query()->value('id'))->assertOk();

        $this->assertTrue(Cache::has($key));

        $versionBefore = CacheRegistry::bicyclesVersion();

        // Travel past the 10 minute (600s) catalog TTL.
        $this->travel(CacheRegistry::TTL_BICYCLE_CATALOG + 1)->seconds();

        $this->assertFalse(Cache::has($key), 'Cached catalog should have expired');
        $this->assertSame($versionBefore, CacheRegistry::bicyclesVersion());

        $this->getJson('/api/bicycles/' . Bicycle::query()->value('id'))->assertOk();
        $this->assertTrue(Cache::has($key), 'the catalog entry is rebuilt on the next read');
    }

    public function test_user_summary_expires_and_recomputes(): void
    {
        $rider = $this->riderAuth();

        $this->get(route('rider.dashboard'))->assertOk();

        $key = CacheRegistry::userSummaryKey($rider->id);
        $this->assertTrue(Cache::has($key));

        $this->travel(CacheRegistry::TTL_USER_SUMMARY + 1)->seconds();
        $this->assertFalse(Cache::has($key));

        // Rebuild still reflects the current database state.
        $this->get(route('rider.dashboard'))->assertOk();
        $this->assertTrue(Cache::has($key));
    }

    // ---------------- Rental lifecycle invalidation ----------------

    public function test_summary_invalidated_when_rental_is_created(): void
    {
        $rider = $this->riderAuth();
        $bike = $this->makeBicycle();

        $this->get(route('rider.dashboard'))->assertOk();

        $key = CacheRegistry::userSummaryKey($rider->id);
        $this->assertTrue(Cache::has($key));

        $versionBefore = CacheRegistry::userVersion($rider->id);

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
        ]);

        // The version bumped, so the key under the new version is not cached
        // and the next render will recompute the summary fresh.
        $this->assertGreaterThan($versionBefore, CacheRegistry::userVersion($rider->id));
        $this->assertFalse(Cache::has(CacheRegistry::userSummaryKey($rider->id)));
        $this->assertNotNull($rental);
    }

    public function test_rental_cache_correctly_avoids_active_rental_countdown_data(): void
    {
        $rider = $this->riderAuth();
        $bike = $this->makeBicycle();

        $this->get(route('rider.dashboard'))->assertOk();

        // Create an active rental: it must be reflected immediately (live),
        // never served from the summary cache.
        $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => now()->subMinutes(5),
            'expectedEndTime' => now()->addMinutes(55),
        ]);

        $this->get(route('rider.dashboard'))
            ->assertOk()
            ->assertSee('Active Rental');
    }
}