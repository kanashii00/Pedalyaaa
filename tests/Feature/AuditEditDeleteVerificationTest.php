<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bicycle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class AuditEditDeleteVerificationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function test_bicycle_edit_records_single_entry_with_changes(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['name' => 'Old Name']);

        $this->actingAs($admin)
            ->put(route('admin.bicycles.update', $bike->id), ['name' => 'New Name'])
            ->assertRedirect();

        $logs = AuditLog::where('action', 'bicycle_updated')->get();
        $this->assertCount(1, $logs);
        $log = $logs->first();
        $this->assertSame($admin->id, $log->userId);
        $this->assertSame('success', $log->details['outcome']);
        $this->assertSame('Old Name', $log->details['changes']['name']['from']);
        $this->assertSame('New Name', $log->details['changes']['name']['to']);
        $this->assertNotNull($log->timestamp);
    }

    public function test_bicycle_removal_keeps_snapshot_and_survives(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['name' => 'Doomed Bike']);

        $this->actingAs($admin)
            ->delete(route('admin.bicycles.destroy', $bike->id))
            ->assertRedirect();

        $logs = AuditLog::where('action', 'bicycle_removed')->get();
        $this->assertCount(1, $logs);
        $log = $logs->first();
        $this->assertSame($bike->id, $log->details['bicycleId']);
        $this->assertSame('Doomed Bike', $log->details['snapshot']['name']);
        $this->assertSame($bike->serialNumber, $log->details['snapshot']['serialNumber']);
        $this->assertSame('success', $log->details['outcome']);
        $this->assertSame($admin->id, $log->userId);
        // Snapshot stays queryable after the record leaves the fleet.
        $this->assertSame(Bicycle::STATUS_REMOVED, $bike->fresh()->status);
    }

    public function test_rider_self_delete_records_snapshot_and_survives_user(): void
    {
        $rider = $this->makeRider(['name' => 'Leaving Rider']);
        $email = $rider->email;
        $riderId = $rider->id;

        $this->actingAs($rider)
            ->delete(route('rider.profile.delete'))
            ->assertRedirect(route('home'));

        $this->assertNull(User::find($riderId));

        $logs = AuditLog::where('action', 'rider_deleted')->get();
        $this->assertCount(1, $logs);
        $log = $logs->first();
        $this->assertSame($riderId, $log->userId);
        $this->assertSame($riderId, $log->details['riderId']);
        $this->assertSame('Leaving Rider', $log->details['snapshot']['name']);
        $this->assertSame($email, $log->details['snapshot']['email']);
        $this->assertSame('success', $log->details['outcome']);
        $this->assertArrayNotHasKey('password', $log->details['snapshot']);
    }

    public function test_failed_edit_creates_no_audit_entry(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle();

        $this->actingAs($admin)
            ->put(route('admin.bicycles.update', $bike->id), ['batteryLevel' => 9999])
            ->assertSessionHasErrors('batteryLevel');

        $this->assertSame(0, AuditLog::where('action', 'bicycle_updated')->count());
    }

    public function test_missing_record_creates_no_audit_entry(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->put(route('admin.bicycles.update', 999999), ['name' => 'Ghost'])->assertNotFound();
        $this->actingAs($admin)->delete(route('admin.bicycles.destroy', 999999))->assertNotFound();

        $this->assertSame(0, AuditLog::count());
    }

    public function test_unauthorized_edit_attempt_creates_no_audit_entry(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        $this->actingAs($rider)
            ->put(route('admin.bicycles.update', $bike->id), ['name' => 'Hacked'])
            ->assertRedirect();
        $this->assertSame('Test Bike', $bike->fresh()->name);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_audit_modal_reports_stored_values_verbatim(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['name' => 'Modal Bike']);
        $this->actingAs($admin)->delete(route('admin.bicycles.destroy', $bike->id))->assertRedirect();

        $html = $this->actingAs($admin)->get(route('admin.audit-log.index'))->assertOk()->getContent();

        $found = preg_match('/var __auditDetails = (\{.*\});\s*\nfunction auditEsc/s', $html, $m);
        $this->assertSame(1, $found);
        $data = json_decode($m[1], true);
        $entry = reset($data);
        $this->assertSame('success', $entry['outcome']);
        $this->assertSame('Modal Bike', $entry['raw']['snapshot']['name'] ?? null);
    }
}
