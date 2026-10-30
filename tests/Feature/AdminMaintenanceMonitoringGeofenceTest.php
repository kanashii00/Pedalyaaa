<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\DeviceStatus;
use App\Models\GpsLog;
use App\Models\MaintenanceRecord;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class AdminMaintenanceMonitoringGeofenceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broadcasting.default' => 'log']);
    }

    public function test_maintenance_index_active_and_history(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle();
        $this->makeMaintenanceRecord(['bicycleId' => $bike->id, 'status' => MaintenanceRecord::STATUS_SCHEDULED]);
        $this->makeMaintenanceRecord(['bicycleId' => $bike->id, 'status' => MaintenanceRecord::STATUS_COMPLETED]);

        $activePage = $this->actingAs($admin)->get(route('admin.maintenance.index'));
        $activePage->assertOk()
            ->assertSee('Active Maintenance')
            ->assertSee('Maintenance History')
            ->assertDontSee('Completed, cancelled records will appear here.');

        $this->actingAs($admin)
            ->get(route('admin.maintenance.index', ['status' => 'completed']))
            ->assertRedirect(route('admin.maintenance.history', ['status' => 'completed']));

        $this->actingAs($admin)
            ->get(route('admin.maintenance.history'))
            ->assertOk()
            ->assertSee('Maintenance History')
            ->assertSee('Active Maintenance');
        $this->actingAs($admin)->get(route('admin.maintenance.index', ['status' => 'in_progress', 'bicycleId' => $bike->id, 'date_from' => '2026-01-01', 'date_to' => '2026-12-31']))->assertOk();
    }

    public function test_maintenance_store_creates_record_and_marks_bike_maintenance(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);

        $this->from(route('admin.maintenance.index'))
            ->actingAs($admin)
            ->post(route('admin.maintenance.store'), [
                'bicycleId' => $bike->id,
                'description' => 'Brake check',
                'type' => 'routine',
                'severity' => 'medium',
                'estimatedCost' => 100,
                'technician' => 'Tech A',
            ])
            ->assertRedirect(route('admin.maintenance.index'));

        $fresh = $bike->fresh();
        $this->assertSame(Bicycle::STATUS_MAINTENANCE, $fresh->status);
        $this->assertDatabaseHas('maintenance_records', ['bicycleId' => $bike->id, 'type' => 'routine']);
    }

    public function test_maintenance_store_blocks_duplicate_active_record(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_MAINTENANCE]);
        $this->makeMaintenanceRecord(['bicycleId' => $bike->id, 'status' => MaintenanceRecord::STATUS_IN_PROGRESS]);

        $this->from(route('admin.maintenance.index'))
            ->actingAs($admin)
            ->post(route('admin.maintenance.store'), [
                'bicycleId' => $bike->id,
                'description' => 'Again',
                'type' => 'repair',
            ])
            ->assertSessionHasErrors('bicycleId');
    }

    public function test_maintenance_update_status_to_completed(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_MAINTENANCE]);
        $record = $this->makeMaintenanceRecord(['bicycleId' => $bike->id, 'status' => MaintenanceRecord::STATUS_SCHEDULED]);

        $this->from(route('admin.maintenance.index'))
            ->actingAs($admin)
            ->put(route('admin.maintenance.update', $record->id), [
                'status' => MaintenanceRecord::STATUS_COMPLETED,
                'notes' => 'Done',
            ])
            ->assertRedirect(route('admin.maintenance.index'));

        $this->assertSame(MaintenanceRecord::STATUS_COMPLETED, $record->fresh()->status);
        $this->assertNotNull($record->fresh()->completedDate);
    }

    public function test_schedule_status_updates_keep_the_success_toast_but_suppress_its_duplicate_banner(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_MAINTENANCE]);
        $record = $this->makeMaintenanceRecord([
            'bicycleId' => $bike->id,
            'status' => MaintenanceRecord::STATUS_SCHEDULED,
        ]);

        $this->from(route('admin.maintenance.index'))
            ->actingAs($admin)
            ->post(route('admin.maintenance.updateStatus', $record->id), ['status' => 'in_progress'])
            ->assertRedirect(route('admin.maintenance.index'))
            ->assertSessionHas('success', 'Maintenance status updated.')
            ->assertSessionHas('suppressSuccessBanner', true);

        $this->assertSame(MaintenanceRecord::STATUS_IN_PROGRESS, $record->fresh()->status);
    }

    public function test_maintenance_update_status_blocked_after_completion(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle();
        $record = $this->makeMaintenanceRecord(['bicycleId' => $bike->id, 'status' => MaintenanceRecord::STATUS_COMPLETED]);

        $this->from(route('admin.maintenance.index'))
            ->actingAs($admin)
            ->post(route('admin.maintenance.updateStatus', $record->id), ['status' => 'in_progress'])
            ->assertSessionHasErrors('status');
    }

    public function test_monitoring_index_and_sections(): void
    {
        $admin = $this->makeAdmin();
        $this->makeBicycle(['currentLat' => 14.6, 'currentLng' => 120.99]);
        $bike2 = $this->makeBicycle(['currentLat' => null, 'currentLng' => null]);

        $this->actingAs($admin)->get(route('admin.monitoring.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.monitoring.index', ['section' => 'locks']))->assertOk();
        $this->actingAs($admin)->get(route('admin.monitoring.index', ['section' => 'invalid']))->assertOk();
    }

    public function test_monitoring_gps_is_the_default_section(): void
    {
        $admin = $this->makeAdmin();
        $this->makeBicycle(['currentLat' => 14.6, 'currentLng' => 120.99]);

        $response = $this->actingAs($admin)->get(route('admin.monitoring.index'))->assertOk();

        $response->assertSee('Live GPS Tracking')
            ->assertSee('Live GPS Tracking Map')
            ->assertSee('Live Position Feed')
            // The retired GeoLibre 3D Map tab and its map-only fleet grid are gone.
            ->assertDontSee('GeoLibre 3D Map')
            ->assertDontSee('Live 3D map of the fleet')
            ->assertDontSee('Fleet Status');
    }

    public function test_monitoring_tab_bar_links_to_theft_detection_module(): void
    {
        $admin = $this->makeAdmin();
        $this->makeBicycle(['currentLat' => 14.6, 'currentLng' => 120.99]);

        $response = $this->actingAs($admin)->get(route('admin.monitoring.index'))->assertOk();

        // The tab sits alongside the existing sections and links to its own module.
        $response->assertSee(route('admin.theft-alerts.index'), false)
            ->assertSee('bi-shield-exclamation', false)
            ->assertSee('Theft Detection');

        // It must not disturb the other monitoring tabs.
        $response->assertSee('Live GPS Tracking')
            ->assertSee('Smart Lock Control')
            ->assertSee('IoT Device Monitoring');

        // The module it points at is still reachable.
        $this->actingAs($admin)->get(route('admin.theft-alerts.index'))->assertOk();
    }

    public function test_monitoring_removed_map_section_falls_back_to_gps(): void
    {
        $admin = $this->makeAdmin();
        $this->makeBicycle(['currentLat' => 14.6, 'currentLng' => 120.99]);

        $this->actingAs($admin)
            ->get(route('admin.monitoring.index', ['section' => 'map']))
            ->assertOk()
            ->assertSee('Live GPS Tracking Map')
            ->assertDontSee('GeoLibre 3D Map');
    }

    public function test_monitoring_sections_still_render_locks_and_devices(): void
    {
        $admin = $this->makeAdmin();
        $this->makeBicycle(['currentLat' => 14.6, 'currentLng' => 120.99]);

        $this->actingAs($admin)
            ->get(route('admin.monitoring.index', ['section' => 'locks']))
            ->assertOk()
            ->assertSee('Smart Lock Control')
            ->assertDontSee('id="monitoringMap"', false);

        $this->actingAs($admin)
            ->get(route('admin.monitoring.index', ['section' => 'devices']))
            ->assertOk()
            ->assertSee('IoT Device Monitoring')
            ->assertDontSee('id="monitoringMap"', false);
    }

    public function test_bicycle_status_index_with_filters(): void
    {
        $admin = $this->makeAdmin();
        $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE, 'lockStatus' => Bicycle::LOCK_LOCKED, 'lastHeartbeat' => now()]);
        $this->makeBicycle(['status' => Bicycle::STATUS_RENTED, 'lockStatus' => Bicycle::LOCK_UNLOCKED, 'lastHeartbeat' => now()->subHours(2)]);

        $this->actingAs($admin)->get(route('admin.bicycles.status'))->assertOk();
        $this->actingAs($admin)->get(route('admin.bicycles.status', ['status' => 'rented', 'lock' => 'unlocked', 'connectivity' => 'offline', 'search' => 'x']))->assertOk();
    }

    public function test_monitoring_bicycle_status_returns_json(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle();
        GpsLog::create(['bicycleId' => $bike->id, 'lat' => 14.6, 'lng' => 120.99, 'timestamp' => now()]);

        $this->actingAs($admin)
            ->getJson(route('admin.monitoring.status', $bike->id))
            ->assertOk()
            ->assertJsonPath('bicycle.id', $bike->id);
    }

    public function test_monitoring_live_returns_json(): void
    {
        $admin = $this->makeAdmin();
        $seedOnlyBike = $this->makeBicycle(['currentLat' => 14.6, 'currentLng' => 120.99]);
        $lastKnownBike = $this->makeBicycle(['currentLat' => 14.7, 'currentLng' => 121.0]);
        $lastKnownLog = GpsLog::create([
            'bicycleId' => $lastKnownBike->id,
            'lat' => 14.6123456,
            'lng' => 120.9876543,
            'timestamp' => now()->subMinutes(2),
        ]);
        $lastKnownLog->forceFill([
            'created_at' => now()->subMinutes(2),
            'timestamp' => now()->subMinutes(2),
        ])->save();
        $freshBike = $this->makeBicycle(['currentLat' => 14.8, 'currentLng' => 121.1]);
        GpsLog::create([
            'bicycleId' => $freshBike->id,
            'lat' => 0,
            'lng' => 0,
            'accuracy' => 4.2,
            'hdop' => 1.3,
            'satellites' => 7,
            'timestamp' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.monitoring.live'))
            ->assertOk()
            ->assertJsonCount(3, 'bicycles');
        $bicycles = collect($response->json('bicycles'))->keyBy('id');

        $this->assertNull($bicycles[$seedOnlyBike->id]['current_lat']);
        $this->assertSame('unavailable', $bicycles[$seedOnlyBike->id]['gps_status']);
        $this->assertSame('last_known', $bicycles[$lastKnownBike->id]['gps_status']);
        $this->assertSame(14.6123456, $bicycles[$lastKnownBike->id]['current_lat']);
        $this->assertSame('fix', $bicycles[$freshBike->id]['gps_status']);
        $this->assertEquals(0.0, $bicycles[$freshBike->id]['current_lat']);
        $this->assertEquals(0.0, $bicycles[$freshBike->id]['current_lng']);
        $this->assertSame(4.2, $bicycles[$freshBike->id]['gps_accuracy']);
        $this->assertSame(1.3, $bicycles[$freshBike->id]['gps_hdop']);

        $this->actingAs($admin)
            ->get(route('admin.monitoring.index', ['section' => 'gps']))
            ->assertOk()
            ->assertSee('Center on bicycle GPS location')
            ->assertSee('id="centerMapBtn"', false)
            ->assertDontSee('id="centerMapBtn" title="Center on bicycle GPS location" disabled', false)
            ->assertSee('id="centerMapStatus"', false)
            ->assertSee('NEO-6M GPS fix available')
            ->assertSee('GPS fix unavailable / last known')
            ->assertSee('GPS FIX NOT AVAILABLE');
    }

    public function test_geofence_index_renders(): void
    {
        $admin = $this->makeAdmin();
        $this->makeBicycle(['currentLat' => 14.6, 'currentLng' => 120.99]);
        $this->makeBicycle(['currentLat' => null, 'currentLng' => null]);

        $this->actingAs($admin)
            ->get(route('admin.geofence.index'))
            ->assertOk();
    }

    public function test_geofence_update_creates_active_geofence(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->putJson(route('admin.geofence.update'), [
                'centerLat' => 14.6,
                'centerLng' => 120.99,
                'radius' => 1000,
                'warningThreshold' => 200,
                'alertEnabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Geofence updated successfully.');

        $this->assertDatabaseHas('geofences', ['isActive' => true, 'radius' => 1000]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'geofence_updated']);
    }

    public function test_geofence_update_validation_fails(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->putJson(route('admin.geofence.update'), ['centerLat' => 200, 'centerLng' => 0, 'radius' => 10])
            ->assertStatus(422);
    }

    public function test_geofence_update_rectangle_persists_shape(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->putJson(route('admin.geofence.update'), [
                'centerLat' => 14.6,
                'centerLng' => 120.99,
                'shapeType' => 'rectangle',
                'width' => 2000,
                'height' => 1000,
                'rotation' => 45,
                'warningThreshold' => 120,
                'alertEnabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Geofence updated successfully.')
            ->assertJsonPath('geofence.shapeType', 'rectangle')
            ->assertJsonPath('geofence.width', 2000)
            ->assertJsonPath('geofence.height', 1000)
            ->assertJsonPath('geofence.rotation', 45);

        $this->assertDatabaseHas('geofences', [
            'isActive' => true,
            'shapeType' => 'rectangle',
            'width' => 2000,
            'height' => 1000,
            'rotation' => 45,
        ]);
    }

    public function test_geofence_update_polygon_persists_points(): void
    {
        $admin = $this->makeAdmin();

        $points = [
            ['lat' => 14.6, 'lng' => 120.98],
            ['lat' => 14.6, 'lng' => 121.00],
            ['lat' => 14.7, 'lng' => 121.00],
            ['lat' => 14.7, 'lng' => 120.98],
        ];

        $this->actingAs($admin)
            ->putJson(route('admin.geofence.update'), [
                'centerLat' => 14.65,
                'centerLng' => 120.99,
                'shapeType' => 'polygon',
                'points' => $points,
                'warningThreshold' => 80,
                'alertEnabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('geofence.shapeType', 'polygon');

        $latest = \App\Models\Geofence::where('isActive', true)->first();
        $this->assertSame('polygon', $latest->shapeType);
        $this->assertIsArray($latest->points);
        $this->assertCount(4, $latest->points);
    }

    public function test_geofence_update_oval_persists_width_height(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->putJson(route('admin.geofence.update'), [
                'centerLat' => 14.6,
                'centerLng' => 120.99,
                'shapeType' => 'oval_h',
                'width' => 1600,
                'height' => 900,
                'warningThreshold' => 100,
            ])
            ->assertOk()
            ->assertJsonPath('geofence.shapeType', 'oval_h')
            ->assertJsonPath('geofence.width', 1600)
            ->assertJsonPath('geofence.height', 900);
    }
}
