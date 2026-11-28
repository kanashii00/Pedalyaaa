<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\Accident;
use App\Models\DeviceCommand;
use App\Models\DeviceStatus;
use App\Models\GpsLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * IoT Device Monitoring shows only bicycles carrying a genuinely registered
 * physical device (fitted hardware + stored device credential). In production
 * that is exactly PDY-001. Everything else renders an explicit "No IoT
 * device registered" placeholder - never fabricated telemetry - and a
 * registered device that never checked in reads "Never connected", which is
 * distinct from a stale (offline) one. RFID is not part of the hardware and
 * appears nowhere.
 */
class DeviceMonitoringTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function devicesPage(User $admin, string $section = 'devices'): string
    {
        return $this->actingAs($admin)
            ->get(route('admin.monitoring.index', ['section' => $section]))
            ->assertOk()
            ->getContent();
    }

    private function register(Bicycle $bike): Bicycle
    {
        if (! $bike->isInService()) {
            $bike->provisionForService();
        }
        $bike->issueDeviceKey();

        return $bike->refresh();
    }

    private function heartbeat(Bicycle $bike, array $overrides = []): DeviceStatus
    {
        return DeviceStatus::create(array_merge([
            'bicycleId' => $bike->id,
            'deviceVersion' => 'v2.4.1',
            'uptime' => 3723,
            'lockStatus' => Bicycle::LOCK_LOCKED,
            'type' => 'gps',
            'eventTimestamp' => now()->subMinute(),
        ], $overrides));
    }

    public function test_only_registered_bicycle_renders_a_live_card(): void
    {
        $admin = $this->makeAdmin();
        $deployed = $this->register($this->makeBicycle([
            'name' => 'Beach Cruiser 001',
            'serialNumber' => 'PDY-001',
            'status' => Bicycle::STATUS_AVAILABLE,
        ]));
        $this->heartbeat($deployed);
        $parked = $this->makeBicycle([
            'serialNumber' => 'PDY-002',
            'status' => Bicycle::STATUS_OUT_OF_SERVICE,
            'iotEnabled' => false,
        ]);

        $html = $this->devicesPage($admin);

        // The deployed unit appears exactly once as a monitored device.
        $this->assertSame(1, substr_count($html, 'data-device-card="'.$deployed->id.'"'));
        $this->assertStringContainsString('PDY-001', $html);
        $this->assertStringContainsString('v2.4.1', $html);
        // Real telemetry only: firmware version and uptime from the report.
        $this->assertStringContainsString('01:02:03', $html);

        // The parked unit is excluded from monitoring with an explicit label.
        $this->assertStringNotContainsString('data-device-card="'.$parked->id.'"', $html);
        $this->assertStringContainsString('No IoT device registered', $html);
    }

    public function test_telemetry_without_registration_is_still_not_monitored(): void
    {
        $admin = $this->makeAdmin();
        $this->register($this->makeBicycle([
            'name' => 'Beach Cruiser 001',
            'serialNumber' => 'PDY-001',
        ]));
        // Stray rows for a bicycle with no device must not promote it.
        $impostor = $this->makeBicycle([
            'serialNumber' => 'PDY-002',
            'status' => Bicycle::STATUS_OUT_OF_SERVICE,
            'iotEnabled' => false,
        ]);
        $this->heartbeat($impostor);

        $html = $this->devicesPage($admin);

        $this->assertStringNotContainsString('data-device-card="'.$impostor->id.'"', $html);
        $this->assertStringContainsString('No IoT device registered', $html);
    }

    public function test_registered_silent_device_is_never_connected_not_offline(): void
    {
        $admin = $this->makeAdmin();
        $silent = $this->register($this->makeBicycle([
            'name' => 'Beach Cruiser 001',
            'serialNumber' => 'PDY-001',
        ]));

        $html = $this->devicesPage($admin);

        $this->assertStringContainsString('data-device-card="'.$silent->id.'"', $html);
        $this->assertStringContainsString('Never connected', $html);
        // Placeholders only: nothing fabricated for a device that never spoke.
    }

    public function test_missing_battery_and_lock_reports_are_rendered_as_unknown(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->register($this->makeBicycle([
            'name' => 'Beach Cruiser 001',
            'serialNumber' => 'PDY-001',
            'batteryLevel' => null,
            'lockStatus' => null,
        ]));

        $html = $this->devicesPage($admin);

        $this->assertStringContainsString('data-device-card="'.$bike->id.'"', $html);
        $this->assertStringContainsString('Unknown', $html);
        $this->assertStringContainsString('No lock commands recorded.', $html);

        $locksHtml = $this->devicesPage($admin, 'locks');
        $this->assertStringContainsString('Not reported', $locksHtml);
        $this->assertStringContainsString('Unknown', $locksHtml);
    }

    public function test_rfid_appears_nowhere_in_monitoring(): void
    {
        $admin = $this->makeAdmin();
        $this->register($this->makeBicycle([
            'name' => 'Beach Cruiser 001',
            'serialNumber' => 'PDY-001',
        ]));

        $this->assertFalse(Schema::hasColumn('device_status', 'rfid'));

        $html = $this->devicesPage($admin);
        $this->assertStringNotContainsStringIgnoringCase('rfid', $html);

        $live = $this->actingAs($admin)->getJson(route('admin.monitoring.live'))->assertOk();
        $this->assertStringNotContainsStringIgnoringCase('rfid', $live->getContent());
    }

    public function test_live_feed_reports_command_result_and_registration(): void
    {
        $admin = $this->makeAdmin();
        $deployed = $this->register($this->makeBicycle([
            'name' => 'Beach Cruiser 001',
            'serialNumber' => 'PDY-001',
        ]));
        $parked = $this->makeBicycle([
            'serialNumber' => 'PDY-002',
            'status' => Bicycle::STATUS_OUT_OF_SERVICE,
            'iotEnabled' => false,
        ]);
        DeviceCommand::create([
            'bicycleId' => $deployed->id,
            'command' => 'lock',
            'status' => 'executed',
            'response' => 'executed',
        ]);

        $data = $this->actingAs($admin)->getJson(route('admin.monitoring.live'))
            ->assertOk()
            ->json('bicycles');

        $deployedRow = collect($data)->firstWhere('id', $deployed->id);
        $parkedRow = collect($data)->firstWhere('id', $parked->id);

        $this->assertTrue($deployedRow['has_registered_device']);
        $this->assertSame('lock', $deployedRow['last_command']['command']);
        $this->assertSame('executed', $deployedRow['last_command']['status']);
        $this->assertSame('executed', $deployedRow['last_command']['response']);
        $this->assertFalse($parkedRow['has_registered_device']);
    }

    public function test_live_feed_exposes_separate_speed_freshness_distance_and_recent_incidents(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->register($this->makeBicycle([
            'name' => 'Hall Test Bicycle',
            'serialNumber' => 'PDY-HALL-LIVE',
            'wheelCircumferenceM' => 2.086,
            'lastHeartbeat' => now(),
        ]));
        DeviceStatus::create([
            'bicycleId' => $bike->id,
            'type' => 'gps',
            'eventTimestamp' => now(),
            'speedKmh' => 12.3,
            'wheelCircumferenceM' => 2.086,
            'hallPulseCount' => 48,
            'speedSensorStatus' => 'active',
            'lastHallPulseAt' => now()->subSeconds(2),
            'pulseIntervalMs' => 610,
            'estimatedDistanceKm' => 0.1001,
            'movementStatus' => 'moving',
        ]);
        GpsLog::create([
            'bicycleId' => $bike->id,
            'lat' => 14.6,
            'lng' => 120.99,
            'speed' => 6.5,
            'timestamp' => now(),
        ]);
        Accident::create([
            'bicycleId' => $bike->id,
            'type' => 'accident',
            'severity' => 'moderate',
            'description' => 'Potential accident',
            'impactForce' => 2.4,
            'speedKmh' => 12.3,
            'sensorContext' => [
                'classification' => 'POTENTIAL_ACCIDENT',
                'detectionTrigger' => 'impact_threshold',
                'detectionSource' => 'mpu6050',
                'detectionReason' => 'impact_and_rotation',
                'impactDetected' => true,
                'tiltDegrees' => 61.5,
                'rotationDps' => 228.4,
                'hall' => [
                    'speedKmh' => 12.3,
                    'speedBeforeKmh' => 10.1,
                    'status' => 'active',
                ],
                'gps' => [
                    'fixStatus' => 'fresh',
                    'lastKnownLat' => 14.6,
                    'lastKnownLng' => 120.99,
                ],
                'mpu' => ['available' => true, 'sampleAgeMs' => 0],
            ],
        ]);

        $response = $this->actingAs($admin)->getJson(route('admin.monitoring.live'))->assertOk();
        $row = collect($response->json('bicycles'))->firstWhere('id', $bike->id);
        $this->assertSame(6.5, $row['gps_speed']);
        $this->assertEquals(12.3, $row['hall_speed_kmh']);
        $this->assertSame('moving', $row['hall_movement_status']);
        $this->assertTrue($row['hall_telemetry_fresh']);
        $this->assertEquals(0.1001, $row['hall_estimated_distance_km']);
        $this->assertSame('online', $row['device_connection']);
        $this->assertSame('impact_and_rotation', $response->json('recent_incidents.0.detection_reason'));
        $this->assertSame('POTENTIAL_ACCIDENT', $response->json('recent_incidents.0.classification'));
        $this->assertSame('impact_threshold', $response->json('recent_incidents.0.detection_trigger'));
        $this->assertSame(61.5, $response->json('recent_incidents.0.tilt_degrees'));
        $this->assertSame(228.4, $response->json('recent_incidents.0.rotation_dps'));
        $this->assertSame('available', $response->json('recent_incidents.0.mpu_status'));
        $this->assertSame(10.1, $response->json('recent_incidents.0.hall_speed_before_kmh'));
        $this->assertSame('fresh', $response->json('recent_incidents.0.gps_fix_status'));

        $html = $this->actingAs($admin)
            ->get(route('admin.monitoring.index', ['section' => 'gps']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Recent sensor incidents', $html);
        $this->assertStringContainsString('GPS 6.5 km/h', $html);
        $this->assertStringContainsString('Hall 12.3 km/h', $html);
    }

    public function test_live_feed_marks_old_hall_telemetry_stale_and_device_offline(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->register($this->makeBicycle([
            'serialNumber' => 'PDY-HALL-STALE',
            'wheelCircumferenceM' => 2.086,
            'lastHeartbeat' => now()->subMinutes(6),
        ]));
        DeviceStatus::create([
            'bicycleId' => $bike->id,
            'type' => 'gps',
            'eventTimestamp' => now()->subMinute(),
            'speedKmh' => 16.0,
            'wheelCircumferenceM' => 2.086,
            'hallPulseCount' => 90,
            'speedSensorStatus' => 'active',
            'lastHallPulseAt' => now()->subMinute(),
            'pulseIntervalMs' => 469,
            'estimatedDistanceKm' => 0.1877,
            'movementStatus' => 'moving',
        ]);

        $response = $this->actingAs($admin)->getJson(route('admin.monitoring.live'))->assertOk();
        $row = collect($response->json('bicycles'))->firstWhere('id', $bike->id);
        $this->assertNull($row['hall_speed_kmh']);
        $this->assertSame('stale', $row['hall_status']);
        $this->assertSame('stale', $row['hall_movement_status']);
        $this->assertSame('offline', $row['device_connection']);

        $html = $this->actingAs($admin)
            ->get(route('admin.monitoring.index', ['section' => 'gps']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Offline · Stale', $html);
    }

    public function test_monitoring_prefers_hall_speed_from_gps_telemetry_and_reports_stopped_as_zero(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle([
            'wheelCircumferenceM' => 2.1,
            'serialNumber' => 'PDY-HALL-001',
        ]);
        DeviceStatus::create([
            'bicycleId' => $bike->id,
            'type' => 'heartbeat',
            'eventTimestamp' => now(),
            'speedKmh' => 0,
            'wheelCircumferenceM' => 2.1,
            'hallPulseCount' => 48,
            'speedSensorStatus' => 'stopped',
        ]);
        GpsLog::create([
            'bicycleId' => $bike->id,
            'lat' => 14.6,
            'lng' => 120.99,
            'speed' => 18.5,
            'timestamp' => now(),
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.monitoring.index', ['section' => 'gps']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('0.0 km/h', $html);
        $this->assertStringContainsString('Hall Effect sensor', $html);
        $this->assertStringContainsString('Circumference 2.100 m', $html);
        $this->assertStringContainsString('Hall pulses 48', $html);
        $this->assertStringContainsString('Number(bike.speed_kmh).toFixed(1)', $html);

        $rows = $this->actingAs($admin)->getJson(route('admin.monitoring.live'))
            ->assertOk()
            ->json('bicycles');
        $row = collect($rows)->firstWhere('id', $bike->id);
        $this->assertSame('hall', $row['speed_source']);
        $this->assertEquals(0.0, $row['speed_kmh']);
        $this->assertSame('stopped', $row['speed_sensor_status']);
        $this->assertEquals(2.1, $row['wheel_circumference_m']);
        $this->assertSame(48, $row['hall_pulse_count']);
    }

    public function test_monitoring_distinguishes_unconfigured_hall_sensor_from_missing_telemetry(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['serialNumber' => 'PDY-HALL-UNCONFIGURED']);
        DeviceStatus::create([
            'bicycleId' => $bike->id,
            'type' => 'gps',
            'eventTimestamp' => now(),
            'hallPulseCount' => 1,
            'speedSensorStatus' => 'unconfigured',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.monitoring.index', ['section' => 'gps']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Hall Effect sensor · unconfigured', $html);
        $this->assertStringContainsString('Circumference not configured', $html);
        $this->assertStringContainsString('Hall pulses 1', $html);

        $rows = $this->actingAs($admin)->getJson(route('admin.monitoring.live'))
            ->assertOk()
            ->json('bicycles');
        $row = collect($rows)->firstWhere('id', $bike->id);
        $this->assertSame('unconfigured', $row['speed_sensor_status']);
        $this->assertSame('unavailable', $row['speed_source']);
        $this->assertNull($row['speed_kmh']);
        $this->assertSame(1, $row['hall_pulse_count']);
    }

    public function test_monitoring_labels_legacy_gps_speed_as_fallback(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['serialNumber' => 'PDY-GPS-001']);
        GpsLog::create([
            'bicycleId' => $bike->id,
            'lat' => 14.6,
            'lng' => 120.99,
            'speed' => 12.34,
            'timestamp' => now(),
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.monitoring.index', ['section' => 'gps']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('12.3 km/h', $html);
        $this->assertStringContainsString('GPS fallback', $html);

        $rows = $this->actingAs($admin)->getJson(route('admin.monitoring.live'))
            ->assertOk()
            ->json('bicycles');
        $row = collect($rows)->firstWhere('id', $bike->id);
        $this->assertSame('gps_fallback', $row['speed_source']);
        $this->assertSame(12.34, $row['speed_kmh']);
    }

    public function test_reconcile_warns_when_deployed_unit_has_no_credential(): void
    {
        $this->makeBicycle([
            'name' => 'Beach Cruiser 001',
            'serialNumber' => 'PDY-001',
            'status' => Bicycle::STATUS_AVAILABLE,
        ])->provisionForService();

        $this->artisan('pedalya:fleet-reconcile', ['--dry-run' => true])
            ->expectsOutputToContain('no device credential stored')
            ->assertExitCode(0);
    }
}
