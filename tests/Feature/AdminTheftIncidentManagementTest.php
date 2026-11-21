<?php

namespace Tests\Feature;

use App\Models\Accident;
use App\Models\AuditLog;
use App\Models\Bicycle;
use App\Models\Geofence;
use App\Models\GeofenceBreach;
use App\Models\Notification;
use App\Models\Rental;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\IoTService;
use App\Services\TheftDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class AdminTheftIncidentManagementTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private Geofence $geofence;

    protected function setUp(): void
    {
        parent::setUp();

        $this->geofence = Geofence::create([
            'name' => 'Azuela Cove Safe Zone',
            'centerLat' => 7.0990,
            'centerLng' => 125.6470,
            'radius' => 500.0,
            'shapeType' => 'circle',
            'isActive' => true,
            'alertEnabled' => true,
            'warningThreshold' => 100.0,
        ]);

        $iotMock = Mockery::mock(IoTService::class);
        $iotMock->shouldReceive('sendCommand')->byDefault();
        $this->app->instance(IoTService::class, $iotMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_immediate_theft_notification_generated_for_administrators(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['name' => 'Speedy Cruiser 001', 'serialNumber' => 'SC-001', 'status' => Bicycle::STATUS_AVAILABLE]);

        $service = app(TheftDetectionService::class);
        $service->processLocation($bike, 8.0, 126.0, [
            'inside' => false,
            'level' => 'breach',
            'distanceOutside' => 150.0,
            'distanceToBoundary' => 150.0,
        ]);

        $incident = Accident::where('bicycleId', $bike->id)->where('type', 'theft')->first();
        $this->assertNotNull($incident);
        $this->assertSame('critical', $incident->severity);

        // Check in-app notification created for the administrator
        $notification = Notification::where('userId', $admin->id)->where('type', 'theft')->first();
        $this->assertNotNull($notification);
        $this->assertSame('Critical Security Alert', $notification->title);
        $this->assertStringContainsString('Speedy Cruiser 001', $notification->message);
        $this->assertStringContainsString('moved outside its designated geofence', $notification->message);
        $this->assertSame((string) $incident->id, (string) $notification->incidentId);
        $this->assertSame($bike->id, (int) $notification->bicycleId);

        // Check sidebar badge in admin dashboard reflects the unresolved theft incident
        $dashboardHtml = $this->actingAs($admin)->get(route('admin.dashboard'))->getContent();
        $this->assertStringContainsString('Theft Detection</span>', $dashboardHtml);
        $this->assertStringContainsString('admin-nav__badge--danger', $dashboardHtml);
    }

    public function test_alert_severity_evaluation_rules(): void
    {
        $service = app(TheftDetectionService::class);

        // 1. Unrented bicycle outside geofence -> CRITICAL (unauthorized movement)
        $unrentedBike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE, 'currentRider' => null, 'currentRentalId' => null]);
        $sev1 = $service->evaluateSeverity($unrentedBike, 8.0, 126.0, 30.0, ['inside' => false, 'level' => 'breach']);
        $this->assertSame('critical', $sev1);

        // 2. Locked bicycle outside geofence -> CRITICAL (unauthorized movement while locked)
        $lockedBike = $this->makeBicycle(['lockStatus' => Bicycle::LOCK_LOCKED]);
        $sev2 = $service->evaluateSeverity($lockedBike, 8.0, 126.0, 10.0, ['inside' => false, 'level' => 'breach']);
        $this->assertSame('critical', $sev2);

        // 3. Actively rented unlocked bicycle slightly outside boundary (<= 25m) -> WARNING
        $rider = $this->makeRider();
        $rentedBike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED, 'lockStatus' => Bicycle::LOCK_UNLOCKED, 'currentRider' => $rider->id]);
        $rental = Rental::create([
            'rentalId' => 'R-SEV-01',
            'bicycleId' => $rentedBike->id,
            'riderId' => $rider->id,
            'riderName' => $rider->name,
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => now(),
            'ratePerHour' => 15.00,
        ]);
        $rentedBike->update(['currentRentalId' => $rental->id]);

        $sev3 = $service->evaluateSeverity($rentedBike, 8.0, 126.0, 15.0, ['inside' => false, 'level' => 'breach']);
        $this->assertSame('warning', $sev3);

        // 4. Actively rented bicycle far outside boundary (> 25m) -> CRITICAL
        $sev4 = $service->evaluateSeverity($rentedBike, 8.0, 126.0, 40.0, ['inside' => false, 'level' => 'breach']);
        $this->assertSame('critical', $sev4);

        // 5. Inside boundary but approaching or warning -> WARNING
        $sev5 = $service->evaluateSeverity($rentedBike, 7.0990, 125.6470, 0, ['inside' => true, 'level' => 'warning']);
        $this->assertSame('warning', $sev5);
    }

    public function test_incident_details_endpoint_returns_complete_security_telemetry(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['name' => 'Maria Santos', 'email' => 'maria@example.com', 'phoneNumber' => '09171234567', 'studentId' => '2026-0042']);
        $bike = $this->makeBicycle(['name' => 'Mountain Pro 7', 'serialNumber' => 'MP-777', 'status' => Bicycle::STATUS_RENTED]);
        $rental = Rental::create([
            'rentalId' => 'R-DETAILS-01',
            'bicycleId' => $bike->id,
            'riderId' => $rider->id,
            'riderName' => 'Maria Santos',
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => now()->subHour(),
            'ratePerHour' => 20.00,
        ]);
        $bike->update(['currentRentalId' => $rental->id, 'currentRider' => $rider->id]);

        $incident = Accident::create([
            'bicycleId' => $bike->id,
            'rentalId' => $rental->id,
            'riderId' => $rider->id,
            'riderName' => 'Maria Santos',
            'type' => 'theft',
            'severity' => 'critical',
            'status' => 'open',
            'investigationStatus' => 'pending',
            'gpsLocation' => ['lat' => 7.1500, 'lng' => 125.7000],
            'breachDistance' => 120.5,
            'description' => 'Bicycle exited riding zone.',
            'acknowledged' => false,
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.theft-alerts.details', $incident->id))
            ->assertOk();

        $data = $response->json();
        $this->assertSame($incident->id, $data['id']);
        $this->assertSame('Mountain Pro 7', $data['bicycle']['name']);
        $this->assertSame('MP-777', $data['bicycle']['serialNumber']);
        $this->assertSame('Maria Santos', $data['rider']['displayName']);
        $this->assertSame('maria@example.com', $data['rider']['email']);
        $this->assertSame('09171234567', $data['rider']['phoneNumber']);
        $this->assertSame('2026-0042', $data['rider']['studentId']);
        $this->assertSame($rental->id, $data['rental']['id']);
        $this->assertSame('critical', $data['incident']['severity']);
        $this->assertSame('Pending Review', $data['incident']['investigationStatus']);
        $this->assertFalse($data['incident']['acknowledged']);
        $this->assertEquals(7.1500, $data['incident']['lat']);
        $this->assertEquals(125.7000, $data['incident']['lng']);
        $this->assertStringContainsString('section=gps', $data['liveTrackingUrl']);
    }

    public function test_administrator_actions_and_audit_trail(): void
    {
        $admin = $this->makeAdmin(['name' => 'Officer Miller']);
        $rider = $this->makeRider(['name' => 'Carlos Reyes']);
        $bike = $this->makeBicycle(['name' => 'City Commuter 12']);
        $rental = Rental::create([
            'rentalId' => 'R-ACT-01',
            'bicycleId' => $bike->id,
            'riderId' => $rider->id,
            'riderName' => 'Carlos Reyes',
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => now(),
            'ratePerHour' => 15.00,
        ]);

        $incident = Accident::create([
            'bicycleId' => $bike->id,
            'rentalId' => $rental->id,
            'riderId' => $rider->id,
            'riderName' => 'Carlos Reyes',
            'type' => 'theft',
            'severity' => 'critical',
            'status' => 'open',
            'acknowledged' => false,
        ]);

        GeofenceBreach::create([
            'bicycleId' => $bike->id,
            'lat' => 7.2,
            'lng' => 125.7,
            'distance' => 100,
            'acknowledged' => false,
            'resolvedAt' => null,
        ]);

        // 1. Acknowledge Alert
        $this->actingAs($admin)
            ->postJson(route('admin.theft-alerts.acknowledge', $incident->id))
            ->assertOk();

        $incident->refresh();
        $this->assertTrue($incident->acknowledged);
        $ackLog = AuditLog::where('action', AuditLogger::THEFT_ALERT_ACKNOWLEDGED)->first();
        $this->assertNotNull($ackLog);
        $this->assertSame($admin->id, $ackLog->userId);
        $this->assertSame('Carlos Reyes', $ackLog->details['rider']);

        // 2. Investigate
        $this->actingAs($admin)
            ->postJson(route('admin.theft-alerts.investigate', $incident->id), [
                'notes' => 'Campus security team dispatched to northeast boundary',
            ])
            ->assertOk();

        $incident->refresh();
        $this->assertSame(TheftDetectionService::STATUS_INVESTIGATING, $incident->status);
        $this->assertSame('Under Investigation', $incident->investigationStatusDisplay);
        $invLog = AuditLog::where('action', AuditLogger::THEFT_ALERT_INVESTIGATED)->first();
        $this->assertNotNull($invLog);
        $this->assertStringContainsString('Campus security team', $invLog->details['notes']);

        // 3. Mark as False Alarm requires resolution notes
        $this->actingAs($admin)
            ->postJson(route('admin.theft-alerts.false-alarm', $incident->id), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['resolution_notes']);

        // 4. Resolve incident with required resolution notes
        $this->actingAs($admin)
            ->postJson(route('admin.theft-alerts.resolve', $incident->id), [
                'resolution_notes' => 'Rider escorted back into designated zone. Bicycle safely inspected.',
            ])
            ->assertOk();

        $incident->refresh();
        $this->assertSame(TheftDetectionService::STATUS_RESOLVED, $incident->status);
        $this->assertSame('Resolved', $incident->investigationStatusDisplay);
        $this->assertSame($admin->id, $incident->resolvedBy);
        $this->assertNotNull($incident->resolvedAt);
        $this->assertSame('Rider escorted back into designated zone. Bicycle safely inspected.', $incident->resolutionNotes);

        // Breach must be marked resolved
        $breach = GeofenceBreach::where('bicycleId', $bike->id)->first();
        $this->assertNotNull($breach->resolvedAt);

        // Audit log for resolution
        $resLog = AuditLog::where('action', AuditLogger::THEFT_ALERT_RESOLVED)->first();
        $this->assertNotNull($resLog);
        $this->assertSame($admin->id, $resLog->userId);
        $this->assertSame('Carlos Reyes', $resLog->details['rider']);
        $this->assertSame('Rider escorted back into designated zone. Bicycle safely inspected.', $resLog->details['notes']);

        // Verify record is preserved in database (not deleted)
        $this->assertDatabaseHas('accidents', ['id' => $incident->id, 'status' => 'resolved']);
    }

    public function test_duplicate_alert_prevention_updates_existing_incident_without_new_notifications(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['name' => 'Cruiser 99', 'status' => Bicycle::STATUS_AVAILABLE]);
        $service = app(TheftDetectionService::class);

        // First breach fix
        $alert1 = $service->openOrUpdateTheftAlert($bike, 7.2000, 125.7000, 100.0, [
            'inside' => false,
            'level' => 'breach',
            'distanceOutside' => 100.0,
        ]);
        $this->assertNotNull($alert1);
        $initialAlertCount = Accident::where('bicycleId', $bike->id)->count();
        $this->assertSame(1, $initialAlertCount);
        $initialNotifCount = Notification::where('type', 'theft')->count();
        $this->assertSame(1, $initialNotifCount);

        // Subsequent live GPS updates while still outside
        $alert2 = $service->openOrUpdateTheftAlert($bike, 7.2050, 125.7050, 150.0, [
            'inside' => false,
            'level' => 'breach',
            'distanceOutside' => 150.0,
        ]);

        $this->assertSame($alert1->id, $alert2->id);
        $this->assertSame(1, Accident::where('bicycleId', $bike->id)->count());
        $this->assertSame(1, Notification::where('type', 'theft')->count());
        $this->assertEquals(150.0, (float) $alert2->breachDistance);
        $this->assertEquals(7.2050, $alert2->gpsLocation['lat']);
    }

    public function test_notification_escalation_for_unacknowledged_critical_theft(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['name' => 'Security Patrol Bike']);
        $service = app(TheftDetectionService::class);

        SystemSetting::setValue('theft_escalation_interval_minutes', 10);

        // Create unacknowledged critical alert created 20 minutes ago
        $alert = Accident::create([
            'bicycleId' => $bike->id,
            'type' => 'theft',
            'severity' => 'critical',
            'status' => 'open',
            'acknowledged' => false,
        ]);
        $alert->forceFill([
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
        ])->saveQuietly();

        $escalated = $service->escalateUnacknowledgedAlerts();
        $this->assertSame(1, $escalated);

        $alert->refresh();
        $this->assertNotNull($alert->lastEscalatedAt);

        // Check escalation notification sent
        $escalationNotif = Notification::where('title', 'Escalation: Unacknowledged Theft Alert')->first();
        $this->assertNotNull($escalationNotif);
        $this->assertStringContainsString('remained unacknowledged', $escalationNotif->message);

        // Running immediately again should not re-escalate (interval has not passed)
        $this->assertSame(0, $service->escalateUnacknowledgedAlerts());

        // Once acknowledged, it will never escalate
        $alert->update(['acknowledged' => true, 'lastEscalatedAt' => now()->subMinutes(30)]);
        $this->assertSame(0, $service->escalateUnacknowledgedAlerts());
    }

    public function test_correct_rider_identification_and_rental_association(): void
    {
        $admin = $this->makeAdmin();

        // Rider 1: Registered account with full name
        $rider1 = $this->makeRider(['name' => 'Juan Dela Cruz', 'email' => 'juan@example.com']);
        $bike1 = $this->makeBicycle(['name' => 'Commuter 01', 'status' => Bicycle::STATUS_RENTED]);
        $rental1 = Rental::create([
            'rentalId' => 'R-JUAN-01',
            'bicycleId' => $bike1->id,
            'riderId' => $rider1->id,
            'riderName' => 'Juan Dela Cruz',
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => now(),
            'ratePerHour' => 15.00,
        ]);
        $bike1->update(['currentRentalId' => $rental1->id, 'currentRider' => $rider1->id]);

        // Rider 2: Registered account with another full name
        $rider2 = $this->makeRider(['name' => 'Elena Gomez', 'email' => 'elena@example.com']);
        $bike2 = $this->makeBicycle(['name' => 'Commuter 02', 'status' => Bicycle::STATUS_RENTED]);
        $rental2 = Rental::create([
            'rentalId' => 'R-ELENA-02',
            'bicycleId' => $bike2->id,
            'riderId' => $rider2->id,
            'riderName' => 'Elena Gomez',
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => now(),
            'ratePerHour' => 15.00,
        ]);
        $bike2->update(['currentRentalId' => $rental2->id, 'currentRider' => $rider2->id]);

        // Bike 3: Unrented bicycle (No active rental)
        $bike3 = $this->makeBicycle(['name' => 'Inventory Bike 03', 'status' => Bicycle::STATUS_AVAILABLE, 'currentRider' => null, 'currentRentalId' => null]);

        $service = app(TheftDetectionService::class);

        // Process theft on Bike 1
        $alert1 = $service->openOrUpdateTheftAlert($bike1, 7.3, 125.8, 80.0, ['inside' => false, 'level' => 'breach']);
        // Process theft on Bike 2
        $alert2 = $service->openOrUpdateTheftAlert($bike2, 7.4, 125.9, 90.0, ['inside' => false, 'level' => 'breach']);
        // Process theft on Bike 3
        $alert3 = $service->openOrUpdateTheftAlert($bike3, 7.5, 126.0, 100.0, ['inside' => false, 'level' => 'breach']);

        // Verify Bike 1 identifies Juan Dela Cruz
        $this->assertSame($rental1->id, $alert1->rentalId);
        $this->assertSame($rider1->id, $alert1->riderId);
        $this->assertSame('Juan Dela Cruz', $alert1->riderDisplayName);

        // Verify Bike 2 identifies Elena Gomez
        $this->assertSame($rental2->id, $alert2->rentalId);
        $this->assertSame($rider2->id, $alert2->riderId);
        $this->assertSame('Elena Gomez', $alert2->riderDisplayName);

        // Verify Bike 3 displays "No Active Rental"
        $this->assertNull($alert3->rentalId);
        $this->assertNull($alert3->riderId);
        $this->assertSame('No Active Rental', $alert3->riderDisplayName);

        // Notifications must reflect the correct rider names
        $notif1 = Notification::where('bicycleId', $bike1->id)->where('type', 'theft')->first();
        $this->assertStringContainsString('Juan Dela Cruz', $notif1->message);

        $notif2 = Notification::where('bicycleId', $bike2->id)->where('type', 'theft')->first();
        $this->assertStringContainsString('Elena Gomez', $notif2->message);

        $notif3 = Notification::where('bicycleId', $bike3->id)->where('type', 'theft')->first();
        $this->assertStringContainsString('No Active Rental', $notif3->message);

        // End rental on Bike 1 (rental ends, bicycle currentRentalId and currentRider cleared)
        $rental1->update(['status' => Rental::STATUS_COMPLETED, 'endTime' => now()]);
        $bike1->update(['currentRentalId' => null, 'currentRider' => null, 'status' => Bicycle::STATUS_AVAILABLE]);

        // Historical incident record for Bike 1 must PRESERVE Juan Dela Cruz
        $alert1Fresh = Accident::find($alert1->id);
        $this->assertSame('Juan Dela Cruz', $alert1Fresh->riderDisplayName);
        $this->assertSame($rental1->id, $alert1Fresh->rentalId);
        $this->assertSame($rider1->id, $alert1Fresh->riderId);

        // Check Theft Alert Log HTML page displays correct rider names and "No Active Rental"
        $theftPageHtml = $this->actingAs($admin)->get(route('admin.theft-alerts.index'))->getContent();
        $this->assertStringContainsString('Juan Dela Cruz', $theftPageHtml);
        $this->assertStringContainsString('Elena Gomez', $theftPageHtml);
        $this->assertStringContainsString('No Active Rental', $theftPageHtml);
    }

    public function test_non_admin_cannot_execute_theft_incident_actions(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $incident = Accident::create([
            'bicycleId' => $bike->id,
            'type' => 'theft',
            'severity' => 'critical',
            'status' => 'open',
            'acknowledged' => false,
        ]);

        // Guest is redirected to login
        $this->post(route('admin.theft-alerts.acknowledge', $incident->id))->assertRedirect(route('login'));

        // Rider gets forbidden / unauthorized
        $this->actingAs($rider)
            ->postJson(route('admin.theft-alerts.acknowledge', $incident->id))
            ->assertForbidden();

        $this->actingAs($rider)
            ->postJson(route('admin.theft-alerts.investigate', $incident->id))
            ->assertForbidden();

        $this->actingAs($rider)
            ->postJson(route('admin.theft-alerts.false-alarm', $incident->id), ['resolution_notes' => 'Test note'])
            ->assertForbidden();

        $this->actingAs($rider)
            ->postJson(route('admin.theft-alerts.resolve', $incident->id), ['resolution_notes' => 'Test note'])
            ->assertForbidden();
    }

    public function test_clickable_bicycle_name_and_action_buttons_in_table_and_modal(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['name' => 'Speedy Cruiser 42', 'serialNumber' => 'SC-042']);
        $incident = Accident::create([
            'bicycleId' => $bike->id,
            'type' => 'theft',
            'severity' => 'critical',
            'status' => 'open',
            'acknowledged' => false,
        ]);

        $html = $this->actingAs($admin)->get(route('admin.theft-alerts.index'))->getContent();

        // 1. Bicycle name is clickable with data-view-incident matching the specific incident ID
        $this->assertStringContainsString('theft-bike-link', $html);
        $this->assertStringContainsString('data-view-incident="' . $incident->id . '"', $html);
        $this->assertStringContainsString('Speedy Cruiser 42', $html);

        // 2. Separate View Details button is removed from the table rows
        $this->assertStringNotContainsString('<i class="bi bi-eye me-1"></i>Details', $html);

        // 3. Acknowledge button is removed from the table's Actions column
        $this->assertStringNotContainsString('data-quick-ack', $html);

        // 4. Modal includes prominent Acknowledge Alert button with aria-label
        $this->assertStringContainsString('id="btnAckModal"', $html);
        $this->assertStringContainsString('aria-label="Acknowledge"', $html);
        $this->assertStringContainsString('Acknowledge Alert', $html);

        // 5. Modal includes accessible close buttons
        $this->assertStringContainsString('data-modal-close aria-label="Close"', $html);
    }

    public function test_acknowledge_inside_incident_details_records_metadata_and_prevents_duplicate(): void
    {
        $admin = $this->makeAdmin(['name' => 'Commander Vance']);
        $bike = $this->makeBicycle(['name' => 'Aero Glide 88']);
        $incident = Accident::create([
            'bicycleId' => $bike->id,
            'type' => 'theft',
            'severity' => 'critical',
            'status' => 'open',
            'acknowledged' => false,
        ]);

        // Create an unread notification matching this theft incident
        $notif = Notification::create([
            'userId' => $admin->id,
            'title' => 'Critical Security Alert',
            'message' => "Theft alert for #{$incident->id}",
            'type' => 'theft',
            'incidentId' => $incident->id,
            'bicycleId' => $bike->id,
            'read' => false,
        ]);

        // First acknowledgment request
        $response1 = $this->actingAs($admin)
            ->postJson(route('admin.theft-alerts.acknowledge', $incident->id))
            ->assertOk();

        $data1 = $response1->json();
        $this->assertTrue($data1['success']);
        $this->assertTrue($data1['acknowledged']);
        $this->assertSame('Commander Vance', $data1['acknowledgedBy']);
        $this->assertNotNull($data1['acknowledgedAt']);

        $incident->refresh();
        $this->assertTrue($incident->acknowledged);
        $this->assertSame($admin->id, $incident->acknowledgedBy);
        $this->assertNotNull($incident->acknowledgedAt);

        // Notification is marked read
        $notif->refresh();
        $this->assertTrue($notif->read);
        $this->assertNotNull($notif->readAt);

        // Duplicate acknowledgment attempt is prevented
        $response2 = $this->actingAs($admin)
            ->postJson(route('admin.theft-alerts.acknowledge', $incident->id))
            ->assertOk();

        $data2 = $response2->json();
        $this->assertTrue($data2['success']);
        $this->assertTrue($data2['alreadyAcknowledged']);
        $this->assertSame('Commander Vance', $data2['acknowledgedBy']);
    }

    public function test_view_live_location_integrates_with_theft_map_and_preserves_context(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['name' => 'Solar Cruiser 55', 'serialNumber' => 'SC-550']);
        $incident = Accident::create([
            'bicycleId' => $bike->id,
            'type' => 'theft',
            'severity' => 'critical',
            'status' => 'open',
            'acknowledged' => false,
            'gpsLocation' => ['lat' => 7.1050, 'lng' => 125.6550],
            'breachDistance' => 125.0,
        ]);

        // 1. Details endpoint provides map focus URL pointing to the theft module
        $details = $this->actingAs($admin)
            ->getJson(route('admin.theft-alerts.details', $incident->id))
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('theftMapUrl', $details);
        $this->assertStringContainsString('focus=map', $details['theftMapUrl']);
        $this->assertStringContainsString((string) $incident->id, $details['theftMapUrl']);

        // 2. Loading Theft page with ?incident=...&focus=map triggers in-module map focus
        $html = $this->actingAs($admin)
            ->get(route('admin.theft-alerts.index', ['incident' => $incident->id, 'focus' => 'map']))
            ->assertOk()
            ->getContent();

        // Must include the in-module map elements and auto-focus script trigger
        $this->assertStringContainsString('id="theftMapCard"', $html);
        $this->assertStringContainsString('id="theftMapActiveAlertBar"', $html);
        $this->assertStringContainsString('overlayBtnReturnDetails', $html);
        $this->assertStringContainsString('overlayBtnFitBounds', $html);
        $this->assertStringContainsString('focusMapOnIncident(' . $incident->id . ')', $html);
        $this->assertStringContainsString('id="btnLiveLocationModal"', $html);
    }
}
