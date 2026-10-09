<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
        ]);
    }

    private function fakeDriver(?SocialiteUser $user = null, bool $fail = false, ?\Throwable $throw = null): Provider
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('redirectUrl')->andReturn($provider);
        $provider->shouldReceive('with')->andReturn($provider);
        $provider->shouldReceive('stateless')->andReturn($provider);
        $provider->shouldReceive('redirect')->andReturn(redirect('http://google.test/authorize'));

        if ($throw) {
            $provider->shouldReceive('user')->andThrow($throw);
        } elseif ($fail) {
            $provider->shouldReceive('user')->andThrow(new \RuntimeException('oauth failed'));
        } else {
            $provider->shouldReceive('user')->andReturn($user);
        }

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        return $provider;
    }

    private function googleUser(array $overrides = []): SocialiteUser
    {
        $user = new SocialiteUser();
        $user->id = $overrides['id'] ?? 'goog-123';
        $user->email = $overrides['email'] ?? 'google@pedalya.test';
        $user->name = $overrides['name'] ?? 'Google Rider';
        $user->nickname = $overrides['nickname'] ?? null;
        $user->avatar = $overrides['avatar'] ?? 'https://x/avatar.png';
        return $user;
    }

    // ---- Web flow ----

    public function test_web_redirect_redirects_to_google(): void
    {
        $this->fakeDriver();
        $this->get(route('login.google'))->assertRedirect();
    }

    public function test_web_callback_new_user_goes_to_register(): void
    {
        $this->fakeDriver($this->googleUser());

        $this->get(route('login.google.callback'))
            ->assertRedirect(route('register'))
            ->assertSessionHas('pending_oauth');
    }

    public function test_web_callback_existing_rider_logs_in(): void
    {
        $rider = $this->makeRider(['email' => 'google@pedalya.test']);
        $this->fakeDriver($this->googleUser());

        $this->get(route('login.google.callback'))
            ->assertRedirect(route('rider.dashboard'));

        $this->assertAuthenticatedAs($rider);
        $this->assertDatabaseHas('users', ['id' => $rider->id, 'google_id' => 'goog-123']);
    }

    public function test_web_callback_existing_admin_logs_in(): void
    {
        $admin = $this->makeAdmin(['email' => 'google@pedalya.test']);
        $this->fakeDriver($this->googleUser());

        $this->get(route('login.google.callback'))
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_web_callback_survives_a_long_google_avatar_url(): void
    {
        // Real lh3.googleusercontent.com photo URLs with size parameters
        // regularly exceed the old VARCHAR(255) avatar column, which aborted
        // sign-in with "Data too long for column 'avatar'".
        $longAvatar = 'https://lh3.googleusercontent.com/a/' . str_repeat('x', 400) . '=s96-c';

        $rider = $this->makeRider(['email' => 'google@pedalya.test']);
        $this->fakeDriver($this->googleUser(['avatar' => $longAvatar]));

        $this->get(route('login.google.callback'))
            ->assertRedirect(route('rider.dashboard'));

        $this->assertAuthenticatedAs($rider);
        $this->assertSame($longAvatar, $rider->fresh()->avatar);
    }

    public function test_web_callback_inactive_user_rejected(): void
    {
        $this->makeRider(['email' => 'google@pedalya.test', 'status' => User::STATUS_INACTIVE]);
        $this->fakeDriver($this->googleUser());

        $this->get(route('login.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }

    public function test_web_callback_failure_redirects_to_login(): void
    {
        $this->fakeDriver(fail: true);
        $this->get(route('login.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }

    public function test_web_callback_state_mismatch_names_the_expired_session(): void
    {
        $this->fakeDriver(throw: new InvalidStateException('Invalid state.'));

        $html = $this->from(route('login'))
            ->followingRedirects()
            ->get(route('login.google.callback'))
            ->getContent();

        $this->assertStringContainsString(
            'Your Google sign-in session expired. Please try again from the login page.',
            $html,
            'a lost OAuth state must name the cause instead of the generic failure'
        );
    }

    public function test_web_callback_cancelled_consent_says_so(): void
    {
        $this->fakeDriver($this->googleUser());

        $html = $this->from(route('login'))
            ->followingRedirects()
            ->get(route('login.google.callback', ['error' => 'access_denied']))
            ->getContent();

        $this->assertStringContainsString(
            'Google sign-in was cancelled. You can try again or use email login.',
            $html,
            'a user-cancelled consent must not read as a broken sign-in'
        );
        $this->assertGuest();
    }

    public function test_web_callback_exchange_failure_keeps_the_generic_message(): void
    {
        $this->fakeDriver(fail: true);

        $html = $this->from(route('login'))
            ->followingRedirects()
            ->get(route('login.google.callback'))
            ->getContent();

        $this->assertStringContainsString(
            'Google sign-in failed. Please try again or use email login.',
            $html,
            'unclassified exchange failures keep the generic message'
        );
    }

    public function test_web_redirect_without_config_aborts(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);
        $this->get(route('login.google'))->assertStatus(500);
    }

    // ---- API flow ----

    public function test_api_redirect_returns_url(): void
    {
        $this->fakeDriver();
        $this->getJson('/api/auth/google/redirect')->assertOk()->assertJsonPath('url', 'http://google.test/authorize');
    }

    public function test_api_callback_new_user_needs_registration(): void
    {
        $this->fakeDriver($this->googleUser());

        $this->getJson('/api/auth/google/callback')
            ->assertOk()
            ->assertJson(['needs_registration' => true]);
    }

    public function test_api_callback_existing_user_returns_token(): void
    {
        $rider = $this->makeRider(['email' => 'google@pedalya.test']);
        $this->fakeDriver($this->googleUser());

        $this->getJson('/api/auth/google/callback?device_name=mobile')
            ->assertOk()
            ->assertJsonStructure(['token', 'token_type' => [], 'user']);
        $this->assertDatabaseHas('users', ['id' => $rider->id, 'google_id' => 'goog-123']);
    }

    public function test_api_callback_inactive_user_403(): void
    {
        $this->makeRider(['email' => 'google@pedalya.test', 'status' => User::STATUS_INACTIVE]);
        $this->fakeDriver($this->googleUser());

        $this->getJson('/api/auth/google/callback')->assertStatus(403);
    }

    public function test_api_callback_failure_401(): void
    {
        $this->fakeDriver(fail: true);
        $this->getJson('/api/auth/google/callback')->assertStatus(401);
    }
}
