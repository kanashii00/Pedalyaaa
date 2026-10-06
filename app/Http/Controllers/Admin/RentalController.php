<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Bicycle;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\IoTService;
use App\Services\NotificationService;
use App\Services\RentalService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class RentalController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
        private IoTService $iotService,
        private RentalService $rentalService
    ) {}

    public function index(Request $request): Response
    {
        // The queue is the shared "active rentals" scope so this listing, its
        // record count and the sidebar badge can never disagree.
        $query = Rental::with(['bicycle', 'rider'])->activeList();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to'));
        }

        if ($request->filled('bicycle_id')) {
            $query->where('bicycleId', $request->input('bicycle_id'));
        }

        if ($request->filled('rider_id')) {
            $query->where('riderId', $request->input('rider_id'));
        }

        $rentals = $query->latest()->paginate(20);

        $bicyclesList = Bicycle::orderBy('name')->get();
        $ridersList = User::where('role', User::ROLE_RIDER)->orderBy('name')->get();

        // Unfiltered size of the queue. With no filters applied this is exactly
        // $rentals->total(), and it is the same number the sidebar badge shows.
        $activeRentalCount = Rental::activeListCount();

        return response()->view('admin.rentals', compact('rentals', 'bicyclesList', 'ridersList', 'activeRentalCount'));
    }

    public function history(Request $request): Response
    {
        // A settled rental (status `returned`) must appear in history rather
        // than in Active Rentals, so the two views partition cleanly on status.
        $query = Rental::with(['bicycle', 'rider'])
            ->whereIn('status', [
                Rental::STATUS_COMPLETED,
                Rental::STATUS_CANCELLED,
                Rental::STATUS_RETURNED,
                Rental::STATUS_EXPIRED,
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to'));
        }

        if ($request->filled('bicycle_id')) {
            $query->where('bicycleId', $request->input('bicycle_id'));
        }

        if ($request->filled('rider_id')) {
            $query->where('riderId', $request->input('rider_id'));
        }

        $rentals = $query->latest()->paginate(20);

        $bicyclesList = Bicycle::orderBy('name')->get();
        $ridersList = User::where('role', User::ROLE_RIDER)->orderBy('name')->get();

        return response()->view('admin.rentals-history', compact('rentals', 'bicyclesList', 'ridersList'));
    }

    public function returns(Request $request): Response
    {
        // "pending" = awaiting admin Process Return; "processed" = confirmed returns.
        $view = $request->query('view', 'pending');
        $view = in_array($view, ['pending', 'processed'], true) ? $view : 'pending';

        $query = Rental::with(['bicycle', 'rider']);

        if ($view === 'pending') {
            $query->whereIn('status', [
                Rental::STATUS_AWAITING_RETURN,
                Rental::STATUS_OVERDUE_RETURN,
            ]);
        } else {
            $query->whereIn('status', [Rental::STATUS_RETURNED, Rental::STATUS_COMPLETED]);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to'));
        }

        if ($request->filled('bicycle_id')) {
            $query->where('bicycleId', $request->input('bicycle_id'));
        }

        if ($request->filled('rider_id')) {
            $query->where('riderId', $request->input('rider_id'));
        }

        $rentals = $query->latest()->paginate(20);

        $bicyclesList = Bicycle::orderBy('name')->get();
        $ridersList = User::where('role', User::ROLE_RIDER)->orderBy('name')->get();

        $overdueStats = [];
        if ($view === 'pending') {
            foreach ($rentals as $rental) {
                if ($rental->status === Rental::STATUS_OVERDUE_RETURN && $rental->returnDeadline !== null) {
                    $overdueStats[$rental->id] = [
                        'durationMinutes' => abs((int) now()->diffInMinutes($rental->returnDeadline)),
                        'currentPenalty' => $this->rentalService->calculatePenaltyForRental($rental, now()),
                    ];
                }
            }
        }

        $summary = [
            'pending' => Rental::whereIn('status', [
                Rental::STATUS_AWAITING_RETURN,
                Rental::STATUS_OVERDUE_RETURN,
            ])->count(),
            'overdueReturn' => Rental::where('status', Rental::STATUS_OVERDUE_RETURN)->count(),
            'processed' => Rental::whereIn('status', [Rental::STATUS_RETURNED, Rental::STATUS_COMPLETED])->count(),
            'totalFee' => (float) Rental::where('status', Rental::STATUS_RETURNED)->sum('finalFee'),
            'today' => Rental::whereIn('status', [Rental::STATUS_RETURNED, Rental::STATUS_COMPLETED])
                ->whereDate('returnProcessedAt', today())
                ->count(),
            'penaltyRate' => (float) \App\Models\SystemSetting::getValue('lateReturnPenaltyPerHour', 20),
        ];

        return response()->view(
            'admin.rentals-returns',
            compact('rentals', 'bicyclesList', 'ridersList', 'summary', 'view', 'overdueStats')
        );
    }

    public function show(int $id): Response
    {
        $rental = Rental::with(['bicycle', 'rider'])->findOrFail($id);

        return response()->view('admin.rentals.show', compact('rental'));
    }

    public function approve(int $id): RedirectResponse
    {
        $rental = Rental::with(['bicycle', 'rider'])->findOrFail($id);

        if ($rental->status !== Rental::STATUS_PENDING) {
            return back()->withErrors(['rental' => 'Only pending rentals can be approved.']);
        }

        $bicycle = $rental->bicycle;

        $rental->update([
            'status' => Rental::STATUS_ACTIVE,
            'approvedBy' => auth()->id(),
            'approvedAt' => now(),
        ]);

        if ($bicycle) {
            // Rental approved: bicycle becomes Rented and the smart lock is
            // Unlocked because the rider is now authorized to use it.
            $bicycle->update([
                'status' => Bicycle::STATUS_RENTED,
                'currentRider' => $rental->riderId,
                'currentRentalId' => $rental->id,
                'lockStatus' => Bicycle::LOCK_UNLOCKED,
            ]);

            $this->iotService->sendCommand($bicycle->id, 'unlock', [], auth()->user());
        }

        AuditLog::record('rental_approved', auth()->id(), [
            'rentalId' => $rental->rentalId,
            'previous_status' => Rental::STATUS_PENDING,
            'new_status' => Rental::STATUS_ACTIVE,
        ]);

        $this->notificationService->create(
            $rental->riderId,
            'Rental Approved',
            "Your rental for bicycle {$rental->bicycleName} has been approved. Enjoy your ride!",
            'rental_status',
            ['rentalId' => $rental->rentalId]
        );

        return back()->with('success', 'Rental approved successfully.');
    }

    public function verifyGcashPayment(int $id): RedirectResponse
    {
        $rental = Rental::with(['bicycle', 'rider'])->findOrFail($id);

        if ($rental->status !== 'pending' || $rental->paymentMethod !== 'gcash') {
            return back()->withErrors(['rental' => 'This rental is not a pending GCash payment.']);
        }

        $bicycle = $rental->bicycle;

        $rental->update([
            'status' => 'active',
            'paymentStatus' => 'paid',
            'paidAt' => now(),
            'approvedBy' => auth()->id(),
            'approvedAt' => now(),
        ]);

        if ($bicycle) {
            // Payment verified: bicycle becomes Rented and the smart lock is
            // Unlocked because the rider is now authorized to use it.
            $bicycle->update([
                'status' => Bicycle::STATUS_RENTED,
                'currentRider' => $rental->riderId,
                'currentRentalId' => $rental->id,
                'lockStatus' => Bicycle::LOCK_UNLOCKED,
            ]);

            $this->iotService->sendCommand($bicycle->id, 'unlock', [], auth()->user());
        }

        $rental->rider->increment('totalRentals');

        Payment::where('rentalId', $rental->id)
            ->where('paymentMethod', 'gcash')
            ->update([
                'status' => 'paid',
                'paidAt' => now(),
            ]);

        AuditLog::record('gcash_payment_verified', auth()->id(), [
            'rentalId' => $rental->rentalId,
            'amount' => $rental->totalFee,
            'paymentMethod' => 'gcash',
        ]);

        $this->notificationService->create(
            $rental->riderId,
            'Payment Verified',
            "Your GCash payment for rental {$rental->rentalId} has been verified. Your rental is now active!",
            'rental_status',
            ['rentalId' => $rental->rentalId]
        );

        return back()->with('success', 'GCash payment verified. Rental activated.');
    }

    public function markPaid(int $id): RedirectResponse
    {
        $rental = Rental::with(['rider'])->findOrFail($id);

        // Only ongoing (active/overdue) rentals may be manually marked paid,
        // and only when payment is still pending.
        $markPaidError = $this->markPaidError($rental);

        if ($markPaidError !== null) {
            return back()->withErrors(['rental' => $markPaidError]);
        }

        $rental->update([
            'paymentStatus' => 'paid',
            'paidAt' => now(),
        ]);

        // Keep the Payments module in sync by updating any linked payment
        // record for this rental to Paid (idempotent status/paidAt update).
        Payment::where('rentalId', $rental->id)
            ->where('paymentMethod', 'cash')
            ->update([
                'status' => 'paid',
                'paidAt' => now(),
            ]);

        AuditLog::record('rental_marked_paid', auth()->id(), [
            'rentalId' => $rental->rentalId,
            'amount' => $rental->totalFee,
            'paymentMethod' => 'cash',
            'paidAt' => now()->toDateTimeString(),
        ]);

        $this->notificationService->create(
            $rental->riderId,
            'Payment Confirmed',
            "Your cash payment for rental {$rental->rentalId} has been confirmed and marked as paid.",
            'payment_status',
            ['rentalId' => $rental->rentalId]
        );

        return back()->with('success', 'Rental marked as paid.');
    }

    private function markPaidError(Rental $rental): ?string
    {
        $guards = [
            [! in_array($rental->status, [Rental::STATUS_ACTIVE, Rental::STATUS_OVERDUE], true), 'Only active or overdue rentals can be marked as paid.'],
            [$rental->paymentStatus === 'paid', 'This rental is already marked as paid.'],
            [$rental->paymentMethod === 'gcash', 'GCash payments can only be marked paid after payment confirmation.'],
        ];

        foreach ($guards as $guard) {
            if ($guard[0]) {
                return $guard[1];
            }
        }

        return null;
    }

    public function endRide(Request $request, int $id): RedirectResponse
    {
        $rental = Rental::with(['bicycle', 'rider'])->findOrFail($id);

        $validated = $request->validate([
            'condition' => ['nullable', Rule::in([
                Rental::CONDITION_GOOD,
                Rental::CONDITION_FAIR,
                Rental::CONDITION_DAMAGED,
                Rental::CONDITION_NEEDS_MAINTENANCE,
            ])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $endRideError = $this->endRideError($rental);

        if ($endRideError !== null) {
            return back()->withErrors(['rental' => $endRideError]);
        }

        try {
            // Settles in one step: the ride is closed, the inspected condition
            // is recorded, the final fee and any penalty are frozen, the
            // bicycle is released to Available (or Maintenance) with its
            // rental pointers cleared, the payment is synced, and the rental
            // leaves Active Rentals for Rental History.
            $result = $this->rentalService->endRideAndSettle($rental, $request->user(), [
                'condition' => $validated['condition'] ?? Rental::CONDITION_GOOD,
                'note' => $validated['note'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return back()->withErrors(['rental' => $e->getMessage()]);
        }

        $fees = $result['fees'] ?? [];
        $settled = $result['rental'] ?? $rental->fresh();
        $bicycle = Bicycle::find($rental->bicycleId);

        AuditLog::record('rental_ended_by_admin', auth()->id(), [
            'rentalId' => $rental->rentalId,
            'endTime' => $settled->endTime?->toDateTimeString(),
            'condition' => $fees['condition'] ?? null,
            'baseFee' => $fees['baseFee'] ?? null,
            'penalty' => $fees['overdueFee'] ?? 0,
            'finalFee' => $fees['finalFee'] ?? null,
            'bicycleStatus' => $bicycle?->status,
        ]);

        $penalty = (float) ($fees['overdueFee'] ?? 0);
        $penaltyNote = $penalty > 0
            ? ' (includes a penalty of ₱' . number_format($penalty, 2) . ')'
            : '';

        $bicycleNote = $bicycle
            ? ' Bicycle "' . $bicycle->name . '" is now ' . $bicycle->status . '.'
            : '';

        return back()->with(
            'success',
            'Ride ended for rental ' . ($rental->rentalId ?? $rental->id)
            . ' · Final fee ₱' . number_format((float) ($fees['finalFee'] ?? 0), 2) . $penaltyNote
            . ' · Moved to Rental History.' . $bicycleNote
        );
    }

    public function processReturn(Request $request, int $id): RedirectResponse
    {
        $rental = Rental::with(['bicycle', 'rider'])->findOrFail($id);

        $validated = $request->validate([
            'return_time' => 'nullable|date',
            'condition'   => ['nullable', Rule::in(Rental::CONDITIONS)],
            'note'        => 'nullable|string|max:1000',
        ]);

        try {
            $this->rentalService->processReturn($rental, $request->user(), [
                'returnTime' => $validated['return_time'] ?? null,
                'condition'  => $validated['condition'] ?? null,
                'note'       => $validated['note'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return back()->withErrors(['rental' => $e->getMessage()]);
        }

        $settled = $rental->fresh();

        AuditLog::record('rental_return_processed', auth()->id(), [
            'rentalId' => $rental->rentalId,
            'condition' => $validated['condition'] ?? null,
            'finalFee' => $settled->finalFee,
        ]);

        // Re-read the bicycle instead of trusting $rental->bicycle: that relation
        // was eager-loaded before the settlement, so it still holds the rented
        // state and would report a bicycle that has just been released as
        // "is now rented".
        $bicycle = Bicycle::find($rental->bicycleId);

        $bicycleNote = $bicycle
            ? ' Bicycle "' . $bicycle->name . '" is now ' . $bicycle->status . '.'
            : '';

        return back()->with(
            'success',
            'Return confirmed for rental '.($rental->rentalId ?? $rental->id)
            .' · Final fee ₱'.number_format((float) $settled->finalFee, 2)
            .'.'.$bicycleNote
        );
    }

    public function setDeadline(Request $request, int $id): RedirectResponse
    {
        $rental = Rental::with(['bicycle', 'rider'])->findOrFail($id);

        if (!in_array($rental->status, [
            Rental::STATUS_EXPIRED,
            Rental::STATUS_AWAITING_RETURN,
            Rental::STATUS_OVERDUE_RETURN,
        ], true)) {
            return back()->withErrors(['rental' => 'Only expired or awaiting-return rentals can get a return deadline.']);
        }

        $validated = $request->validate([
            'minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'deadline' => ['nullable', 'date', 'after:now'],
            'penaltyType' => ['nullable', Rule::in(Rental::PENALTY_TYPES)],
            'penaltyValue' => ['nullable', 'numeric', 'min:0'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        // One normalised integer drives the presence check, the deadline math
        // and the audit trail, so every layer sees the same number.
        $minutes = isset($validated['minutes']) && $validated['minutes'] !== ''
            ? (int) $validated['minutes']
            : null;
        $explicitDeadline = isset($validated['deadline']) && $validated['deadline'] !== ''
            ? (string) $validated['deadline']
            : null;

        if (($minutes === null || $minutes < 1) && $explicitDeadline === null) {
            return back()->withErrors(['minutes' => 'Specify the allowed return time in minutes or an exact deadline.']);
        }

        $penaltyType = $validated['penaltyType'] ?? Rental::PENALTY_PER_MINUTE;
        $penaltyValue = ($validated['penaltyValue'] ?? '') !== ''
            ? (float) $validated['penaltyValue']
            : 10.00;

        // Exact deadline always comes from the server clock: minutes are added
        // to server time; an explicit deadline is only honoured as a fallback.
        $deadline = $minutes !== null
            ? now()->addMinutes($minutes)
            : Carbon::parse($explicitDeadline);

        $previousDeadline = $rental->returnDeadline;

        try {
            $this->rentalService->setReturnDeadline(
                $rental,
                $request->user(),
                $deadline,
                ['type' => $penaltyType, 'value' => $penaltyValue],
                $validated['message'] ?? null,
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['rental' => $e->getMessage()]);
        }

        AuditLog::record(
            $previousDeadline === null ? 'rental_deadline_set' : 'rental_deadline_changed',
            auth()->id(),
            [
                'rentalId' => $rental->rentalId,
                'deadline' => $deadline->toDateTimeString(),
                'previous_deadline' => $previousDeadline?->toDateTimeString(),
                'allowed_minutes' => $minutes,
                'penalty_type' => $penaltyType,
                'penalty_value' => $penaltyValue,
                'message' => $validated['message'] ?? null,
            ]
        );

        $rateLabel = match ($penaltyType) {
            Rental::PENALTY_PER_MINUTE => 'per minute',
            Rental::PENALTY_PER_HOUR => 'per hour',
            Rental::PENALTY_FIXED => 'fixed',
            default => 'per minute',
        };

        return back()->with(
            'success',
            'Return deadline set to ' . $deadline->format('M d, Y g:i A')
            . ' for rental ' . ($rental->rentalId ?? $rental->id)
            . ' · Penalty ' . number_format($penaltyValue, 2) . ' ' . $rateLabel . '.'
        );
    }

    private function endRideError(Rental $rental): ?string
    {
        if (! in_array($rental->status, [
            Rental::STATUS_ACTIVE,
            Rental::STATUS_OVERDUE,
            Rental::STATUS_EXPIRED,
        ], true)) {
            // Distinguish a repeat click from a genuinely invalid request: a
            // duplicate submission must read as "already done", not as a
            // generic validation failure.
            if ($rental->status === Rental::STATUS_RETURNED) {
                return 'This ride has already been ended and settled.';
            }
            return 'Only an active, overdue, or expired ride can be ended.';
        }

        if (User::find($rental->riderId) === null) {
            return 'The rider account for this rental no longer exists.';
        }

        return null;
    }

    public function cancel(Request $request, int $id): RedirectResponse
    {
        $rental = Rental::with(['bicycle'])->findOrFail($id);

        if (in_array($rental->status, [Rental::STATUS_COMPLETED, Rental::STATUS_CANCELLED], true)) {
            return back()->withErrors(['rental' => 'This rental cannot be cancelled.']);
        }

        $previousStatus = $rental->status;

        $rental->update([
            'status' => Rental::STATUS_CANCELLED,
            'cancelledBy' => (string) auth()->id(),
            'cancelReason' => $request->input('reason'),
        ]);

        // Rental cancelled: the service frees the bicycle (clears renter and
        // rental pointers, locks it, and keeps it in maintenance if it is out
        // of service) so this path cannot drift from the rider-facing one.
        $this->rentalService->releaseBicycleOnCancel($rental, auth()->user());

        AuditLog::record('rental_cancelled', auth()->id(), [
            'rentalId' => $rental->rentalId,
            'previous_status' => $previousStatus,
            'reason' => $request->input('reason'),
        ]);

        return back()->with('success', 'Rental cancelled successfully.');
    }
}
