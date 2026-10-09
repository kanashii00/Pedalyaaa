<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Name of the long-lived email cookie behind "Remember me".
     *
     * It carries only the email address, is encrypted and signed by the
     * EncryptCookies middleware, and is never readable from JavaScript
     * storage. No password (plaintext or otherwise) is ever persisted.
     */
    public const REMEMBER_EMAIL_COOKIE = 'remember_email';

    /** Email cookie lifetime: one year, in minutes. */
    public const REMEMBER_EMAIL_LIFETIME = 525600;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');

        if (!Auth::attempt($request->only('email', 'password'), $remember)) {
            $response = back()
                ->withInput($request->only('email', 'remember'))
                ->withErrors([
                    'email' => 'The login information you entered is incorrect. Please check your email and password and try again.',
                ]);

            // Unchecked on a failed attempt means "do not persist": drop a
            // stale email cookie now rather than restoring it on redisplay.
            if (!$remember) {
                $response->withCookie(Cookie::forget(self::REMEMBER_EMAIL_COOKIE));
            }

            return $response;
        }

        $request->session()->regenerate();

        $user = $request->user();

        if (!$user || $user->status !== User::STATUS_ACTIVE) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Your account is inactive. Please contact the administrator.',
            ]);
        }

        $response = $this->redirectForRole($user);

        if ($remember) {
            // Persist only the email for prefilling the login form. Session
            // survival across browser restarts is handled separately and
            // securely by Auth's remember-token (recaller) cookie.
            $response->withCookie(cookie(
                self::REMEMBER_EMAIL_COOKIE,
                $user->email,
                self::REMEMBER_EMAIL_LIFETIME
            ));
        } else {
            // Explicitly clear any stale persistence: a previous remember-me
            // choice must not survive a login made without it.
            $response->withCookie(Cookie::forget(self::REMEMBER_EMAIL_COOKIE));
            $response->withCookie(Cookie::forget(Auth::getRecallerName()));
        }

        return $response;
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function redirectForRole(User $user): RedirectResponse
    {
        if ($user->isAdmin()) {
            return redirect()->intended(route('admin.dashboard'));
        }

        return redirect()->intended(route('rider.dashboard'));
    }
}
