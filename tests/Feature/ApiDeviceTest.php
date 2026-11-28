<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\DeviceCommand;
use App\Models\GpsLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class ApiDeviceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.device_api_key' => 'test-device-key']);
        config(['broadcasting.default' => 'log']);
    }

    private function deviceHeaders(): array
    {
        return ['X-API-Key' => 'test-device-key'];
    }

    public function test_device_auth_requires_api_key(): void
    {
        $this->postJson('/api/iot/heartbeat', [])->assertStatus(401);
    }

    public function test_device_auth_rejects_invalid_key(): void
    {
        $this->postJson('/api/iot/heartbeat', [], ['X-API-Key' => 'wrong'])->assertStatus(401);
    }

    public function test_device_auth_fails_closed_when_no_key_is_configured(): void
    {
        config(['services.device_api_key' => null]);

        $this->postJson('/api/iot/heartbeat', [], $this->deviceHeaders())->assertStatus(401);
    }

    public function test_public_database_diagnostic_endpoint_is_not_registered(): void
    {
        $this->getJson('/api/test')->assertNotFound();
    }

    public function test_heartbeat_processes(): void
    {
        $bike = $this->makeBicycle();

        $this->postJson('/api/iot/heartbeat', [
            'bicycle_id' => $bike->id,
            'device_id' => 'ESP-001',
            'battery' => 80,
            'locked' => true,
        ], $this->deviceHeaders())->assertOk()->assertJsonPath('message', 'Heartbeat processed');
    }

    public function test_heartbeat_validates_and_stores_hall_speed_telemetry(): void
    {
        $bike = $this->makeBicycle(['wheelCircumferenceM' => 2.1]);

        $this->postJson('/api/iot/heartbeat', [
            'bicycle_id' => $bike->id,
            'device_id' => 'ESP-001',
            'speedKmh' => 7.56,
            'wheelCircumferenceM' => 2.1,
            'hallPulseCount' => 12,
            'speedSensorStatus' => 'active',
        ], $this->deviceHeaders())->assertOk();

        $this->assertDatabaseHas('device_status', [
            'bicycleId' => $bike->id,
            'speedKmh' => 7.56,
            'wheelCircumferenceM' => 2.1,
            'hallPulseCount' => 12,
            'speedSensorStatus' => 'active',
        ]);
    }

    public function test_heartbeat_accepts_higher_realistic_hall_speed(): void
    {
        $bike = $this->makeBicycle(['wheelCircumferenceM' => 2.086]);

        $this->postJson('/api/iot/heartbeat', [
            'bicycle_id' => $bike->id,
            'device_id' => 'ESP-001',
            'speedKmh' => 90,
            'wheelCircumferenceM' => 2.086,
            'hallPulseCount' => 12,
            'speedSensorStatus' => 'active',
        ], $this->deviceHeaders())->assertOk();

        $this->assertDatabaseHas('device_status', [
            'bicycleId' => $bike->id,
            'speedKmh' => 90,
            'speedSensorStatus' => 'active',
        ]);
    }

    public function test_heartbeat_rejects_speed_above_supported_range(): void
    {
        $bike = $this->makeBicycle();

        $this->postJson('/api/iot/heartbeat', [
            'bicycle_id' => $bike->id,
            'device_id' => 'ESP-001',
            'speedKmh' => 121,
            'wheelCircumferenceM' => 2.1,
            'hallPulseCount' => 12,
            'speedSensorStatus' => 'active',
        ], $this->deviceHeaders())->assertUnprocessable()
            ->assertJsonValidationErrors('speedKmh');
    }

    public function test_heartbeat_with_coordinates_updates_live_position(): void
    {
        $bike = $this->makeBicycle();

        $this->postJson('/api/iot/heartbeat', [
            'bicycle_id' => $bike->id,
            'device_id' => 'ESP-001',
            'lat' => 14.6123456,
            'lng' => 120.9876543,
            'battery' => 81,
            'locked' => false,
        ], $this->deviceHeaders())->assertOk();

        $bike->refresh();
        $this->assertEquals(14.6123456, (float) $bike->currentLat);
        $this->assertEquals(120.9876543, (float) $bike->currentLng);
        $this->assertNotNull($bike->lastGpsUpdate);
    }

    public function test_admin_monitoring_page_handles_heartbeat_only_position(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle();
        $bike->update([
            'currentLat' => 14.6123456,
            'currentLng' => 120.9876543,
            'lastGpsUpdate' => now(),
        ]);

        $this->actingAs($admin, 'web')
            ->get('/admin/monitoring')
            ->assertOk();
    }

    public function test_admin_monitoring_ignores_dummy_qa_bicycle(): void
    {
        $admin = $this->makeAdmin();
        Bicycle::create([
            'name' => 'QA System Bicycle',
            'serialNumber' => 'QA-16',
            'model' => 'Test',
            'status' => 'out_of_service',
            'lockStatus' => 'locked',
            'batteryLevel' => 100,
            'hourlyRate' => 15.00,
            'currentLat' => 14.5995,
            'currentLng' => 120.9842,
        ]);

        $this->actingAs($admin, 'web')
            ->get('/admin/monitoring')
            ->assertOk()
            ->assertDontSee('QA System Bicycle');
    }

    public function test_heartbeat_requires_valid_bicycle(): void
    {
        $this->postJson('/api/iot/heartbeat', [
            'bicycle_id' => 99999,
            'device_id' => 'ESP-001',
        ], $this->deviceHeaders())->assertStatus(422);
    }

    public function test_accident_report_processes(): void
    {
        $bike = $this->makeBicycle(['wheelCircumferenceM' => 2.086]);

        $this->postJson('/api/iot/accident-report', [
            'bicycle_id' => $bike->id,
            'device_id' => 'ESP-001',
            'lat' => 14.6,
            'lng' => 120.99,
            'impact_force' => 15,
            'speedKmh' => 24.5,
            'wheelCircumferenceM' => 2.086,
            'hallPulseCount' => 25,
            'speedSensorStatus' => 'active',
        ], $this->deviceHeaders())->assertOk();

        $this->assertDatabaseHas('accidents', [
            'bicycleId' => $bike->id,
            'speedKmh' => 24.5,
        ]);
    }

    public function test_geofence_alert_processes(): void
    {
        $bike = $this->makeBicycle();

        $this->postJson('/api/iot/geofence-alert', [
            'bicycle_id' => $bike->id,
            'device_id' => 'ESP-001',
            'lat' => 14.6,
            'lng' => 120.99,
            'distance' => 50,
        ], $this->deviceHeaders())->assertOk();
    }

    public function test_device_bicycle_status_returns_commands(): void
    {
        $bike = $this->makeBicycle();
        DeviceCommand::create(['bicycleId' => $bike->id, 'command' => 'lock', 'status' => 'pending']);

        $this->postJson("/api/iot/bicycle/{$bike->id}/status", [], $this->deviceHeaders())
            ->assertOk()
            ->assertJsonPath('bicycle_id', $bike->id);
    }

    public function test_device_bicycle_status_not_found(): void
    {
        $this->postJson('/api/iot/bicycle/99999/status', [], $this->deviceHeaders())->assertStatus(404);
    }

    public function test_rental_endpoint_404s_for_unknown_bicycle(): void
    {
        // Route model binding resolves {bicycle} by id ahead of the key check,
        // so an unknown bicycle id is a routing 404 - not an auth failure.
        // This is the failure class the ESP32 poll() reports as "404 not found".
        $this->getJson('/api/iot/bicycle/99999/rental', $this->deviceHeaders())->assertStatus(404);
    }

    public function test_acknowledge_command(): void
    {
        $bike = $this->makeBicycle();
        $cmd = DeviceCommand::create(['bicycleId' => $bike->id, 'command' => 'lock', 'status' => 'pending']);

        $this->postJson("/api/iot/bicycle/{$bike->id}/command-ack", [
            'command_id' => $cmd->id,
            'status' => 'executed',
        ], $this->deviceHeaders())
            ->assertOk()
            ->assertJsonPath('message', 'Command acknowledged');

        $this->assertSame('executed', $cmd->fresh()->status);
    }

    public function test_acknowledge_command_wrong_bicycle(): void
    {
        $bike = $this->makeBicycle();
        $other = $this->makeBicycle();
        $cmd = DeviceCommand::create(['bicycleId' => $other->id, 'command' => 'lock', 'status' => 'pending']);

        $this->postJson("/api/iot/bicycle/{$bike->id}/command-ack", [
            'command_id' => $cmd->id,
        ], $this->deviceHeaders())->assertStatus(404);
    }

    public function test_gps_location_records_log(): void
    {
        $bike = $this->makeBicycle();

        $this->postJson('/api/gps/location', [
            'bicycle_id' => $bike->id,
            'lat' => 14.6,
            'lng' => 120.99,
            'speed' => 12,
            'battery' => 75,
        ], $this->deviceHeaders())
            ->assertOk()
            ->assertJsonPath('message', 'Location recorded');

        $this->assertDatabaseHas('gps_logs', ['bicycleId' => $bike->id, 'lat' => 14.6]);
    }

    public function test_bicycle_gps_path_records_the_authenticated_bicycles_fix(): void
    {
        $bike = $this->makeBicycle();
        $deviceKey = $bike->issueDeviceKey();

        $this->postJson("/api/iot/bicycle/{$bike->id}/gps", [
            'lat' => 14.6123456,
            'lng' => 120.9876543,
            'speed' => 8.5,
            'heading' => 90,
            'accuracy' => 4.2,
            'hdop' => 1.3,
            'satellites' => 7,
        ], ['X-API-Key' => $deviceKey])
            ->assertOk()
            ->assertJsonPath('message', 'Location recorded');

        $this->assertDatabaseHas('gps_logs', [
            'bicycleId' => $bike->id,
            'lat' => 14.6123456,
            'lng' => 120.9876543,
            'accuracy' => 4.2,
            'hdop' => 1.3,
            'satellites' => 7,
        ]);
        $this->assertEquals(14.6123456, (float) $bike->fresh()->currentLat);
        $this->assertEquals(120.9876543, (float) $bike->fresh()->currentLng);
        $this->assertNotNull($bike->fresh()->lastGpsUpdate);
    }

    public function test_bicycle_gps_path_records_hall_speed_without_replacing_gps_speed(): void
    {
        $bike = $this->makeBicycle(['wheelCircumferenceM' => 2.1]);
        $deviceKey = $bike->issueDeviceKey();

        $this->postJson("/api/iot/bicycle/{$bike->id}/gps", [
            'lat' => 14.6123456,
            'lng' => 120.9876543,
            'speed' => 6.5,
            'speedKmh' => 12.34,
            'wheelCircumferenceM' => 2.1,
            'hallPulseCount' => 48,
            'speedSensorStatus' => 'active',
        ], ['X-API-Key' => $deviceKey])->assertOk();

        $this->assertDatabaseHas('gps_logs', [
            'bicycleId' => $bike->id,
            'speed' => 6.5,
        ]);
        $this->assertDatabaseHas('device_status', [
            'bicycleId' => $bike->id,
            'type' => 'gps',
            'speedKmh' => 12.34,
            'wheelCircumferenceM' => 2.1,
            'hallPulseCount' => 48,
            'speedSensorStatus' => 'active',
        ]);
    }

    public function test_bicycle_gps_path_accepts_hall_telemetry_without_a_gps_fix(): void
    {
        $bike = $this->makeBicycle(['wheelCircumferenceM' => 2.086]);
        $deviceKey = $bike->issueDeviceKey();

        $this->postJson("/api/iot/bicycle/{$bike->id}/gps", [
            'speedKmh' => 90,
            'wheelCircumferenceM' => 2.086,
            'hallPulseCount' => 48,
            'speedSensorStatus' => 'active',
        ], ['X-API-Key' => $deviceKey])
            ->assertOk()
            ->assertJsonPath('message', 'Hall telemetry recorded');

        $this->assertDatabaseHas('device_status', [
            'bicycleId' => $bike->id,
            'type' => 'gps',
            'speedKmh' => 90,
            'speedSensorStatus' => 'active',
        ]);
        $this->assertDatabaseCount('gps_logs', 0);
        $this->assertNull($bike->fresh()->lastGpsUpdate);
    }

    public function test_gps_location_triggers_geofence_breach(): void
    {
        config(['services.geofence' => ['center_lat' => 15.0, 'center_lng' => 121.0, 'default_radius' => 100, 'warning_threshold' => 50]]);

        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle();

        $this->postJson('/api/gps/location', [
            'bicycle_id' => $bike->id,
            'lat' => 14.0,
            'lng' => 120.0,
        ], $this->deviceHeaders())->assertOk();

        $this->assertDatabaseHas('geofence_breaches', ['bicycleId' => $bike->id]);
        $this->assertDatabaseHas('accidents', ['bicycleId' => $bike->id, 'type' => 'theft']);
    }

    public function test_gps_track_and_current_require_auth(): void
    {
        $rider = $this->makeRider();
        Sanctum::actingAs($rider);

        $bike = $this->makeBicycle();
        GpsLog::create(['bicycleId' => $bike->id, 'lat' => 14.6, 'lng' => 120.99, 'timestamp' => now()]);

        $this->getJson("/api/gps/bicycle/{$bike->id}/track")->assertOk()->assertJsonStructure(['bicycle_id', 'gps_logs', 'count']);
        $this->getJson("/api/gps/bicycle/{$bike->id}/current")->assertOk()->assertJsonPath('bicycle_id', $bike->id);
        $this->getJson('/api/gps/bicycle/99999/track')->assertStatus(404);
        $this->getJson('/api/gps/bicycle/99999/current')->assertStatus(404);
        $this->getJson('/api/gps/geofence')->assertOk();
    }

    public function test_gps_update_geofence_requires_admin(): void
    {
        $rider = $this->makeRider();
        Sanctum::actingAs($rider, ['*']);
        $this->postJson('/api/gps/geofence', [
            'center_lat' => 15.0, 'center_lng' => 121.0, 'radius' => 200,
        ])->assertForbidden();
    }

    public function test_gps_update_geofence_as_admin(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/gps/geofence', [
            'center_lat' => 15.0, 'center_lng' => 121.0, 'radius' => 200, 'alert_enabled' => true,
        ])->assertOk()->assertJsonPath('message', 'Geofence updated successfully');

        $this->assertDatabaseHas('geofences', ['isActive' => true]);
    }

    public function test_full_status_and_command_endpoints(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin, ['*']);

        $bike = $this->makeBicycle();

        $this->getJson("/api/iot/bicycle/{$bike->id}/status")->assertOk()->assertJsonPath('bicycle_id', $bike->id);
        $this->getJson('/api/iot/bicycle/99999/status')->assertStatus(404);

        $this->postJson("/api/iot/bicycle/{$bike->id}/command", ['command' => 'lock'])
            ->assertStatus(201)
            ->assertJsonPath('message', 'Command queued');

        $this->assertDatabaseHas('device_commands', ['bicycleId' => $bike->id, 'command' => 'lock']);
    }
}
