<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * End-to-end Google sign-in for a brand-new renter.
 *
 * Callback -> renter fill-up form (prefilled from Google, account NOT
 * created yet) -> required renter details submitted -> account created with
 * the Google link -> working dashboard. Guards the whole chain, including
 * long Google avatar URLs that once aborted sign-in after a successful
 * OAuth exchange.
 */
class GoogleRenterJourneyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env', 'testing');

        config([
            'cache.default' => 'array',
            'session.driver' => 'array',
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
        ]);
    }

    public function test_full_new_renter_journey(): void
    {
        $googleUser = new SocialiteUser();
        $googleUser->id = '105412345678901234567';
        $googleUser->email = 'NewRenter@Gmail.com';
        $googleUser->name = 'New Renter';
        $googleUser->nickname = null;
        $googleUser->avatar = 'https://lh3.googleusercontent.com/a/' . str_repeat('y', 400) . '=s96-c';
        $googleUser->user = ['email_verified' => true];

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('redirectUrl')->andReturn($provider);
        $provider->shouldReceive('with')->andReturn($provider);
        $provider->shouldReceive('user')->andReturn($googleUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        // 1. Callback -> register with pending oauth.
        $this->get(route('login.google.callback'))
            ->assertRedirect(route('register'))
            ->assertSessionHas('pending_oauth');

        // 2. Register page renders the fill-up form with Google data.
        $html = $this->get(route('register'))->getContent();
        $this->assertStringContainsString('Finish Creating Account', $html);
        $this->assertStringContainsString('newrenter@gmail.com', $html);

        // 3. Submit the required renter details, then land on a working dashboard.
        $html = $this->followingRedirects()->post(route('register'), [
            'name' => 'New Renter',
            'email' => 'newrenter@gmail.com',
            'phoneNumber' => '09171234567',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->getContent();

        $this->assertStringNotContainsString('Whoops, something went wrong', $html);
        $this->assertStringNotContainsString('Server Error', $html);

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'newrenter@gmail.com',
            'google_id' => '105412345678901234567',
            'role' => 'rider',
        ]);
    }
}
