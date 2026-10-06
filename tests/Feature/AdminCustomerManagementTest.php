<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class AdminCustomerManagementTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function test_index_lists_and_filters_riders(): void
    {
        $admin = $this->makeAdmin();
        $this->makeRider(['name' => 'Alice', 'status' => User::STATUS_ACTIVE]);
        $this->makeRider(['name' => 'Bob', 'status' => User::STATUS_SUSPENDED]);

        $this->actingAs($admin)
            ->get(route('admin.riders.index'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.riders.index', ['status' => 'suspended', 'search' => 'Bob', 'verified' => 1]))
            ->assertOk();
    }

    public function test_create_returns_page(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.riders.create'))
            ->assertOk();
    }

    public function test_store_creates_rider_and_audit_log(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.riders.store'), [
                'name' => 'New Rider',
                'email' => 'stored-rider@pedalya.test',
                'phoneNumber' => '09171234567',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertRedirect(route('admin.riders.index'));

        $this->assertDatabaseHas('users', ['email' => 'stored-rider@pedalya.test', 'role' => User::ROLE_RIDER]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'rider_created']);
    }

    public function test_store_validates_duplicate_email(): void
    {
        $admin = $this->makeAdmin();
        $this->makeRider(['email' => 'dup@pedalya.test']);

        $this->actingAs($admin)
            ->post(route('admin.riders.store'), [
                'name' => 'X',
                'email' => 'dup@pedalya.test',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_verify_approves_rider_and_notifies(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => false]);

        $this->from(route('admin.riders.index'))
            ->actingAs($admin)
            ->put(route('admin.riders.verify', $rider->id), ['approved' => 1])
            ->assertRedirect(route('admin.riders.index'));

        $this->assertTrue($rider->fresh()->verified);
        $this->assertDatabaseHas('audit_logs', ['action' => 'rider_verification_approved']);
        $this->assertDatabaseHas('notifications', ['userId' => $rider->id, 'type' => 'verification_approved']);
    }

    public function test_verify_rejects_rider_with_reason(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => true]);

        $this->from(route('admin.riders.index'))
            ->actingAs($admin)
            ->put(route('admin.riders.verify', $rider->id), ['approved' => 0, 'reason' => 'Blurry'])
            ->assertRedirect(route('admin.riders.index'));

        $this->assertFalse($rider->fresh()->verified);
        $this->assertDatabaseHas('notifications', ['userId' => $rider->id, 'type' => 'verification_rejected']);
    }

    public function test_verify_returns_json_for_the_styled_approve_dialog(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => false]);

        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->put(route('admin.riders.verify', $rider->id), ['approved' => 1])
            ->assertOk()
            ->assertJson(['ok' => true, 'verified' => true, 'status' => 'approved']);

        $this->assertTrue($rider->fresh()->verified);
        $this->assertSame('approved', $rider->fresh()->idVerification['status']);
        $this->assertDatabaseHas('notifications', ['userId' => $rider->id, 'type' => 'verification_approved']);
    }

    public function test_approve_verification_uses_a_styled_dialog_instead_of_native_confirm(): void
    {
        $admin = $this->makeAdmin();

        $html = $this->actingAs($admin)
            ->get(route('admin.riders.index'))
            ->assertOk()
            ->getContent();

        // The confirmation is a Pedalya modal with its own title, message and
        // Cancel / Approve Verification buttons...
        $this->assertStringContainsString('id="verifyApproveModal"', $html);
        $this->assertStringContainsString('Approve Customer Verification', $html);
        $this->assertStringContainsString('id="verifyApproveCancel"', $html);
        $this->assertStringContainsString('Approve Verification', $html);
        $this->assertStringContainsString('id="verifyApproveConfirm"', $html);
        $this->assertStringContainsString('id="vrApproveForm"', $html);

        // ...and the browser popup it replaced is gone from the approve flow.
        $this->assertStringNotContainsString("confirm('Approve this customer", $html);

        // Review Verification header: icon, title, subtitle, close X.
        $this->assertStringContainsString('vr-review-head', $html);
        $this->assertStringContainsString('vr-review-head__subtitle', $html);
        $this->assertStringContainsString('vr-review-head__close', $html);
        $this->assertStringContainsString('>Review Verification<', $html);
    }

    public function test_reject_verification_uses_a_styled_dialog_with_a_reason_field(): void
    {
        $admin = $this->makeAdmin();

        $html = $this->actingAs($admin)
            ->get(route('admin.riders.index'))
            ->assertOk()
            ->getContent();

        // Same confirmation family as approve: title, Cancel / Reject
        // Verification buttons and a matching (danger) header icon.
        $this->assertStringContainsString('id="verifyRejectModal"', $html);
        $this->assertStringContainsString('Reject Customer Verification', $html);
        $this->assertStringContainsString('vr-confirm-head__icon--danger', $html);
        $this->assertStringContainsString('id="verifyRejectCancel"', $html);
        $this->assertStringContainsString('id="verifyRejectConfirm"', $html);
        $this->assertStringContainsString('id="verifyRejectLabel">Reject Verification', $html);

        // The reason is collected in the dialog and posts with the reject form.
        $this->assertStringContainsString('id="vrRejectReason"', $html);
        $this->assertStringContainsString('name="reason"', $html);
        $this->assertStringContainsString('form="vrRejectForm"', $html);

        // The browser popup it replaced is gone.
        $this->assertStringNotContainsString("confirm('Reject this customer", $html);
    }

    public function test_verify_rejects_rider_over_json_and_saves_the_reason(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => true]);

        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->put(route('admin.riders.verify', $rider->id), ['approved' => 0, 'reason' => 'Blurry photo'])
            ->assertOk()
            ->assertJson(['ok' => true, 'verified' => false, 'status' => 'rejected']);

        $fresh = $rider->fresh();
        $this->assertFalse($fresh->verified);
        $this->assertSame('rejected', $fresh->idVerification['status']);
        $this->assertSame('Blurry photo', $fresh->idVerification['reason']);
        $this->assertDatabaseHas('notifications', ['userId' => $rider->id, 'type' => 'verification_rejected']);
    }

    public function test_update_status_success(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();

        $this->from(route('admin.riders.index'))
            ->actingAs($admin)
            ->put(route('admin.riders.status', $rider->id), ['status' => 'suspended'])
            ->assertRedirect(route('admin.riders.index'));

        $this->assertSame('suspended', $rider->fresh()->status);
    }

    public function test_update_status_to_active_clears_blacklist_reason(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['status' => 'blacklisted', 'blacklistReason' => 'Past issue']);

        $this->from(route('admin.riders.index'))
            ->actingAs($admin)
            ->put(route('admin.riders.status', $rider->id), ['status' => 'active'])
            ->assertRedirect(route('admin.riders.index'));

        $fresh = $rider->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->blacklistReason);
    }

    public function test_update_status_blocked_when_active_rental_exists(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $this->makeRental(['riderId' => $rider->id, 'status' => Rental::STATUS_ACTIVE]);

        $this->from(route('admin.riders.index'))
            ->actingAs($admin)
            ->put(route('admin.riders.status', $rider->id), ['status' => 'suspended'])
            ->assertSessionHasErrors('status');
    }

    public function test_verified_and_blacklisted_pages(): void
    {
        $admin = $this->makeAdmin();
        $this->makeRider(['verified' => true, 'status' => User::STATUS_ACTIVE]);
        $this->makeRider(['verified' => false, 'status' => 'blacklisted']);

        $this->actingAs($admin)->get(route('admin.riders.verified'))->assertOk();
        $this->actingAs($admin)->get(route('admin.riders.blacklisted'))->assertOk();
        $this->actingAs($admin)->get(route('admin.riders.blacklisted', ['status' => 'blacklisted', 'search' => 'x']))->assertOk();
    }

    public function test_update_blacklist_validates_and_updates(): void
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

        $fresh = $rider->fresh();
        $this->assertSame('suspended', $fresh->status);
        $this->assertSame('Violations', $fresh->blacklistReason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'rider_blacklist_updated']);
    }

    public function test_admin_routes_reject_non_admin(): void
    {
        $rider = $this->makeRider();

        $this->actingAs($rider)
            ->get(route('admin.riders.index'))
            ->assertRedirect();
    }

    public function test_unauthenticated_admin_routes_redirect_to_login(): void
    {
        $this->get(route('admin.riders.index'))->assertRedirect(route('login'));
    }
}
