<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

class GoogleAuthController extends Controller
{
    private const API_STATE_CACHE_PREFIX = 'google-oauth:api-state:';

    private const API_STATE_TTL_MINUTES = 10;

    /**
     * Specific user-facing reason for the last failed Google exchange, if the
     * failure maps to one. Null means "use the generic message".
     */
    private ?string $lastGoogleError = null;

    private function driver(string $redirectUrl, bool $stateless = false)
    {
        $driver = Socialite::driver('google')
            ->redirectUrl($redirectUrl);

        return $stateless ? $driver->stateless() : $driver;
    }

    /**
     * Web (session) flow - start.
     */
    public function redirectToProvider(Request $request): RedirectResponse
    {
        $this->guardCredentials();

        return $this->driver($this->webCallbackUrl($request))
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    /**
     * Web (session) flow - callback.
     */
    public function handleProviderCallback(Request $request): RedirectResponse
    {
        // Google redirects back with ?error=... when the user cancels or
        // denies consent. That is a decision, not a failure: say so instead
        // of reporting a broken sign-in.
        if ($request->filled('error')) {
            if (! $this->consumeWebOAuthState($request)) {
                return redirect()->route('login')->withErrors([
                    'email' => 'Your Google sign-in session expired. Please try again from the login page.',
                ]);
            }

            return redirect()->route('login')->withErrors([
                'email' => $request->input('error') === 'access_denied'
                    ? 'Google sign-in was cancelled. You can try again or use email login.'
                    : 'Google sign-in did not complete. You can try again or use email login.',
            ]);
        }

        $googleUser = $this->resolveGoogleUser($this->webCallbackUrl($request));

        if (! $googleUser) {
            return redirect()->route('login')->withErrors([
                'email' => $this->lastGoogleError
                    ?? 'Google sign-in failed. Please try again or use email login.',
            ]);
        }

        $user = $this->findUser($googleUser);

        if ($this->lastGoogleError !== null) {
            return redirect()->route('login')->withErrors([
                'email' => $this->lastGoogleError,
            ]);
        }

        return $this->loginResponse($request, $googleUser, $user);
    }

    private function loginResponse(Request $request, SocialiteUser $googleUser, ?User $user): RedirectResponse
    {
        if ($user === null) {
            $request->session()->put('pending_oauth', [
                'name' => $googleUser->getName() ?? $googleUser->getNickname() ?? 'Google User',
                'email' => strtolower(trim((string) $googleUser->getEmail())),
                'avatar' => $googleUser->getAvatar(),
                'google_id' => $googleUser->getId(),
            ]);

            return redirect()->route('register')
                ->with('status', 'We found your Google account. Please complete your registration to continue.');
        }

        if (! $user->isActive()) {
            return redirect()->route('login')->withErrors([
                'email' => 'Your account is inactive. Please contact the administrator.',
            ]);
        }

        $this->linkGoogleProfile($user, $googleUser);

        Auth::login($user, true);

        $request->session()->regenerate();

        $this->recordLogin($user);

        AuditLogger::record(AuditLogger::LOGIN_SUCCESS, [
            'email' => $user->email,
            'role' => $user->role,
            'provider' => 'google',
            'outcome' => 'success',
        ], $user->id);

        return $user->isAdmin()
            ? redirect()->intended(route('admin.dashboard'))
            : redirect()->intended(route('rider.dashboard'));
    }

    private function findUser(SocialiteUser $googleUser): ?User
    {
        $googleId = $googleUser->getId();
        if (! is_string($googleId) || trim($googleId) === '') {
            $this->lastGoogleError = 'Google did not return a valid account identifier. Please try again.';

            return null;
        }

        $linkedUser = User::where('google_id', $googleId)->first();
        if ($linkedUser) {
            return $linkedUser;
        }

        $email = $googleUser->getEmail();
        if (! is_string($email) || trim($email) === '' || ! $this->hasVerifiedGoogleEmail($googleUser)) {
            $this->lastGoogleError = 'Google must provide a verified email address to continue.';

            return null;
        }

        $user = User::whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->first();
        if ($user && $user->google_id !== null
            && ! hash_equals((string) $user->google_id, $googleId)) {
            $this->lastGoogleError = 'This email is linked to a different Google account. Sign in with that account or contact support.';

            return null;
        }

        return $user;
    }

    /**
     * API (mobile / Sanctum token) flow - start.
     * Returns the Google authorization URL for the client to open in a browser.
     */
    public function apiRedirect(Request $request): JsonResponse
    {
        $this->guardCredentials();

        $state = Str::random(40);
        if (! Cache::add($this->apiStateCacheKey($state), true, now()->addMinutes(self::API_STATE_TTL_MINUTES))) {
            Log::error('Google API sign-in could not establish a unique OAuth state.');

            abort(500, 'Google sign-in could not be started securely. Please try again.');
        }

        $response = $this->driver($this->apiCallbackUrl($request), true)
            ->with([
                'prompt' => 'select_account',
                'state' => $state,
            ])
            ->redirect();
        $url = $response->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    /**
     * API (mobile / Sanctum token) flow - callback.
     * Returns a bearer token for existing users, or a registration payload for new users.
     */
    public function apiCallback(Request $request): JsonResponse
    {
        $state = $request->query('state');
        if (! is_string($state) || $state === '' || Cache::pull($this->apiStateCacheKey($state)) !== true) {
            return response()->json([
                'message' => 'Your Google sign-in session expired. Please start again.',
            ], 401);
        }

        if ($request->filled('error')) {
            return response()->json([
                'message' => $request->input('error') === 'access_denied'
                    ? 'Google sign-in was cancelled.'
                    : 'Google sign-in did not complete.',
            ], 401);
        }

        $googleUser = $this->resolveGoogleUser($this->apiCallbackUrl($request), true);

        if (! $googleUser) {
            return response()->json([
                'message' => 'Google authentication failed.',
            ], 401);
        }

        $user = $this->findUser($googleUser);

        if ($this->lastGoogleError !== null) {
            return response()->json([
                'message' => $this->lastGoogleError,
            ], 409);
        }

        return $this->apiLoginResponse($request, $googleUser, $user);
    }

    private function apiLoginResponse(Request $request, SocialiteUser $googleUser, ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->registrationPayload($googleUser);
        }

        if (! $user->isActive()) {
            return response()->json([
                'message' => 'Your account is not active.',
            ], 403);
        }

        $this->linkGoogleProfile($user, $googleUser);
        $this->recordLogin($user);

        $token = $user->createToken($request->input('device_name', 'google-oauth'))->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ]);
    }

    private function registrationPayload(SocialiteUser $googleUser): JsonResponse
    {
        // New Google user: tell the mobile app to send them to registration.
        return response()->json([
            'needs_registration' => true,
            'message' => 'No account exists. Please complete registration.',
            'provider' => 'google',
            'profile' => [
                'name' => $googleUser->getName() ?? $googleUser->getNickname() ?? 'Google User',
                'email' => strtolower(trim((string) $googleUser->getEmail())),
                'avatar' => $googleUser->getAvatar(),
                'google_id' => $googleUser->getId(),
            ],
        ]);
    }

    /**
     * Link an existing account to the Google identity and refresh avatar.
     */
    private function linkGoogleProfile(User $user, SocialiteUser $googleUser): void
    {
        $attributes = [
            'google_id' => $user->google_id ?? $googleUser->getId(),
            'avatar' => $user->avatar ?? $googleUser->getAvatar(),
            'oauth_provider' => $user->oauth_provider ?? 'google',
        ];

        if ($user->email_verified_at === null && $this->hasVerifiedGoogleEmail($googleUser)) {
            $attributes['email_verified_at'] = now();
        }

        $user->update($attributes);
    }

    private function resolveGoogleUser(string $redirectUrl, bool $stateless = false): ?SocialiteUser
    {
        try {
            $this->lastGoogleError = null;

            return $this->driver($redirectUrl, $stateless)->user();
        } catch (InvalidStateException $e) {
            Log::warning('Google sign-in failed: OAuth state mismatch.', [
                'exception' => $e::class,
            ]);

            // The session holding the OAuth state did not survive the round
            // trip: expired session, blocked cookies, or the login was
            // started on one host (localhost / 127.0.0.1 / tunnel URL) and
            // the callback returned to another. A fresh attempt on a single
            // host rebuilds the state.
            $this->lastGoogleError = 'Your Google sign-in session expired. Please try again from the login page.';

            return null;
        } catch (\Throwable $e) {
            // Token-exchange failures (expired code, revoked grant, network
            // fault). Only the exception class is logged so provider details,
            // which can contain sensitive response data, do not reach logs.
            Log::warning('Google sign-in failed during token exchange.', [
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    private function recordLogin(User $user): void
    {
        $user->update(['last_login_at' => now()]);
    }

    private function webCallbackUrl(Request $request): string
    {
        $redirect = config('services.google.redirect');

        if (! is_string($redirect) || trim($redirect) === '' || $redirect === '/auth/google/callback') {
            $root = $request->root();
            if (app()->environment('production') && ! str_starts_with($root, 'https://')) {
                abort(500, 'Google sign-in requires an HTTPS application URL in production.');
            }

            return $root.'/auth/google/callback';
        }

        $parts = parse_url($redirect);
        if ($parts === false
            || filter_var($redirect, FILTER_VALIDATE_URL) === false
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || empty($parts['host'])
            || ($parts['path'] ?? '') !== '/auth/google/callback'
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || (app()->environment('production') && ($parts['scheme'] ?? null) !== 'https')) {
            abort(
                500,
                app()->environment('production')
                    ? 'GOOGLE_REDIRECT_URI must be an absolute HTTPS URL ending in /auth/google/callback.'
                    : 'GOOGLE_REDIRECT_URI must be an absolute HTTP(S) URL ending in /auth/google/callback.'
            );
        }

        return $redirect;
    }

    private function apiCallbackUrl(Request $request): string
    {
        $parts = parse_url($this->webCallbackUrl($request));
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $parts['scheme'].'://'.$parts['host'].$port.'/api/auth/google/callback';
    }

    private function consumeWebOAuthState(Request $request): bool
    {
        $expectedState = $request->session()->pull('state');
        $receivedState = $request->query('state');

        return is_string($expectedState)
            && $expectedState !== ''
            && is_string($receivedState)
            && hash_equals($expectedState, $receivedState);
    }

    private function apiStateCacheKey(string $state): string
    {
        return self::API_STATE_CACHE_PREFIX.hash('sha256', $state);
    }

    private function hasVerifiedGoogleEmail(SocialiteUser $googleUser): bool
    {
        $rawUser = $googleUser->user;

        return is_array($rawUser)
            && (($rawUser['email_verified'] ?? $rawUser['verified_email'] ?? false) === true);
    }

    private function guardCredentials(): void
    {
        abort_unless(
            config('services.google.client_id') && config('services.google.client_secret'),
            500,
            'Google OAuth is not configured. Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET.'
        );
    }
}
