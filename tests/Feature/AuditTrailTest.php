<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bicycle;
use App\Models\DeviceCommand;
use App\Models\Rental;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DeviceCommandService;
use App\Services\RentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * Audit-trail coverage: every security-sensitive operation records exactly
 * one event with the real actor, timestamp, affected entity, outcome and
 * before/after context — and secrets never persist.
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function auditPage(array $params = []): string
    {
        return $this->actingAs($this->makeAdmin())
            ->get(route('admin.audit-log.index', $params))
            ->assertOk()
            ->getContent();
    }

    private function latestAudit(string $action): AuditLog
    {
        $log = AuditLog::where('action', $action)->latest('id')->first();
        $this->assertNotNull($log, "expected an audit row for {$action}");

        return $log;
    }

    // ---- Authentication ----

    public function test_successful_login_records_actor_and_outcome(): void
    {
        $rider = $this->makeRider();

        $this->post(route('login'), ['email' => $rider->email, 'password' => 'password'])
            ->assertRedirect();

        $this->assertSame(1, AuditLog::where('action', 'login_success')->count());
        $log = $this->latestAudit('login_success');
        $this->assertSame($rider->id, $log->userId);
        $this->assertSame('success', $log->details['outcome']);
        $this->assertSame($rider->email, $log->details['email']);
        $this->assertNotNull($log->timestamp);
        $this->assertSame('security', $log->category);
    }

    public function test_failed_login_records_attempted_email_without_password(): void
    {
        $rider = $this->makeRider();

        $this->post(route('login'), ['email' => $rider->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertSame(1, AuditLog::where('action', 'login_failed')->count());
        $log = $this->latestAudit('login_failed');
        $this->assertNull($log->userId);
        $this->assertSame('failed', $log->details['outcome']);
        $this->assertSame($rider->email, $log->details['email']);
        $this->assertArrayNotHasKey('password', $log->details);

        $html = $this->auditPage();
        $this->assertStringContainsString($rider->email, $html);
    }

    public function test_blocked_login_for_inactive_account_names_the_account(): void
    {
        $rider = $this->makeRider(['status' => User::STATUS_INACTIVE]);

        $this->post(route('login'), ['email' => $rider->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $log = $this->latestAudit('login_blocked');
        $this->assertSame($rider->id, $log->userId);
        $this->assertSame('account_inactive', $log->details['reason']);
    }

    public function test_logout_records_actor(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('logout'))->assertRedirect('/');

        $log = $this->latestAudit('logout');
        $this->assertSame($admin->id, $log->userId);
        $this->assertSame('success', $log->details['outcome']);
    }

    // ---- Customer lifecycle ----

    public function test_verification_approve_captures_before_and_after_exactly_once(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => false]);

        $this->from(route('admin.riders.index'))
            ->actingAs($admin)
            ->put(route('admin.riders.verify', $rider->id), ['approved' => 1])
            ->assertRedirect(route('admin.riders.index'));

        $this->assertSame(1, AuditLog::where('action', 'rider_verification_approved')->count());
        $log = $this->latestAudit('rider_verification_approved');
        $this->assertSame($admin->id, $log->userId);
        $this->assertSame($rider->id, $log->details['riderId']);
        $this->assertSame('success', $log->details['outcome']);
        $this->assertSame(false, $log->details['changes']['verified']['from']);
        $this->assertSame(true, $log->details['changes']['verified']['to']);
        $this->assertSame('pending', $log->details['changes']['id_status']['from']);
        $this->assertSame('approved', $log->details['changes']['id_status']['to']);
    }

    public function test_verification_reject_captures_reason(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => false]);

        $this->from(route('admin.riders.index'))
            ->actingAs($admin)
            ->put(route('admin.riders.verify', $rider->id), ['approved' => 0, 'reason' => 'Blurry'])
            ->assertRedirect(route('admin.riders.index'));

        $log = $this->latestAudit('rider_verification_rejected');
        $this->assertSame($admin->id, $log->userId);
        $this->assertSame('rejected', $log->details['changes']['id_status']['to']);
    }

    public function test_suspend_captures_previous_status_exactly_once(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['status' => User::STATUS_ACTIVE]);

        $this->from(route('admin.riders.index'))
            ->actingAs($admin)
            ->put(route('admin.riders.status', $rider->id), ['status' => 'suspended'])
            ->assertRedirect();

        $this->assertSame(1, AuditLog::where('action', 'rider_status_updated')->count());
        $log = $this->latestAudit('rider_status_updated');
        $this->assertSame($admin->id, $log->userId);
        $this->assertSame('active', $log->details['changes']['status']['from']);
        $this->assertSame('suspended', $log->details['changes']['status']['to']);
    }

    public function test_blacklist_update_captures_status_and_reason_changes(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['status' => 'blacklisted']);

        $this->from(route('admin.riders.blacklisted'))
            ->actingAs($admin)
            ->put(route('admin.riders.blacklist.update', $rider->id), [
                'name' => 'Updated',
                'email' => $rider->email,
                'status' => 'suspended',
                'blacklistReason' => 'Violations',
            ])
            ->assertRedirect(route('admin.riders.blacklisted'));

        $log = $this->latestAudit('rider_blacklist_updated');
        $this->assertSame('blacklisted', $log->details['changes']['status']['from']);
        $this->assertSame('suspended', $log->details['changes']['status']['to']);
        $this->assertSame('Violations', $log->details['changes']['blacklistReason']['to']);
    }

    public function test_customer_registration_links_record_and_hides_password(): void
    {
        $admin = $this->makeAdmin();

        $this->from(route('admin.riders.create'))
            ->actingAs($admin)
            ->post(route('admin.riders.store'), [
                'name' => 'Audit Customer',
                'email' => 'audit-customer@pedalya.test',
                'password' => 'supersecretpassword',
                'password_confirmation' => 'supersecretpassword',
            ])
            ->assertRedirect(route('admin.riders.index'));

        $log = $this->latestAudit('rider_created');
        $this->assertSame($admin->id, $log->userId);
        $this->assertNotNull($log->details['riderId']);
        $this->assertArrayNotHasKey('password', $log->details);
        $this->assertArrayNotHasKey('password_confirmation', $log->details);
    }

    // ---- Bicycles and locks ----

    public function test_bicycle_update_captures_changed_fields(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['hourlyRate' => 15.00]);

        $this->from(route('admin.bicycles.index'))
            ->actingAs($admin)
            ->put(route('admin.bicycles.update', $bike->id), ['hourlyRate' => 25])
            ->assertSessionHasNoErrors();

        $log = $this->latestAudit('bicycle_updated');
        $this->assertSame($admin->id, $log->userId);
        $this->assertSame($bike->id, $log->details['bicycleId']);
        $this->assertEqualsWithDelta(15.00, (float) $log->details['changes']['hourlyRate']['from'], 0.001);
        $this->assertEqualsWithDelta(25.00, (float) $log->details['changes']['hourlyRate']['to'], 0.001);
    }

    public function test_manual_lock_records_actor_and_previous_state(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);

        $this->from(route('admin.bicycles.index'))
            ->actingAs($admin)
            ->post(route('admin.bicycles.lock', $bike->id), ['action' => 'unlock'])
            ->assertRedirect(route('admin.bicycles.index'));

        $this->assertSame(1, AuditLog::where('action', 'bicycle_lock_unlock')->count());
        $log = $this->latestAudit('bicycle_lock_unlock');
        $this->assertSame($admin->id, $log->userId);
        $this->assertSame('locked', $log->details['changes']['lockStatus']['from']);
        $this->assertSame('unlocked', $log->details['changes']['lockStatus']['to']);
    }

    public function test_lock_command_result_recorded_once_per_transition(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $service = app(DeviceCommandService::class);

        $command = $service->sendCommand($bike->id, 'lock', [], $admin);
        $this->assertNotNull($command);

        $this->assertTrue($service->acknowledgeDeviceCommand($command->id, 'ok', 'executed'));
        $this->assertSame(1, AuditLog::where('action', 'lock_command_executed')->count());

        // A duplicate acknowledgement of the same terminal state records nothing.
        $this->assertTrue($service->acknowledgeDeviceCommand($command->id, 'ok', 'executed'));
        $this->assertSame(1, AuditLog::where('action', 'lock_command_executed')->count());

        $log = $this->latestAudit('lock_command_executed');
        $this->assertNull($log->userId);
        $this->assertSame('success', $log->details['outcome']);
        $this->assertSame($bike->id, $log->details['bicycleId']);
        $this->assertSame($admin->id, $log->details['issuedBy']);
        $this->assertSame('pending', $log->details['changes']['status']['from']);
        $this->assertSame('executed', $log->details['changes']['status']['to']);
    }

    public function test_failed_lock_command_records_outcome(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $service = app(DeviceCommandService::class);

        $command = $service->sendCommand($bike->id, 'lock', [], $admin);
        $this->assertTrue($service->acknowledgeDeviceCommand($command->id, 'timeout', 'failed'));

        $log = $this->latestAudit('lock_command_failed');
        $this->assertSame('failed', $log->details['outcome']);
        $this->assertSame($bike->id, $log->details['bicycleId']);
    }

    // ---- Rentals, fees, configuration ----

    public function test_deadline_change_keeps_previous_deadline(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_EXPIRED,
            'expiredAt' => now()->subHour(),
            'expectedEndTime' => now()->subHour(),
        ]);

        $put = function (int $minutes) use ($admin, $rental) {
            $this->actingAs($admin)
                ->from(route('admin.rentals.returns'))
                ->put(route('admin.rentals.set-deadline', $rental->id), [
                    'minutes' => $minutes,
                    'penaltyType' => Rental::PENALTY_PER_MINUTE,
                    'penaltyValue' => 10,
                ])
                ->assertRedirect(route('admin.rentals.returns'));
        };
        $put(90);
        $put(60);

        $this->assertSame(1, AuditLog::where('action', 'rental_deadline_set')->count());
        $this->assertSame(1, AuditLog::where('action', 'rental_deadline_changed')->count());
        $log = $this->latestAudit('rental_deadline_changed');
        $this->assertNotNull($log->details['previous_deadline']);
        $this->assertNotNull($log->details['deadline']);
        $this->assertNotSame($log->details['previous_deadline'], $log->details['deadline']);
    }

    public function test_settings_update_captures_before_and_after_values(): void
    {
        $admin = $this->makeAdmin();
        \App\Models\SystemSetting::updateOrCreate(['key' => 'rentalRatePerHour'], ['value' => '15']);

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), ['rentalRatePerHour' => 25])
            ->assertOk();

        $log = $this->latestAudit('system_settings_updated');
        $this->assertSame($admin->id, $log->userId);
        $this->assertSame('15', (string) $log->details['changes']['rentalRatePerHour']['from']);
        $this->assertSame('25', (string) $log->details['changes']['rentalRatePerHour']['to']);
    }

    // ---- Safeguards ----

    public function test_sensitive_values_are_redacted(): void
    {
        $log = AuditLogger::record('login_failed', [
            'email' => 'user@pedalya.test',
            'password' => 'hunter2',
            'nested' => ['apiToken' => 'abc123', 'deviceApiKey' => 'deadbeef'],
            'outcome' => 'failed',
        ]);

        $this->assertSame('[redacted]', $log->details['password']);
        $this->assertSame('[redacted]', $log->details['nested']['apiToken']);
        $this->assertSame('[redacted]', $log->details['nested']['deviceApiKey']);
        $this->assertSame('user@pedalya.test', $log->details['email']);
    }

    public function test_audit_records_are_append_only(): void
    {
        $log = AuditLog::record('test_action', $this->makeAdmin()->id, ['k' => 'v']);

        $log->update(['action' => 'tampered']);
        $this->assertSame('test_action', $log->fresh()->action);

        $log->delete();
        $this->assertNotNull(AuditLog::find($log->id));
    }

    public function test_non_admin_cannot_open_audit_or_activity_logs(): void
    {
        $rider = $this->makeRider();

        $this->actingAs($rider)->get(route('admin.audit-log.index'))->assertRedirect();
        $this->actingAs($rider)->get(route('admin.audit-log.index', ['tab' => 'activity']))->assertRedirect();
    }

    public function test_activity_tab_lists_operational_events_only(): void
    {
        $admin = $this->makeAdmin();
        AuditLog::record('maintenance_created', $admin->id, ['maintenanceId' => 7001]);
        AuditLog::record('rental_approved', $admin->id, ['rentalId' => 'REN-TAB-1']);

        // The filter dropdown lists every known action on both tabs; row
        // content (distinctive record ids) proves the actual separation.
        $audit = $this->actingAs($admin)->get(route('admin.audit-log.index'))->assertOk()->getContent();
        $this->assertStringContainsString('REN-TAB-1', $audit);
        $this->assertStringNotContainsString('7001', $audit);

        $activity = $this->actingAs($admin)->get(route('admin.audit-log.index', ['tab' => 'activity']))->assertOk()->getContent();
        $this->assertStringContainsString('7001', $activity);
        $this->assertStringNotContainsString('REN-TAB-1', $activity);
    }

    public function test_clear_filters_resets_to_tab_base(): void
    {
        $html = $this->auditPageWithAdmin(['tab' => 'activity']);

        $this->assertStringContainsString('Activity Logs', $html);
        $this->assertStringContainsString(route('admin.audit-log.index', ['tab' => 'activity']), $html);
    }

    public function test_details_modal_carries_before_and_after_changes(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => false]);

        $this->from(route('admin.riders.index'))
            ->actingAs($admin)
            ->put(route('admin.riders.verify', $rider->id), ['approved' => 1]);

        $html = $this->actingAs($admin)->get(route('admin.audit-log.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Change Details', $html);
        $this->assertStringContainsString('Security Context', $html);
        $this->assertStringContainsString('verified', $html);
    }

    public function test_unknown_actions_default_to_security(): void
    {
        $this->assertSame('security', AuditLogger::categoryFor('rental_approved'));
        $this->assertSame('security', AuditLogger::categoryFor('some_future_event'));
        $this->assertSame('operational', AuditLogger::categoryFor('maintenance_created'));
        $this->assertSame('operational', AuditLogger::categoryFor('accident_acknowledged'));
    }

    public function test_login_success_carries_security_context_and_no_invented_resource(): void
    {
        $rider = $this->makeRider();

        $this->post(route('login'), ['email' => $rider->email, 'password' => 'password'])
            ->assertRedirect();

        $log = $this->latestAudit('login_success');
        $this->assertSame('127.0.0.1', $log->details['ip_address']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $log->details['request_id']
        );
        $this->assertSame('POST', $log->details['http_method']);

        $html = $this->actingAs($this->makeAdmin())->get(route('admin.audit-log.index'))->assertOk()->getContent();

        $this->assertStringContainsString('No specific resource', $html);
        $this->assertStringContainsString('Security Context', $html);
        $this->assertStringContainsString('IP Address', $html);
        $this->assertStringContainsString('Request ID', $html);
    }

    public function test_failed_login_attributes_attempted_email_and_role_is_absent(): void
    {
        $rider = $this->makeRider();

        $this->post(route('login'), ['email' => $rider->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $log = $this->latestAudit('login_failed');
        $this->assertNull($log->userId);

        $html = $this->actingAs($this->makeAdmin())->get(route('admin.audit-log.index'))->assertOk()->getContent();

        // The attempted address identifies the actor; nothing is invented.
        $this->assertStringContainsString($rider->email, $html);
        $this->assertStringContainsString('No specific resource', $html);
    }

    public function test_events_from_one_request_share_a_correlation_id(): void
    {
        $admin = $this->makeAdmin();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);

        // One request produces two audit rows (auto maintenance + update).
        $this->from(route('admin.bicycles.index'))
            ->actingAs($admin)
            ->put(route('admin.bicycles.update', $bike->id), ['status' => 'maintenance'])
            ->assertSessionHasNoErrors();

        $ids = AuditLog::whereIn('action', ['bicycle_maintenance_auto', 'bicycle_updated'])
            ->orderBy('id')
            ->pluck('details')
            ->map(fn ($details) => $details['request_id'] ?? null)
            ->all();

        $this->assertCount(2, $ids);
        $this->assertNotNull($ids[0]);
        $this->assertSame($ids[0], $ids[1]);
    }

    public function test_suspension_modal_shows_actor_email_role_and_single_outcome(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['status' => User::STATUS_ACTIVE]);

        $this->from(route('admin.riders.index'))
            ->actingAs($admin)
            ->put(route('admin.riders.status', $rider->id), ['status' => 'suspended'])
            ->assertRedirect();

        $html = $this->actingAs($admin)->get(route('admin.audit-log.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Actor', $html);
        $this->assertStringContainsString($admin->email, $html);
        $this->assertStringContainsString('Change Details', $html);
    }

    public function test_return_confirmation_records_fees_and_outcome_once(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
            'endTime' => now()->subHour(),
            'expectedEndTime' => now()->subHour(),
            'totalFee' => 15,
            'overdueFee' => 5,
            'finalFee' => 20,
        ]);

        $service = \Mockery::mock(RentalService::class);
        $service->shouldReceive('processReturn')->once()->andReturn([
            'rental' => $rental->fresh(),
            'fees' => ['baseFee' => 15, 'overdueFee' => 5, 'finalFee' => 20],
        ]);
        $this->app->instance(RentalService::class, $service);

        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'condition' => 'good',
                'note' => 'Looks fine',
            ])
            ->assertRedirect(route('admin.rentals.returns'));

        $this->assertSame(1, AuditLog::where('action', 'rental_return_processed')->count());
        $log = $this->latestAudit('rental_return_processed');
        $this->assertSame($admin->id, $log->userId);
        $this->assertSame('success', $log->details['outcome']);
        $this->assertSame('20.00', number_format((float) $log->details['finalFee'], 2));
    }

    private function auditPageWithAdmin(array $params = []): string
    {
        return $this->actingAs($this->makeAdmin())
            ->get(route('admin.audit-log.index', $params))
            ->assertOk()
            ->getContent();
    }

    /**
     * Decode the modal dataset embedded in the page. @json hex-escapes quotes,
     * which json_decode resolves natively.
     */
    private function auditDataset(string $html): array
    {
        $found = preg_match(
            '/var __auditDetails = (\{.*\});\s*\nfunction auditEsc/s',
            $html,
            $matches
        );
        $this->assertSame(1, $found, 'modal dataset must be embedded in the page');
        $data = json_decode($matches[1], true);
        $this->assertIsArray($data, 'modal dataset must decode to an array');

        return $data;
    }

    private function onlyAuditDataset(string $html): array
    {
        $data = $this->auditDataset($html);
        $this->assertCount(1, $data, 'expected exactly one modal dataset entry');
        $entry = reset($data);
        $this->assertArrayHasKey('meta', $entry);

        return $entry;
    }

    public function test_modal_metadata_has_no_duplicate_outcome_email_or_role(): void
    {
        $rider = $this->makeRider();

        $this->post(route('login'), ['email' => $rider->email, 'password' => 'password'])
            ->assertRedirect();

        $entry = $this->onlyAuditDataset($this->auditPageWithAdmin());

        // Outcome lives in Event Information; actor email/role live in Actor.
        $this->assertArrayNotHasKey('outcome', $entry['meta']);
        $this->assertArrayNotHasKey('email', $entry['meta']);
        $this->assertArrayNotHasKey('role', $entry['meta']);
        $this->assertSame('success', $entry['outcome']);
        $this->assertSame($rider->email, $entry['email']);
        $this->assertSame('Rider', $entry['role']);
        $this->assertSame('No specific resource', $entry['resource']);
    }

    public function test_rider_email_kept_as_context_when_actor_is_the_admin(): void
    {
        $admin = $this->makeAdmin();

        $this->from(route('admin.riders.create'))
            ->actingAs($admin)
            ->post(route('admin.riders.store'), $this->validCustomerPayload('kept-context@pedalya.test'))
            ->assertRedirect(route('admin.riders.index'));

        $entry = $this->onlyAuditDataset($this->auditPageWithAdmin());

        // The created account's address differs from the admin actor, so it
        // stays visible as event-specific metadata.
        $this->assertSame($admin->email, $entry['email']);
        $this->assertSame('kept-context@pedalya.test', $entry['meta']['email'] ?? null);
        $this->assertSame('User', $entry['resource']);
    }

    public function test_consumed_change_and_outcome_keys_leave_metadata(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => false]);

        $this->from(route('admin.riders.index'))
            ->actingAs($admin)
            ->put(route('admin.riders.verify', $rider->id), ['approved' => 1]);

        $entry = $this->onlyAuditDataset($this->auditPageWithAdmin());

        $fields = array_column($entry['changes'], 'field');
        $this->assertContains('verified', $fields);
        $this->assertArrayNotHasKey('outcome', $entry['meta']);
    }

    public function test_security_context_keys_never_leak_into_metadata(): void
    {
        $rider = $this->makeRider();

        $this->post(route('login'), ['email' => $rider->email, 'password' => 'password'])
            ->assertRedirect();

        $entry = $this->onlyAuditDataset($this->auditPageWithAdmin());

        foreach (['ip_address', 'request_id', 'http_method', 'http_path'] as $key) {
            $this->assertArrayNotHasKey($key, $entry['meta']);
        }
        $this->assertNotEmpty($entry['ip']);
        $this->assertNotEmpty($entry['requestId']);
    }

    private function validCustomerPayload(string $email): array
    {
        return [
            'name' => 'Audit Context Customer',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];
    }
}
