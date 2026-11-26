<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class RegisterPageTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    private function styleBlock(string $html): string
    {
        preg_match('/<style>(.*?)<\/style>/s', $html, $matches);

        return trim(str_replace("\r\n", "\n", $matches[1] ?? ''));
    }

    private function grab(string $html, string $pattern): string
    {
        preg_match($pattern, $html, $matches);

        return trim(str_replace("\r\n", "\n", $matches[0] ?? ''));
    }

    private function openingTags(string $html, string $pattern): array
    {
        preg_match_all($pattern, $html, $matches);

        return $matches[0] ?? [];
    }

    public function test_register_page_reuses_the_login_page_structure(): void
    {
        $login = $this->get('/login')->getContent();
        $register = $this->get('/register')->getContent();

        // Logo lockup.
        $logoImage = '/<img src="[^"]*assets\/img\/Logo\.png"[^>]*>/';
        $logoHeading = '/<h1 class="text-white"[^>]*>.*?<\/h1>/s';
        $this->assertSame($this->grab($login, $logoImage), $this->grab($register, $logoImage));
        $this->assertSame($this->grab($login, $logoHeading), $this->grab($register, $logoHeading));

        // "OR" divider.
        $divider = '/<div class="d-flex align-items-center my-4">.*?<\/div>\s*<\/div>/s';
        $this->assertSame($this->grab($login, $divider), $this->grab($register, $divider));

        // Google button: identical markup apart from its label.
        $googleButton = '/<a href="[^"]*\/auth\/google\/redirect"[^>]*class="btn w-100 btn-lg google-btn">.*?<\/a>/s';
        $this->assertSame(
            str_replace('Sign in with Google', 'Sign up with Google', $this->grab($login, $googleButton)),
            $this->grab($register, $googleButton),
        );

        // Footer paragraphs: same classes and inline typography, different wording.
        $footerTags = '/<p class="text-center[^"]*"[^>]*>/';
        $this->assertSame(
            $this->openingTags($login, $footerTags),
            $this->openingTags($register, $footerTags),
        );

        // Card width comes from the shared stylesheet, not a one-off override.
        $this->assertStringNotContainsString('max-width: 480px', $register);
    }

    public function test_register_page_uses_the_same_styles_as_the_login_page(): void
    {
        $login = $this->get('/login');
        $register = $this->get('/register');

        $login->assertOk();
        $register->assertOk();

        $this->assertNotSame('', $this->styleBlock($login->getContent()));
        $this->assertSame(
            $this->styleBlock($login->getContent()),
            $this->styleBlock($register->getContent()),
        );
    }

    public function test_register_page_matches_the_login_page_markup_conventions(): void
    {
        $html = $this->get('/register')->getContent();

        $this->assertStringContainsString('class="auth-page"', $html);
        $this->assertStringContainsString('class="auth-card"', $html);
        $this->assertStringContainsString('assets/img/Logo.png', $html);
        $this->assertStringContainsString('Peda<span style="color: var(--warning);">lya</span>', $html);
        $this->assertStringContainsString('assets/img/bg.png', $html);

        // Google button uses the shared login class instead of inline overrides.
        $this->assertStringContainsString('class="btn w-100 btn-lg google-btn"', $html);
        $this->assertStringContainsString('Sign up with Google', $html);
        $this->assertStringNotContainsString('color:var(--gray-500)', $html);
        $this->assertStringNotContainsString('rgba(0,0,0,0.1)', $html);

        // Footer links follow the login card link styling.
        $this->assertStringContainsString('class="text-center mt-4 mb-0 auth-footer-text"', $html);
        $this->assertStringContainsString('Back to Home', $html);
    }

    public function test_register_page_offers_password_and_confirmation_fields(): void
    {
        $html = $this->get('/register')->getContent();

        $this->assertStringContainsString('for="password">Create Password', $html);
        $this->assertStringContainsString('for="password_confirmation">Confirm Password', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringContainsString('name="password_confirmation"', $html);
    }

    public function test_password_toggles_are_labeled_and_keyboard_reachable(): void
    {
        $html = $this->get('/register')->assertOk()->getContent();
        preg_match_all('/<button\b[^>]*data-password-toggle="[^"]+"[^>]*>/s', $html, $matches);

        $this->assertCount(2, $matches[0]);

        foreach (['Show password', 'Show password confirmation'] as $index => $label) {
            $button = $matches[0][$index];
            $this->assertStringContainsString('aria-label="'.$label.'"', $button);
            $this->assertStringContainsString('aria-pressed="false"', $button);
            $this->assertStringNotContainsString('tabindex="-1"', $button);
        }
    }

    public function test_google_registration_page_keeps_google_details_and_asks_for_a_password(): void
    {
        $response = $this->withSession([
            'pending_oauth' => [
                'name' => 'Juan Dela Cruz',
                'email' => 'juan.delacruz@jmc.edu.ph',
                'avatar' => 'https://lh3.googleusercontent.com/a/avatar',
                'google_id' => 'google-oauth-id-123',
            ],
        ])->get('/register');

$response->assertOk();
        $response->assertSee('juan.delacruz@jmc.edu.ph');
        $response->assertSee('Juan Dela Cruz');
        $response->assertSee('Finish Creating Account');

        // Google profile values stay locked in and come from the session, not the form.
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/id="name"[^>]*value="Juan Dela Cruz"[^>]*readonly/s', $html);
        $this->assertMatchesRegularExpression('/id="email"[^>]*value="juan\.delacruz@jmc\.edu\.ph"[^>]*readonly/s', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringContainsString('name="password_confirmation"', $html);
    }

    public function test_google_registration_stores_a_hashed_password_and_links_the_google_account(): void
    {
        $response = $this->withSession([
            'pending_oauth' => [
                'name' => 'Juan Dela Cruz',
                'email' => 'juan.delacruz@jmc.edu.ph',
                'avatar' => 'https://lh3.googleusercontent.com/a/avatar',
                'google_id' => 'google-oauth-id-123',
            ],
        ])->post('/register', [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan.delacruz@jmc.edu.ph',
            'phoneNumber' => '09171234567',
            'password' => 'pedalya-secret-1',
            'password_confirmation' => 'pedalya-secret-1',
        ]);

        $response->assertRedirect(route('rider.dashboard'));
        $response->assertSessionMissing('pending_oauth');

        $user = User::where('email', 'juan.delacruz@jmc.edu.ph')->firstOrFail();

        $this->assertNotNull($user->password);
        $this->assertNotSame('pedalya-secret-1', $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check('pedalya-secret-1', $user->password));
        $this->assertSame('google-oauth-id-123', $user->google_id);
        $this->assertSame('google', $user->oauth_provider);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(User::ROLE_RIDER, $user->role);
        $this->assertAuthenticatedAs($user);
    }

    public function test_google_registration_can_sign_in_with_email_and_password_afterwards(): void
    {
        $this->withSession([
            'pending_oauth' => [
                'name' => 'Maria Santos',
                'email' => 'maria.santos@jmc.edu.ph',
                'avatar' => null,
                'google_id' => 'google-oauth-id-456',
            ],
        ])->post('/register', [
            'name' => 'Maria Santos',
            'email' => 'maria.santos@jmc.edu.ph',
            'password' => 'pedalya-secret-2',
            'password_confirmation' => 'pedalya-secret-2',
        ])->assertRedirect(route('rider.dashboard'));

        Auth::logout();
        $this->assertGuest();

        $this->post('/login', [
            'email' => 'maria.santos@jmc.edu.ph',
            'password' => 'pedalya-secret-2',
        ])->assertRedirect(route('rider.dashboard'));

        $this->assertAuthenticated();
    }

    public function test_google_registration_requires_a_password(): void
    {
        $response = $this->withSession([
            'pending_oauth' => [
                'name' => 'Maria Santos',
                'email' => 'maria.santos@jmc.edu.ph',
                'avatar' => null,
                'google_id' => 'google-oauth-id-456',
            ],
        ])->post('/register', [
            'name' => 'Maria Santos',
            'email' => 'maria.santos@jmc.edu.ph',
            'phoneNumber' => '09171234567',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'maria.santos@jmc.edu.ph']);
        $this->assertGuest();
        $response->assertSessionHas('pending_oauth');
    }

    public function test_google_registration_rejects_a_mismatched_confirmation(): void
    {
        $response = $this->withSession([
            'pending_oauth' => [
                'name' => 'Maria Santos',
                'email' => 'maria.santos@jmc.edu.ph',
                'avatar' => null,
                'google_id' => 'google-oauth-id-456',
            ],
        ])->from('/register')->post('/register', [
            'name' => 'Maria Santos',
            'email' => 'maria.santos@jmc.edu.ph',
            'password' => 'pedalya-secret-3',
            'password_confirmation' => 'pedalya-secret-4',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'maria.santos@jmc.edu.ph']);
        $this->assertGuest();
    }

    public function test_google_registration_rejects_a_password_below_the_minimum_length(): void
    {
        $response = $this->withSession([
            'pending_oauth' => [
                'name' => 'Maria Santos',
                'email' => 'maria.santos@jmc.edu.ph',
                'avatar' => null,
                'google_id' => 'google-oauth-id-456',
            ],
        ])->from('/register')->post('/register', [
            'name' => 'Maria Santos',
            'email' => 'maria.santos@jmc.edu.ph',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'maria.santos@jmc.edu.ph']);
    }

    public function test_registration_errors_never_echo_the_submitted_password(): void
    {
        $plain = Str::random(24);

        $response = $this->from('/register')->post('/register', [
            'name' => 'Password Echo',
            'email' => 'echo@jmc.edu.ph',
            'password' => $plain,
            'password_confirmation' => 'a-different-value',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertStringNotContainsString($plain, $response->getContent() ?: '');
    }

    public function test_manual_registration_still_creates_a_password_account(): void
    {
        $response = $this->post('/register', [
            'name' => 'Jose Reyes',
            'email' => 'jose.reyes@jmc.edu.ph',
            'phoneNumber' => '09171234567',
            'password' => 'pedalya-secret-5',
            'password_confirmation' => 'pedalya-secret-5',
        ]);

        $response->assertRedirect(route('rider.dashboard'));

        $user = User::where('email', 'jose.reyes@jmc.edu.ph')->firstOrFail();

        $this->assertTrue(Hash::check('pedalya-secret-5', $user->password));
        $this->assertNull($user->google_id);
        $this->assertNull($user->oauth_provider);
        $this->assertNull($user->email_verified_at);
    }

    public function test_manual_registration_requires_a_matching_password(): void
    {
        $response = $this->from('/register')->post('/register', [
            'name' => 'Jose Reyes',
            'email' => 'jose.reyes@jmc.edu.ph',
            'password' => 'pedalya-secret-6',
            'password_confirmation' => 'pedalya-secret-7',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'jose.reyes@jmc.edu.ph']);
        $this->assertGuest();
    }

    public function test_legacy_google_account_can_set_a_first_password_from_the_profile(): void
    {
        $user = $this->makeRider([
            'email' => 'legacy.google@jmc.edu.ph',
            'password' => null,
            'google_id' => 'google-oauth-id-legacy',
            'oauth_provider' => 'google',
        ]);

        $this->actingAs($user)
            ->put(route('rider.profile.update-password'), [
                'password' => 'first-pedalya-pass',
                'password_confirmation' => 'first-pedalya-pass',
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertTrue(Hash::check('first-pedalya-pass', $user->password));

        $this->post('/logout');

        $this->post('/login', [
            'email' => 'legacy.google@jmc.edu.ph',
            'password' => 'first-pedalya-pass',
        ])->assertRedirect(route('rider.dashboard'));
    }

    public function test_account_with_a_password_still_requires_the_current_one(): void
    {
        $user = $this->makeRider([
            'email' => 'has.password@jmc.edu.ph',
            'password' => 'existing-pass-1',
        ]);

        $this->actingAs($user)
            ->put(route('rider.profile.update-password'), [
                'password' => 'replacement-pass-1',
                'password_confirmation' => 'replacement-pass-1',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('existing-pass-1', $user->refresh()->password));
    }

    public function test_profile_password_form_explains_the_first_password_for_google_accounts(): void
    {
        $this->actingAs($this->makeRider(['password' => null]))
            ->get(route('rider.profile.index'))
            ->assertOk()
            ->assertSee("don't have a Pedalya password yet", false);

        $this->actingAs($this->makeRider(['password' => 'has-a-password']))
            ->get(route('rider.profile.index'))
            ->assertOk()
            ->assertDontSee("don't have a Pedalya password yet", false);
    }
}