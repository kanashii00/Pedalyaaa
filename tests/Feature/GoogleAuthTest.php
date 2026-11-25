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
        $this->app->instance('env', 'testing');

        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'cache.default' => 'array',
            'session.driver' => 'array',
        ]);
    }

    private function fakeDriver(
        ?SocialiteUser $user = null,
        bool $fail = false,
        ?\Throwable $throw = null,
        ?string $expectedRedirectUrl = null,
    ): Provider {
        $provider = Mockery::mock(Provider::class);
        if ($expectedRedirectUrl === null) {
            $provider->shouldReceive('redirectUrl')->andReturn($provider);
        } else {
            $provider->shouldReceive('redirectUrl')->once()->with($expectedRedirectUrl)->andReturn($provider);
        }
        $parameters = [];
        $provider->shouldReceive('with')->andReturnUsing(function (array $values) use ($provider, &$parameters) {
            $parameters = array_merge($parameters, $values);

            return $provider;
        });
        $provider->shouldReceive('stateless')->andReturn($provider);
        $provider->shouldReceive('redirect')->andReturnUsing(function () use (&$parameters) {
            $parameters['state'] ??= bin2hex(random_bytes(20));

            return redirect('http://google.test/authorize?'.http_build_query($parameters));
        });

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
        $user->user = ['email_verified' => $overrides['email_verified'] ?? true];

        return $user;
    }

    private function startApiFlow(): string
    {
        $response = $this->getJson('/api/auth/google/redirect')->assertOk();
        $query = [];
        parse_str((string) parse_url($response->json('url'), PHP_URL_QUERY), $query);

        $this->assertIsString($query['state'] ?? null);
        $this->assertNotSame('', $query['state']);

        return $query['state'];
    }

    // ---- Web flow ----

    public function test_login_page_google_button_targets_the_oauth_redirect_route(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('login.google'), false)
            ->assertSee('Sign in with Google');
    }

    public function test_web_redirect_redirects_to_google(): void
    {
        config(['services.google.redirect' => 'https://pedalya.example/auth/google/callback']);
        $this->fakeDriver(expectedRedirectUrl: 'https://pedalya.example/auth/google/callback');

        $this->get(route('login.google'))->assertRedirect();
    }

    public function test_web_redirect_uses_the_current_origin_when_callback_is_relative(): void
    {
        config(['services.google.redirect' => '/auth/google/callback']);
        $this->fakeDriver(expectedRedirectUrl: 'http://localhost:8000/auth/google/callback');

        $this->get(route('login.google'))->assertRedirect();
    }

    public function test_production_google_callback_uri_must_use_https(): void
    {
        $this->app->instance('env', 'production');
        config([
            'app.env' => 'production',
            'services.google.redirect' => 'http://pedalya.example/auth/google/callback',
        ]);

        $this->get(route('login.google'))->assertStatus(500);
    }

    public function test_web_callback_new_user_goes_to_register(): void
    {
        $this->fakeDriver($this->googleUser());

        $this->get(route('login.google.callback'))
            ->assertRedirect(route('register'))
            ->assertSessionHas('pending_oauth');
    }

    public function test_web_callback_normalizes_google_email_before_registration(): void
    {
        $this->fakeDriver($this->googleUser(['email' => 'Google@Pedalya.Test']));

        $this->get(route('login.google.callback'))
            ->assertRedirect(route('register'))
            ->assertSessionHas('pending_oauth.email', 'google@pedalya.test');
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

    public function test_web_callback_matches_existing_email_case_insensitively(): void
    {
        $rider = $this->makeRider(['email' => 'Google@Pedalya.Test']);
        $this->fakeDriver($this->googleUser());

        $this->get(route('login.google.callback'))
            ->assertRedirect(route('rider.dashboard'));

        $this->assertAuthenticatedAs($rider);
        $this->assertSame('goog-123', $rider->fresh()->google_id);
    }

    public function test_web_callback_existing_admin_logs_in(): void
    {
        $admin = $this->makeAdmin(['email' => 'google@pedalya.test']);
        $this->fakeDriver($this->googleUser());

        $this->get(route('login.google.callback'))
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_google_authenticated_user_can_log_out(): void
    {
        $rider = $this->makeRider(['email' => 'google@pedalya.test']);
        $this->fakeDriver($this->googleUser());

        $this->get(route('login.google.callback'))->assertRedirect(route('rider.dashboard'));
        $this->assertAuthenticatedAs($rider);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
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

    public function test_web_callback_requires_google_to_verify_the_email_for_account_linking(): void
    {
        $rider = $this->makeRider(['email' => 'google@pedalya.test']);
        $this->fakeDriver($this->googleUser(['email_verified' => false]));

        $this->get(route('login.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertNull($rider->fresh()->google_id);
    }

    public function test_web_callback_does_not_link_an_email_to_a_different_google_identity(): void
    {
        $rider = $this->makeRider([
            'email' => 'google@pedalya.test',
            'google_id' => 'another-google-id',
        ]);
        $this->fakeDriver($this->googleUser());

        $this->get(route('login.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame('another-google-id', $rider->fresh()->google_id);
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
        $state = 'cancelled-google-state';
        $this->withSession(['state' => $state]);

        $html = $this->from(route('login'))
            ->followingRedirects()
            ->get(route('login.google.callback', ['error' => 'access_denied', 'state' => $state]))
            ->getContent();

        $this->assertStringContainsString(
            'Google sign-in was cancelled. You can try again or use email login.',
            $html,
            'a user-cancelled consent must not read as a broken sign-in'
        );
        $this->assertGuest();
    }

    public function test_web_callback_rejects_provider_errors_without_valid_oauth_state(): void
    {
        $html = $this->from(route('login'))
            ->followingRedirects()
            ->get(route('login.google.callback', [
                'error' => 'access_denied',
                'state' => 'attacker-controlled-state',
            ]))
            ->getContent();

        $this->assertStringContainsString(
            'Your Google sign-in session expired. Please try again from the login page.',
            $html
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
        config(['services.google.redirect' => 'https://pedalya.example/auth/google/callback']);
        $this->fakeDriver(expectedRedirectUrl: 'https://pedalya.example/api/auth/google/callback');

        $url = $this->getJson('/api/auth/google/redirect')->assertOk()->json('url');
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('google.test', parse_url($url, PHP_URL_HOST));
        $this->assertNotEmpty($query['state'] ?? null);
        $this->assertSame('select_account', $query['prompt'] ?? null);
    }

    public function test_api_callback_new_user_needs_registration(): void
    {
        $this->fakeDriver($this->googleUser());
        $state = $this->startApiFlow();

        $this->getJson('/api/auth/google/callback?state='.$state)
            ->assertOk()
            ->assertJson(['needs_registration' => true]);
    }

    public function test_api_callback_existing_user_returns_token(): void
    {
        $rider = $this->makeRider(['email' => 'google@pedalya.test']);
        $this->fakeDriver($this->googleUser());
        $state = $this->startApiFlow();

        $this->getJson('/api/auth/google/callback?state='.$state.'&device_name=mobile')
            ->assertOk()
            ->assertJsonStructure(['token', 'token_type' => [], 'user']);
        $this->assertDatabaseHas('users', ['id' => $rider->id, 'google_id' => 'goog-123']);
    }

    public function test_api_callback_inactive_user_403(): void
    {
        $this->makeRider(['email' => 'google@pedalya.test', 'status' => User::STATUS_INACTIVE]);
        $this->fakeDriver($this->googleUser());
        $state = $this->startApiFlow();

        $this->getJson('/api/auth/google/callback?state='.$state)->assertStatus(403);
    }

    public function test_api_callback_failure_401(): void
    {
        $this->fakeDriver(fail: true);
        $state = $this->startApiFlow();

        $this->getJson('/api/auth/google/callback?state='.$state)->assertStatus(401);
    }

    public function test_api_callback_requires_and_consumes_oauth_state(): void
    {
        $this->fakeDriver($this->googleUser());
        $state = $this->startApiFlow();

        $this->getJson('/api/auth/google/callback')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Your Google sign-in session expired. Please start again.');

        $this->getJson('/api/auth/google/callback?state='.$state)
            ->assertOk()
            ->assertJson(['needs_registration' => true]);

        $this->getJson('/api/auth/google/callback?state='.$state)
            ->assertUnauthorized();
    }
}
