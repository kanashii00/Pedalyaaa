<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register', [
            'pendingOauth' => session('pending_oauth'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $pendingOauth = $request->session()->get('pending_oauth');

        // Normalise whitespace before validation so "  Juan   Dela Cruz  "
        // becomes "Juan Dela Cruz" and a phone like " 09171234567 " is trimmed.
        // Interior phone spacing is intentionally NOT stripped: only pure
        // digits are valid, so "0917 123 4567" must fail validation.
        $request->merge([
            'name' => preg_replace('/\s+/', ' ', trim((string) $request->input('name', ''))),
            'email' => trim((string) $request->input('email', '')),
            'phoneNumber' => trim((string) $request->input('phoneNumber', '')) === ''
                ? null
                : trim((string) $request->input('phoneNumber', '')),
        ]);

        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:100', 'regex:/^(?=.*\pL)[\pL\pM\s.\'’\-]+$/u'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class, 'email'),
            ],
            'phoneNumber' => ['nullable', 'string', 'min:7', 'max:15', 'regex:/^[0-9]+$/'],
            // Google sign-ups also set a Pedalya password so the rider can later
            // sign in either with Google or with email + password. The value is
            // hashed below and never stored in plain text.
            'password' => ['required', 'string', 'min:8', 'confirmed', Password::defaults()],
        ];

        $messages = [
            'name.regex' => 'Please enter a valid full name.',
            'name.min' => 'Please enter a valid full name.',
            'name.max' => 'Please enter a valid full name.',
            'email.email' => 'Please enter a valid email address.',
            'phoneNumber.regex' => 'Please enter a valid phone number.',
            'phoneNumber.min' => 'Please enter a valid phone number.',
            'phoneNumber.max' => 'Please enter a valid phone number.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Passwords do not match.',
        ];

        $request->validate($rules, $messages);

        $user = User::create([
            'name' => $request->string('name')->value(),
            'email' => $request->string('email')->value(),
            'phoneNumber' => $request->input('phoneNumber'),
            'password' => Hash::make($request->string('password')->value()),
            'google_id' => $pendingOauth['google_id'] ?? null,
            'avatar' => $pendingOauth['avatar'] ?? null,
            'oauth_provider' => $pendingOauth ? 'google' : null,
            'email_verified_at' => $pendingOauth ? now() : null,
            'role' => User::ROLE_RIDER,
            'status' => User::STATUS_ACTIVE,
            'verified' => false,
            'idUploaded' => false,
            'totalRentals' => 0,
            'totalSpent' => 0,
        ]);

        if ($pendingOauth) {
            $request->session()->forget('pending_oauth');
        }

        event(new Registered($user));

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->route('rider.dashboard');
    }
}
