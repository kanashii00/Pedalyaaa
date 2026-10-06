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

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class, 'email'),
            ],
            'phoneNumber' => ['nullable', 'string', 'max:20'],
            // Google sign-ups also set a Pedalya password so the rider can later
            // sign in either with Google or with email + password. The value is
            // hashed below and never stored in plain text.
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];

        $request->validate($rules);

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
