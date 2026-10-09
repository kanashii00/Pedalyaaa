<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * Login feedback and the "Remember me" preference.
 *
 * A failed sign-in reports a professional, concise message through the
 * existing error styling. "Remember me" persists only the email address in
 * an encrypted cookie plus Laravel's own remember-token for the session -
 * never a password, never localStorage - restores both on return, and clears
 * any stale persistence when the box is left unchecked.
 */
class LoginRememberMeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function test_failed_login_shows_the_professional_message_in_the_glass_error_bar(): void
    {
        $html = $this->from(route('login'))
            ->followingRedirects()
            ->post(route('login'), ['email' => 'nobody@pedalya.test', 'password' => 'wrong'])
            ->getContent();

        $this->assertStringContainsString(
            'The login information you entered is incorrect. Please check your email and password and try again.',
            $html,
            'the failed-login message must be professional and concise'
        );
        $this->assertStringContainsString(
            'auth-alert-error',
            $html,
            'the glass error bar styling must be used'
        );
        $this->assertStringContainsString(
            'auth-alert-icon',
            $html,
            'the outlined icon must be rendered'
        );
        $this->assertStringContainsString(
            'btn-close-white',
            $html,
            'the dismiss button must stay visible on the dark surface'
        );
        $this->assertStringNotContainsString(
            'alert alert-danger',
            $html,
            'the old red banner styling must be gone'
        );
    }

    public function test_login_with_remember_persists_email_and_session_cookies(): void
    {
        $rider = $this->makeRider();

        $this->post(route('login'), [
            'email' => $rider->email,
            'password' => 'password',
            'remember' => '1',
        ])
            ->assertRedirect(route('rider.dashboard'))
            ->assertCookie(AuthenticatedSessionController::REMEMBER_EMAIL_COOKIE, $rider->email)
            ->assertCookie(Auth::getRecallerName());

        $this->assertAuthenticatedAs($rider);
    }

    public function test_remember_me_restores_authentication_after_a_browser_restart(): void
    {
        $rider = $this->makeRider();

        $login = $this->post(route('login'), [
            'email' => $rider->email,
            'password' => 'password',
            'remember' => '1',
        ])->assertRedirect(route('rider.dashboard'));

        $recaller = collect($login->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === Auth::getRecallerName());

        $this->assertNotNull($recaller, 'a long-lived session cookie must be issued');

        // A restarted browser sends only the long-lived cookie: no session
        // cookie is carried over, mirroring a fresh browser profile.
        $this->withUnencryptedCookie($recaller->getName(), $recaller->getValue())
            ->get(route('rider.dashboard'))
            ->assertOk();

        $this->assertAuthenticatedAs($rider);
    }

    public function test_login_without_remember_clears_any_stale_persistence(): void
    {
        $rider = $this->makeRider();

        $this->post(route('login'), [
            'email' => $rider->email,
            'password' => 'password',
        ])
            ->assertRedirect(route('rider.dashboard'))
            ->assertCookieExpired(AuthenticatedSessionController::REMEMBER_EMAIL_COOKIE)
            ->assertCookieExpired(Auth::getRecallerName());

        $this->assertAuthenticatedAs($rider);
    }

    public function test_login_page_restores_email_and_checks_the_box(): void
    {
        $rider = $this->makeRider();

        $html = $this->withCookie(
            AuthenticatedSessionController::REMEMBER_EMAIL_COOKIE,
            $rider->email
        )->get(route('login'))->getContent();

        $this->assertStringContainsString(
            'value="' . e($rider->email) . '"',
            $html,
            'the remembered email must be prefilled'
        );
        $this->assertMatchesRegularExpression(
            '~id="remember"[^>]*checked~',
            $html,
            'the box must reflect the stored preference'
        );
    }

    public function test_login_page_without_a_stored_preference_leaves_the_box_unchecked(): void
    {
        $html = $this->get(route('login'))->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '~id="remember"[^>]*checked~',
            $html,
            'no stored preference means an unchecked box'
        );
    }
}
