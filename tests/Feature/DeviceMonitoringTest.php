<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\DeviceCommand;
use App\Models\DeviceStatus;
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
            'type' => 'heartbeat',
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
