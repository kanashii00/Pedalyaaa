<?php

namespace Tests\Feature;

use App\Models\Accident;
use App\Models\AuditLog;
use App\Models\Bicycle;
use App\Models\Notification;
use App\Models\Rental;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class AdminAccidentManagementTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function adminAuth(): User
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        return $admin;
    }

    public function test_bicycle_name_is_clickable_and_identifies_exact_incident_id(): void
    {
        $this->adminAuth();
        $bike = $this->makeBicycle(['name' => 'Roadster 100', 'serialNumber' => 'RD-100']);

        // One bicycle can have multiple accident incidents
        $acc1 = Accident::create([
            'bicycleId' => $bike->id,
            'type' => 'accident',
            'severity' => 'major',
            'description' => 'First incident: rear collision',
            'impactForce' => 3.50,
            'gpsLocation' => ['lat' => 14.5995, 'lng' => 120.9842],
            'acknowledged' => false,
        ]);

        $acc2 = Accident::create([
            'bicycleId' => $bike->id,
            'type' => 'impact_detected',
            'severity' => 'moderate',
            'description' => 'Second incident: sudden jolt',
            'impactForce' => 2.10,
            'gpsLocation' => ['lat' => 14.6010, 'lng' => 120.9855],
            'acknowledged' => false,
        ]);

        $response = $this->get(route('admin.accidents.index'));
        $response->assertOk();
        $html = $response->getContent();

        // Clickable bicycle links with unique incident IDs
        $this->assertStringContainsString('data-view-accident="' . $acc1->id . '"', $html);
        $this->assertStringContainsString('data-view-accident="' . $acc2->id . '"', $html);
        $this->assertStringContainsString('class="accident-bike-link"', $html);

        // Separate View Details eye buttons removed from table rows
        $this->assertStringNotContainsString('title="View Details"', $html);
        $this->assertStringNotContainsString('aria-label="View Details"', $html);

        // Acknowledge form/buttons removed from table rows
        $this->assertStringNotContainsString('data-confirm="Acknowledge this accident report?"', $html);
    }

    public function test_accident_details_modal_renders_all_required_incident_data(): void
    {
        $this->adminAuth();
        $response = $this->get(route('admin.accidents.index'));
        $response->assertOk();
        $html = $response->getContent();

        // Modal markup assertions
        $this->assertStringContainsString('id="accidentDetailModal"', $html);
        $this->assertStringContainsString('id="modalIncidentIdBadge"', $html);
        $this->assertStringContainsString('id="modalBikeName"', $html);
        $this->assertStringContainsString('id="modalBikeSerial"', $html);
        $this->assertStringContainsString('id="modalRiderName"', $html);
        $this->assertStringContainsString('id="modalRentalId"', $html);
        $this->assertStringContainsString('id="modalImpactForce"', $html);
        $this->assertStringContainsString('id="modalHallSpeed"', $html);
        $this->assertStringContainsString('id="modalGpsCoords"', $html);
        $this->assertStringContainsString('id="modalDescription"', $html);
        $this->assertStringContainsString('id="modalDetectedTime"', $html);
        $this->assertStringContainsString('id="modalAckBanner"', $html);
        $this->assertStringContainsString('id="modalAckSubline"', $html);
        $this->assertStringContainsString('id="modalBtnMap"', $html);
        $this->assertStringContainsString('id="modalBtnAcknowledge"', $html);
        $this->assertStringContainsString('openAccidentDetailsModal', $html);
        $this->assertStringContainsString('cursor: pointer', $html);
    }

    public function test_accident_details_json_endpoint_returns_correct_rider_and_active_rental(): void
    {
        $this->adminAuth();

        $rider = User::create([
            'name' => 'Maria Santos',
            'email' => 'maria.santos@example.com',
            'phone' => '+639171234567',
            'password' => bcrypt('password123'),
            'role' => User::ROLE_RIDER,
            'verified' => true,
        ]);

        $bike = $this->makeBicycle(['name' => 'Cruiser Deluxe 05', 'serialNumber' => 'CD-05']);

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'startTime' => now()->subHours(2),
            'status' => Rental::STATUS_ACTIVE,
            'cost' => 150.00,
        ]);

        $accident = Accident::create([
            'bicycleId' => $bike->id,
            'rentalId' => $rental->id,
            'riderId' => $rider->id,
            'type' => 'accident',
            'severity' => 'critical',
            'description' => 'Severe impact detected at intersection',
            'impactForce' => 4.25,
            'speedKmh' => 24.5,
            'gpsLocation' => ['lat' => 14.5821, 'lng' => 120.9788],
            'sensorContext' => [
                'detectionSource' => 'mpu6050',
                'detectionReason' => 'impact_and_rotation',
                'gpsSpeedKmh' => 20.0,
                'gpsFixAgeMs' => 1200,
                'rotationDps' => 80.0,
                'tiltDegrees' => 60.0,
                'hall' => [
                    'status' => 'active',
                    'movementStatus' => 'moving',
                    'wheelCircumferenceM' => 2.086,
                    'pulseCount' => 20,
                    'lastPulseAgeMs' => 100,
                ],
                'preImpactSamples' => [['ageMs' => 100]],
            ],
            'acknowledged' => false,
        ]);

        $res = $this->getJson(route('admin.accidents.details', $accident->id));
        $res->assertOk();
        $res->assertJson([
            'success' => true,
            'incident' => [
                'id' => $accident->id,
                'bicycleName' => 'Cruiser Deluxe 05',
                'serialNumber' => 'CD-05',
                'riderName' => 'Maria Santos',
                'rentalId' => '#' . $rental->id,
                'type' => 'accident',
                'severity' => 'critical',
                'impactForce' => '4.25 G',
                'speedKmh' => 24.5,
                'acknowledged' => false,
            ],
        ]);

        $this->assertStringContainsString('14.5821', $res->json('incident.locationText'));
        $this->assertStringContainsString(route('admin.monitoring.index'), $res->json('incident.mapUrl'));
        $this->assertSame('impact_and_rotation', $res->json('incident.detectionReason'));
        $this->assertSame('active', $res->json('incident.sensorStatus'));
        $this->assertEquals(20.0, $res->json('incident.gpsSpeedKmh'));
        $this->assertSame(1, count($res->json('incident.preImpactSamples')));
    }

    public function test_accident_details_handles_unrented_bicycle_cleanly(): void
    {
        $this->adminAuth();
        $bike = $this->makeBicycle(['name' => 'Stationary Unit 01', 'serialNumber' => 'ST-01']);

        $accident = Accident::create([
            'bicycleId' => $bike->id,
            'rentalId' => null,
            'riderId' => null,
            'type' => 'impact_detected',
            'severity' => 'minor',
            'description' => 'Minor vibration while parked in docking zone',
            'impactForce' => 1.20,
            'acknowledged' => false,
        ]);

        $res = $this->getJson(route('admin.accidents.details', $accident->id));
        $res->assertOk();
        $res->assertJson([
            'success' => true,
            'incident' => [
                'id' => $accident->id,
                'riderName' => 'No Active Rental',
                'rentalId' => null,
            ],
        ]);
    }

    public function test_accident_acknowledge_action_records_admin_timestamp_and_logs_audit(): void
    {
        $admin = $this->makeAdmin(['name' => 'Admin Alex']);
        $this->actingAs($admin);

        $bike = $this->makeBicycle();
        $accident = Accident::create([
            'bicycleId' => $bike->id,
            'type' => 'accident',
            'severity' => 'major',
            'description' => 'Collision during turn',
            'acknowledged' => false,
        ]);

        $res = $this->postJson(route('admin.accidents.acknowledge', $accident->id));
        $res->assertOk();
        $res->assertJson([
            'success' => true,
            'incidentId' => $accident->id,
            'acknowledged' => true,
            'acknowledgedBy' => 'Admin Alex',
        ]);

        $accident->refresh();
        $this->assertTrue($accident->acknowledged);
        $this->assertSame($admin->id, $accident->acknowledgedBy);
        $this->assertNotNull($accident->acknowledgedAt);
        $this->assertStringContainsString('Acknowledged by administrator: Admin Alex', $accident->actionTaken);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLogger::ACCIDENT_ACKNOWLEDGED,
            'userId' => $admin->id,
        ]);
    }

    public function test_accident_acknowledge_marks_unread_notifications_as_read(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $bike = $this->makeBicycle();
        $accident = Accident::create([
            'bicycleId' => $bike->id,
            'type' => 'accident',
            'severity' => 'critical',
            'description' => 'High impact collision',
            'acknowledged' => false,
        ]);

        $notif = Notification::create([
            'userId' => $admin->id,
            'title' => 'Accident Alert',
            'message' => "Accident detected on bicycle {$bike->id}. Immediate attention required.",
            'type' => 'accident',
            'bicycleId' => $bike->id,
            'incidentId' => $accident->id,
            'read' => false,
        ]);

        $this->postJson(route('admin.accidents.acknowledge', $accident->id))->assertOk();

        $notif->refresh();
        $this->assertTrue($notif->read);
        $this->assertNotNull($notif->readAt);
    }

    public function test_duplicate_acknowledgments_are_prevented(): void
    {
        $admin = $this->makeAdmin(['name' => 'Admin First']);
        $this->actingAs($admin);

        $bike = $this->makeBicycle();
        $accident = Accident::create([
            'bicycleId' => $bike->id,
            'type' => 'accident',
            'severity' => 'major',
            'description' => 'Collision report',
            'acknowledged' => false,
        ]);

        // First acknowledgment
        $first = $this->postJson(route('admin.accidents.acknowledge', $accident->id));
        $first->assertOk();
        $first->assertJson(['success' => true]);

        $originalAcknowledgedAt = $accident->fresh()->acknowledgedAt;

        // Second acknowledgment attempt
        $second = $this->postJson(route('admin.accidents.acknowledge', $accident->id));
        $second->assertOk();
        $second->assertJson([
            'success' => true,
            'alreadyAcknowledged' => true,
        ]);

        $this->assertSame($originalAcknowledgedAt->toIso8601String(), $accident->fresh()->acknowledgedAt->toIso8601String());
    }

    public function test_view_on_map_action_links_to_live_gps_tracking_map_with_coords(): void
    {
        $this->adminAuth();
        $bike = $this->makeBicycle();
        $accident = Accident::create([
            'bicycleId' => $bike->id,
            'type' => 'accident',
            'severity' => 'major',
            'description' => 'Crash location test',
            'gpsLocation' => ['lat' => 14.5901, 'lng' => 120.9812],
            'acknowledged' => false,
        ]);

        $res = $this->getJson(route('admin.accidents.details', $accident->id));
        $res->assertOk();

        $mapUrl = $res->json('incident.mapUrl');
        $this->assertStringContainsString('bike=' . $bike->id, $mapUrl);
        $this->assertStringContainsString('lat=14.5901', $mapUrl);
        $this->assertStringContainsString('lng=120.9812', $mapUrl);
    }

    public function test_direct_url_with_incident_query_parameter_loads_selected_incident(): void
    {
        $this->adminAuth();
        $bike = $this->makeBicycle(['name' => 'Selected Bike 99']);
        $accident = Accident::create([
            'bicycleId' => $bike->id,
            'type' => 'accident',
            'severity' => 'critical',
            'description' => 'Target incident',
            'acknowledged' => false,
        ]);

        $response = $this->get(route('admin.accidents.index', ['incident' => $accident->id]));
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('Selected Bike 99', $html);
        $this->assertStringContainsString('Target incident', $html);
    }
}
