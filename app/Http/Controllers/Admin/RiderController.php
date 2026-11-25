<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RiderController extends Controller
{
    public function index(Request $request): Response
    {
        $query = User::where('role', 'rider');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('verified')) {
            $query->where('verified', $request->boolean('verified'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phoneNumber', 'like', "%{$search}%");
            });
        }

        $riders = $query->latest()->paginate(20);

        return response()->view('admin.riders', compact('riders'));
    }

    public function create(): View
    {
        return view('admin.riders.create');
    }

    public function idDocument(int $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $rider = User::where('role', User::ROLE_RIDER)->findOrFail($id);
        $verification = $rider->idVerification ?? [];
        $path = $verification['id_path'] ?? null;

        if (!is_string($path) || !str_starts_with($path, 'id-verifications/') || str_contains($path, '..')) {
            abort(404);
        }

        $disk = $verification['id_disk'] ?? 'public';
        if (!in_array($disk, ['public', 'id_verifications', 'id_verifications_s3'], true)) {
            abort(404);
        }

        $storage = Storage::disk($disk);
        abort_unless($storage->exists($path), 404);

        return $storage->response($path, null, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'name' => preg_replace('/\s+/', ' ', trim((string) $request->input('name', ''))),
            'phoneNumber' => trim((string) $request->input('phoneNumber', '')) === ''
                ? null
                : trim((string) $request->input('phoneNumber', '')),
        ]);

        $validated = $request->validate([
            'name'        => ['required', 'string', 'min:2', 'max:100', 'regex:/^(?=.*\pL)[\pL\pM\s.\'’\-]+$/u'],
            'studentId'   => ['nullable', 'string', 'max:50'],
            'email'       => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                \Illuminate\Validation\Rule::unique(User::class, 'email'),
            ],
            'phoneNumber' => ['nullable', 'string', 'min:7', 'max:15', 'regex:/^[0-9]+$/'],
            'address'     => ['nullable', 'string', 'max:500'],
            'id_image'    => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'password'    => ['required', 'string', 'min:8', 'confirmed', Password::defaults()],
        ], [
            'name.regex' => 'Please enter a valid full name.',
            'name.min' => 'Please enter a valid full name.',
            'name.max' => 'Please enter a valid full name.',
            'email.email' => 'Please enter a valid email address.',
            'phoneNumber.regex' => 'Please enter a valid phone number.',
            'phoneNumber.min' => 'Please enter a valid phone number.',
            'phoneNumber.max' => 'Please enter a valid phone number.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Passwords do not match.',
        ]);

        // The password hash is deliberately never included in audit details
        // (and the central scrubber would redact it regardless).
        $rider = User::create([
            'name'        => $validated['name'],
            'studentId'   => $validated['studentId'] ?? null,
            'email'       => $validated['email'],
            'phoneNumber' => $validated['phoneNumber'] ?? null,
            'address'     => $validated['address'] ?? null,
            'password'    => Hash::make($validated['password']),
            'role'        => User::ROLE_RIDER,
            'status'      => User::STATUS_ACTIVE,
            'verified'    => false,
            'idUploaded'  => false,
            'totalRentals'=> 0,
            'totalSpent'  => 0,
        ]);

        // An optional ID photo follows the same storage path as rider
        // self-uploads (DocumentUploadService): validated image, unique
        // random filename (never overwritten), reference saved on the record.
        $idUploaded = false;
        if ($request->hasFile('id_image')) {
            try {
                $stored = app(\App\Services\DocumentUploadService::class)
                    ->storeIdVerification($request, $rider->id);
            } catch (\Throwable $e) {
                report($e);
                $rider->delete();

                return back()
                    ->withInput($request->except('id_image'))
                    ->withErrors(['id_image' => 'The ID photo could not be saved. Please try again.']);
            }

            if ($stored === null) {
                $rider->delete();

                return back()
                    ->withInput($request->except('id_image'))
                    ->withErrors(['id_image' => 'The provided document is not a valid JPG, JPEG, PNG or WEBP image.']);
            }

            $rider->update([
                'idVerification' => [
                    'id_path' => $stored['id_path'],
                    'id_disk' => $stored['id_disk'],
                    'status' => 'pending',
                    'submitted_at' => now()->toIso8601String(),
                    'submitted_by' => 'admin:' . auth()->id(),
                ],
                'idUploaded' => true,
            ]);
            $idUploaded = true;
        }

        AuditLog::record('rider_created', auth()->id(), [
            'riderId' => $rider->id,
            'email' => $validated['email'],
            'idUploaded' => $idUploaded,
            'outcome' => 'success',
        ]);

        return redirect()->route('admin.riders.index')
            ->with('success', 'Customer "' . $validated['name'] . '" registered successfully.');
    }

    public function verify(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $rider = User::where('role', 'rider')->findOrFail($id);

        $validated = $request->validate([
            'approved' => ['required', 'boolean'],
            'reason'   => ['nullable', 'string', 'max:500'],
        ]);

        $previousVerified = (bool) $rider->verified;
        $previousIdStatus = $rider->idVerification['status'] ?? 'pending';

       $idVerification = $rider->idVerification ?? [];

$idVerification = array_merge($idVerification, [
    'status' => $validated['approved']
        ? 'approved'
        : 'rejected',
    'approved' => $validated['approved'],
    'reason' => $validated['reason'] ?? null,
    'verified_at' => now()->toISOString(),
]);

$rider->update([
    'verified' => $validated['approved'],
    'idVerification' => $idVerification,
]);

$notificationService = app(\App\Services\NotificationService::class);

if ($validated['approved']) {
    $notificationService->create(
        $rider->id,
        'Identity Verification Approved',
        'Your identity verification has been approved. You can now start renting bicycles.',
        'verification_approved'
    );
} else {
    $message = 'Your identity verification was rejected. Please upload another valid ID.';

    if (!empty($validated['reason'])) {
        $message .= ' Reason: ' . $validated['reason'];
    }

    $notificationService->create(
        $rider->id,
        'Identity Verification Rejected',
        $message,
        'verification_rejected'
    );
}

        AuditLog::record('rider_verification_' . ($validated['approved'] ? 'approved' : 'rejected'), auth()->id(), [
            'riderId' => $rider->id,
            'email' => $rider->email,
            'outcome' => 'success',
            'changes' => [
                'verified' => ['from' => $previousVerified, 'to' => (bool) $validated['approved']],
                'id_status' => ['from' => $previousIdStatus, 'to' => $idVerification['status']],
            ],
        ]);

        // Both styled dialogs (approve and reject) submit over fetch so they can
        // stay open until the write has landed; plain form posts keep the
        // redirect-and-reload flow they always had.
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'verified' => (bool) $rider->verified,
                'status' => $idVerification['status'],
                'message' => 'Rider verification updated.',
            ]);
        }

        return back()->with('success', 'Rider verification updated.');
    }

    public function updateStatus(Request $request, int $id): RedirectResponse
    {
        $rider = User::where('role', 'rider')->findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,inactive,suspended,blacklisted'],
        ]);

        if ($validated['status'] !== 'active' &&
            $rider->rentals()->whereIn('status', ['active', 'pending'])->exists()) {
            throw ValidationException::withMessages([
                'status' => 'Cannot change status of rider with active or pending rentals.',
            ]);
        }

        $previousStatus = $rider->status;
        $previousReason = $rider->blacklistReason;

        $updates = ['status' => $validated['status']];

        if ($validated['status'] === 'active') {
            $updates['blacklistReason'] = null;
        }

        $rider->update($updates);

        AuditLog::record('rider_status_updated', auth()->id(), [
            'riderId' => $rider->id,
            'email' => $rider->email,
            'outcome' => 'success',
            'changes' => [
                'status' => ['from' => $previousStatus, 'to' => $validated['status']],
                'blacklistReason' => [
                    'from' => $previousReason,
                    'to' => $validated['status'] === 'active' ? null : $previousReason,
                ],
            ],
        ]);

        return back()->with('success', 'Rider status updated.');
    }

    public function verified(Request $request): Response
    {
        $query = User::where('role', 'rider')
            ->where('verified', true)
            ->where('status', 'active');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phoneNumber', 'like', "%{$search}%")
                  ->orWhere('studentId', 'like', "%{$search}%");
            });
        }

        $riders = $query->latest()->paginate(20);

        return response()->view('admin.verified-customers', compact('riders'));
    }

    public function blacklisted(Request $request): Response
    {
        $query = User::where('role', 'rider')
            ->whereIn('status', ['inactive', 'suspended', 'blacklisted']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phoneNumber', 'like', "%{$search}%")
                  ->orWhere('studentId', 'like', "%{$search}%");
            });
        }

        $riders = $query->latest()->paginate(20);

        return response()->view('admin.blacklisted-customers', compact('riders'));
    }

    public function updateBlacklist(Request $request, int $id): RedirectResponse
    {
        $rider = User::where('role', 'rider')->findOrFail($id);

        $validated = $request->validate([
            'name'            => ['required', 'string', 'max:255'],
            'studentId'       => ['nullable', 'string', 'max:50'],
            'email'           => ['required', 'string', 'lowercase', 'email', 'max:255',
                \Illuminate\Validation\Rule::unique(User::class, 'email')->ignore($rider->id),
            ],
            'phoneNumber'     => ['nullable', 'string', 'max:20'],
            'address'         => ['nullable', 'string', 'max:500'],
            'status'          => ['required', 'string', 'in:active,inactive,suspended,blacklisted'],
            'blacklistReason' => ['nullable', 'string', 'max:1000'],
        ]);

        $before = $rider->only(['name', 'studentId', 'email', 'phoneNumber', 'address', 'status', 'blacklistReason']);

        $rider->update([
            'name'            => $validated['name'],
            'studentId'       => $validated['studentId'] ?? null,
            'email'           => $validated['email'],
            'phoneNumber'     => $validated['phoneNumber'] ?? null,
            'address'         => $validated['address'] ?? null,
            'status'          => $validated['status'],
            'blacklistReason' => $validated['status'] === 'active' ? null : ($validated['blacklistReason'] ?? null),
        ]);

        AuditLog::record('rider_blacklist_updated', auth()->id(), [
            'riderId' => $rider->id,
            'status'  => $validated['status'],
            'outcome' => 'success',
            'changes' => AuditLogger::changes($before, $rider->fresh()->only([
                'name', 'studentId', 'email', 'phoneNumber', 'address', 'status', 'blacklistReason',
            ])),
        ]);

        return back()->with('success', 'Customer profile updated.');
    }
}
