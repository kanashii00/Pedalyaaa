<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class SecureLogoutVerificationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function test_authenticated_pages_carry_no_store_headers(): void
    {
        $admin = $this->makeAdmin();
        $response = $this->actingAs($admin)->get(route('admin.dashboard'));
        $response->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));

        $rider = $this->makeRider();
        $response = $this->actingAs($rider)->get(route('rider.dashboard'));
        $response->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));
    }

    public function test_guest_auth_pages_carry_no_store_headers(): void
    {
        foreach ([route('login'), route('register')] as $url) {
            $response = $this->get($url)->assertOk();
            $this->assertStringContainsString(
                'no-store',
                $response->headers->get('Cache-Control', ''),
                'a cached CSRF form can submit a stale session token'
            );
        }
    }

    public function test_logout_invalidates_session_and_redirects_to_login(): void
    {
        $rider = $this->makeRider();
        $this->actingAs($rider);

        $this->assertAuthenticatedAs($rider);
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        // Back / refresh / direct URL after logout: re-requests hit auth first.
        $this->get(route('rider.dashboard'))->assertRedirect(route('login'));
        $this->get(route('rider.dashboard'))->assertRedirect(route('login'));
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_admin_pages_require_login_after_logout(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        $this->get(route('admin.dashboard'))->assertOk();

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->get(route('admin.geofence.index'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk();
    }

    public function test_protected_api_rejects_unauthenticated_and_revoked_tokens(): void
    {
        // No token at all.
        $this->getJson('/api/auth/profile')->assertStatus(401);
        $this->getJson('/api/rentals')->assertStatus(401);

        // Token revoked by logout stops working; other users are unaffected.
        $userA = $this->makeRider();
        $userB = $this->makeRider();
        $tokenA = $userA->createToken('web')->plainTextToken;
        $tokenB = $userB->createToken('web')->plainTextToken;

        $this->withToken($tokenA)->getJson('/api/auth/profile')->assertOk();

        $this->withToken($tokenA)->postJson('/api/auth/logout')->assertOk();

        // Production boots a fresh container per HTTP request; the test bench
        // reuses one and memoizes the resolved guard user, so drop the auth
        // manager to re-resolve credentials exactly like a new request would.
        $this->app->forgetInstance('auth');

        $this->withToken($tokenA)->getJson('/api/auth/profile')->assertStatus(401);
        $this->withToken($tokenB)->getJson('/api/auth/profile')->assertOk();
    }

    public function test_relogin_creates_working_session(): void
    {
        $rider = $this->makeRider();
        $this->actingAs($rider);
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post(route('login'), [
            'email' => $rider->email,
            'password' => 'password',
        ])->assertRedirect(route('rider.dashboard'));

        $this->assertAuthenticatedAs($rider);
        $this->get(route('rider.dashboard'))->assertOk();
    }
}
