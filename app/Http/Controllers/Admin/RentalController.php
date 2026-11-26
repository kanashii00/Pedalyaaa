<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Bicycle;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\User;
use App\Services\IoTService;
use App\Services\NotificationService;
use App\Services\RentalService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RentalController extends Controller
{
    /**
     * A manual arrival-time correction of one full penalty interval (five
     * minutes) or more needs verified evidence. Smaller differences are treated
     * as the normal gap between opening the dialog and submitting it, or as
     * minute-picker rounding.
     */
    public function __construct(
        private NotificationService $notificationService,
        private IoTService $iotService,
        private RentalService $rentalService
    ) {}

    public function index(Request $request): Response
    {
        // The queue is the shared "active rentals" scope so this listing, its
        // record count and the sidebar badge can never disagree.
        $query = Rental::with(['bicycle', 'rider', 'payment'])->activeList();

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
        $query = Rental::with(['bicycle', 'rider', 'payment'])
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

        $query = Rental::with(['bicycle', 'rider', 'payment']);

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
        ];

        return response()->view(
            'admin.rentals-returns',
            compact('rentals', 'bicyclesList', 'ridersList', 'summary', 'view', 'overdueStats')
        );
    }

    public function show(int $id): Response
    {
        $rental = Rental::with(['bicycle', 'rider', 'payment'])->findOrFail($id);

        return response()->view('admin.rentals.show', compact('rental'));
    }

    /**
     * Tiny versioned snapshot behind the admin live sync.
     *
     * Every counter mirrors the aggregate its page already renders (the Active
     * Rentals queue size, the Returns summary, the fleet availability and
     * today's settled revenue), so a client can poll this cheaply and only
     * re-render tables when the version token actually moves. The
     * rental.sync middleware promotes due transitions first, keeping the
     * backend the source of truth, and no-store headers keep proxies and the
     * browser from serving a stale snapshot that would hide a new rental.
     */
    public function feed(): JsonResponse
    {
        $today = Carbon::today();
        $settled = [Rental::STATUS_COMPLETED, Rental::STATUS_RETURNED];

        $active = Rental::activeListCount();
        $pendingReturns = Rental::whereIn('status', [
            Rental::STATUS_AWAITING_RETURN,
            Rental::STATUS_OVERDUE_RETURN,
        ])->count();
        $processed = Rental::whereIn('status', [Rental::STATUS_RETURNED, Rental::STATUS_COMPLETED])->count();
        $bikesAvailable = Bicycle::where('status', Bicycle::STATUS_AVAILABLE)->count();

        // Real pending-return events for the page-level toast watcher. The
        // notification broadcast is the fast path; this snapshot is the
        // polling-safe fallback and carries enough detail for the same concise
        // "inspect and confirm" message without inventing client-side events.
        $pendingReturnRequests = Rental::with(['rider:id,name', 'bicycle:id,name,serialNumber'])
            ->whereIn('status', [
                Rental::STATUS_AWAITING_RETURN,
                Rental::STATUS_OVERDUE_RETURN,
            ])
            ->orderByDesc('returnRequestedAt')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(function (Rental $rental): array {
                $code = $rental->rentalId ?? (string) $rental->id;
                $requestedAt = $rental->returnRequestedAt ?? $rental->endTime ?? $rental->updated_at;

                return [
                    'key' => 'rental:'.$code,
                    'rentalId' => $code,
                    'rentalDbId' => $rental->id,
                    'status' => $rental->status,
                    'rider' => $rental->rider?->name ?? $rental->riderName,
                    'bicycle' => $rental->bicycle?->name ?? $rental->bicycleName,
                    'serial' => $rental->bicycle?->serialNumber ?? $rental->bicycleSerial,
                    'requestedAt' => $requestedAt?->toIso8601String(),
                ];
            })
            ->values();

        $payload = [
            'active' => $active,
            'pendingReturns' => $pendingReturns,
            'pendingReturnRequests' => $pendingReturnRequests,
            'overdueReturns' => Rental::where('status', Rental::STATUS_OVERDUE_RETURN)->count(),
            'processed' => $processed,
            'feeCollected' => (float) Rental::where('status', Rental::STATUS_RETURNED)->sum('finalFee'),
            'confirmedToday' => Rental::whereIn('status', [Rental::STATUS_RETURNED, Rental::STATUS_COMPLETED])
                ->whereDate('returnProcessedAt', $today)
                ->count(),
            'bikesAvailable' => $bikesAvailable,
            'bikesTotal' => Bicycle::count(),
            'revenueToday' => (float) Rental::whereIn('status', $settled)
                ->whereDate('updated_at', $today)
                ->sum('totalFee'),
            // Fingerprint, not just a timestamp: two transitions landing in
            // the same second still move the version because the counters do.
            'version' => implode('|', [
                Rental::max('updated_at'),
                Bicycle::max('updated_at'),
                $active,
                $pendingReturns,
                $processed,
                $bikesAvailable,
            ]),
            'serverTime' => now()->toIso8601String(),
        ];

        return response()->json($payload)->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
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
                'amountPaid' => DB::raw('totalAmount'),
                'balanceDue' => 0,
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
                'amountPaid' => DB::raw('totalAmount'),
                'balanceDue' => 0,
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

        try {
            $validated = $request->validate([
                'condition' => ['nullable', Rule::in([
                    Rental::CONDITION_GOOD,
                    Rental::CONDITION_FAIR,
                    Rental::CONDITION_DAMAGED,
                    Rental::CONDITION_NEEDS_MAINTENANCE,
                ])],
                'note' => ['nullable', 'string', 'max:1000'],
            ]);
        } catch (ValidationException $e) {
            return $this->rentalToastFailure('Ride not ended', $e->errors());
        }

        $endRideError = $this->endRideError($rental);

        if ($endRideError !== null) {
            return $this->rentalToastFailure('Ride not ended', ['rental' => $endRideError]);
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
            return $this->rentalToastFailure('Ride not ended', ['rental' => $e->getMessage()]);
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

        $endedMessage = 'Ride ended for rental ' . ($rental->rentalId ?? $rental->id)
            . ' · Final fee ₱' . number_format((float) ($fees['finalFee'] ?? 0), 2) . $penaltyNote
            . ' · Moved to Rental History.' . $bicycleNote;

        return back()->with([
            'success' => $endedMessage,
            'toast' => [
                'title' => 'Ride ended successfully',
                'message' => $endedMessage,
                'type' => 'success',
            ],
        ]);
    }

    /**
     * Start a signed Process Return session.
     *
     * The modal calls this the moment the administrator opens it. The server
     * time returned here is the default actual-arrival time, and the encrypted
     * token binds that default to this rental and this administrator. The
     * submission can therefore tell the difference between "I accepted the
     * server default" and "I manually corrected the time", without trusting a
     * client-supplied timestamp or hidden field.
     */
    public function returnVerification(Request $request, int $id): JsonResponse
    {
        $rental = Rental::findOrFail($id);

        if (!in_array($rental->status, [
            Rental::STATUS_AWAITING_RETURN,
            Rental::STATUS_OVERDUE_RETURN,
        ], true)) {
            return response()->json([
                'message' => 'Only rentals awaiting return can be processed.',
            ], 422);
        }

        $startedAt = Carbon::now();
        $token = Crypt::encryptString(json_encode([
            'rentalId' => $rental->id,
            'adminId' => $request->user()->id,
            'startedAt' => $startedAt->toIso8601String(),
        ]));

        return response()->json([
            'processingStartedAt' => $startedAt->toIso8601String(),
            'serverNow' => $startedAt->toIso8601String(),
            'token' => $token,
        ]);
    }

    public function processReturn(Request $request, int $id): RedirectResponse
    {
        $rental = Rental::with(['bicycle', 'rider'])->findOrFail($id);

        // Existing business rules: return_time is optional (omitted = now) and
        // must be a parseable date when present; anything unparseable is
        // rejected here. No fee/penalty/total fields are accepted — the server
        // recalculates Late Penalty + Final Fee from the STORED deadline and
        // this timestamp in RentalService::settleRental(), so the modal preview
        // is display-only and can never be trusted as the charge.
        try {
            $validated = $request->validate([
                'return_time'          => 'nullable|date',
                'returnTime'           => 'nullable|date',
                'actual_return_time'   => 'nullable|date',
                'actualReturnTime'     => 'nullable|date',
                'processing_token'     => 'nullable|string|max:4096',
                'return_time_evidence' => 'nullable|string|max:1000',
                'returnTimeEvidence'   => 'nullable|string|max:1000',
                'evidence'             => 'nullable|string|max:1000',
                'correction_reason'    => 'nullable|string|max:1000',
                'return_time_reason'   => 'nullable|string|max:1000',
                'condition'            => ['nullable', Rule::in(Rental::CONDITIONS)],
                'note'                 => 'nullable|string|max:1000',
            ]);
        } catch (ValidationException $e) {
            return $this->rentalToastFailure('Return not processed', $e->errors());
        }

        $serverNow = Carbon::now();
        $processingStartedAt = $this->processingStartedAtFromToken(
            $validated['processing_token'] ?? null,
            $rental,
            $request->user(),
            $serverNow
        );
        $defaultReturnTime = $processingStartedAt ?? $serverNow->copy();

        $submittedReturnTime = ($validated['return_time'] ?? null)
            ?: ($validated['returnTime'] ?? null)
            ?: ($validated['actual_return_time'] ?? null)
            ?: ($validated['actualReturnTime'] ?? null);

        // An omitted value means the administrator accepted the server default
        // captured when the dialog opened. It never falls back to the rider's
        // Return Submitted At or to any pre-existing endTime.
        // datetime-local has minute precision. Treat the value that we put in
        // the picker as accepting the signed server default, rather than as a
        // client-side edit which happens to discard its seconds.
        $acceptedServerDefault = $submittedReturnTime !== null
            && $submittedReturnTime !== ''
            && $submittedReturnTime === $defaultReturnTime->format('Y-m-d\\TH:i');
        $actualReturnTime = $submittedReturnTime === null || $submittedReturnTime === '' || $acceptedServerDefault
            ? $defaultReturnTime->copy()
            : Carbon::parse($submittedReturnTime)->setTimezone(config('app.timezone'));

        if ($actualReturnTime->gt($serverNow->copy()->addMinute())) {
            return $this->rentalToastFailure('Return not processed', [
                'return_time' => ['The actual return time cannot be in the future.'],
            ]);
        }

        if ($rental->startTime !== null && $actualReturnTime->lt($rental->startTime)) {
            return $this->rentalToastFailure('Return not processed', [
                'return_time' => ['The actual return time cannot be before the rental start time.'],
            ]);
        }

        $evidence = trim((string) ($validated['return_time_evidence'] ?? ''));
        if ($evidence === '') {
            $evidence = trim((string) ($validated['returnTimeEvidence'] ?? ''));
        }
        if ($evidence === '') {
            $evidence = trim((string) ($validated['evidence'] ?? ''));
        }
        if ($evidence === '') {
            $evidence = trim((string) ($validated['correction_reason'] ?? ''));
        }
        if ($evidence === '') {
            $evidence = trim((string) ($validated['return_time_reason'] ?? ''));
        }

        $correctionSeconds = abs($actualReturnTime->getTimestamp() - $defaultReturnTime->getTimestamp());
        // Any intentional picker edit is a correction of the verified
        // arrival fact. It must be supported by recorded evidence; only the
        // displayed server default may be accepted without it.
        $returnTimeCorrected = $correctionSeconds > 0;

        if ($returnTimeCorrected && $evidence === '') {
            return $this->rentalToastFailure('Return not processed', [
                'return_time_evidence' => [
                    'Correcting the verified arrival time requires evidence. '
                    . 'Describe the verified evidence or accept the server default.',
                ],
            ]);
        }

        try {
            $result = $this->rentalService->processReturn($rental, $request->user(), [
                // Only the verified arrival instant reaches settlement. The
                // modal preview, rider submission time and any client-supplied
                // fee fields are never used as the charge.
                'returnTime' => $actualReturnTime->toDateTimeString(),
                'condition'  => $validated['condition'] ?? null,
                'note'       => $validated['note'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return $this->rentalToastFailure('Return not processed', ['rental' => $e->getMessage()]);
        }

        $settled = $result['rental'] ?? $rental->fresh();

        $deadlineUnchanged = ($rental->returnDeadline === null && $settled->returnDeadline === null)
            || (
                $rental->returnDeadline !== null
                && $settled->returnDeadline !== null
                && $rental->returnDeadline->equalTo($settled->returnDeadline)
            );

        AuditLog::record('rental_return_processed', auth()->id(), [
            'rentalId' => $rental->rentalId,
            'condition' => $settled->returnCondition ?? $validated['condition'] ?? null,
            'returnSubmittedAt' => $rental->returnRequestedAt?->toDateTimeString(),
            'returnTime' => $settled->endTime?->toDateTimeString(),
            'defaultReturnTime' => $defaultReturnTime->toDateTimeString(),
            'returnTimeCorrected' => $returnTimeCorrected,
            'returnTimeAdjustmentSeconds' => $correctionSeconds,
            'returnTimeEvidence' => $evidence !== '' ? $evidence : null,
            'returnDeadline' => $rental->returnDeadline?->toDateTimeString(),
            'returnDeadlineUnchanged' => $deadlineUnchanged,
            'changes' => $returnTimeCorrected
                ? [
                    'returnTime' => [
                        'from' => $defaultReturnTime->toDateTimeString(),
                        'to' => $actualReturnTime->toDateTimeString(),
                    ],
                ]
                : [],
            'finalFee' => $settled->finalFee,
            'overdueFee' => $settled->overdueFee ?? 0,
            'amountPaid' => $result['payment']['amountPaid'] ?? null,
            'balanceDue' => $result['payment']['balanceDue'] ?? null,
            'outcome' => 'success',
        ]);

        // Re-read the bicycle instead of trusting $rental->bicycle: that relation
        // was eager-loaded before the settlement, so it still holds the rented
        // state and would report a bicycle that has just been released as
        // "is now rented".
        $bicycle = Bicycle::find($rental->bicycleId);

        $rentalCode = $rental->rentalId ?? (string) $rental->id;
        $finalFee = '₱'.number_format((float) $settled->finalFee, 2);
        $bicycleStatus = $bicycle
            ? strtolower(str_replace('_', ' ', (string) $bicycle->status))
            : 'available';

        $balanceDue = (float) ($result['payment']['balanceDue'] ?? 0);
        $balanceNote = $balanceDue > 0
            ? ' Balance due: ₱'.number_format($balanceDue, 2).'.'
            : '';

        $toastTitle = 'Return confirmed successfully';
        $toastMessage = "Rental {$rentalCode} has been completed. Final fee: {$finalFee}. Bicycle is now {$bicycleStatus}.{$balanceNote}";

        return back()->with([
            'success' => $toastMessage,
            'toast' => [
                'title' => $toastTitle,
                'message' => $toastMessage,
                'type' => 'success',
            ],
        ]);
    }

    /**
     * Return/end-ride failures keep the underlying validation errors for the
     * form and post the first one through the shared top-right toast. The
     * admin layout skips the duplicate in-page alert when this structured
     * toast is present, so one failure produces exactly one notification.
     *
     * @param  array<string, array<int, string>|string>  $errors
     */
    private function rentalToastFailure(string $title, array $errors): RedirectResponse
    {
        $messages = collect($errors)->flatten()->filter()->values();
        $message = (string) ($messages->first() ?? 'Please try again.');

        return back()
            ->withInput()
            ->withErrors($errors)
            ->with('toast', [
                'title' => $title,
                'message' => $message,
                'type' => 'error',
            ]);
    }

    /**
     * Decode and validate the signed Process Return session token.
     *
     * A missing, forged, expired, or cross-rental/cross-admin token is treated
     * as "no server default": the request still works, but any submitted time
     * that differs materially from the current server clock needs evidence.
     */
    private function processingStartedAtFromToken(
        ?string $token,
        Rental $rental,
        ?User $admin,
        Carbon $serverNow
    ): ?Carbon {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        if ((int) ($payload['rentalId'] ?? 0) !== (int) $rental->id) {
            return null;
        }

        if ((int) ($payload['adminId'] ?? 0) !== (int) ($admin?->id ?? 0)) {
            return null;
        }

        $startedAt = $payload['startedAt'] ?? null;
        if (! is_string($startedAt) || $startedAt === '') {
            return null;
        }

        try {
            $startedAt = Carbon::parse($startedAt)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }

        // A session older than four hours is stale; a token from the future is
        // not trustworthy. Either way, fall back to "now" and require evidence
        // for a materially earlier arrival.
        if ($startedAt->gt($serverNow->copy()->addMinute())) {
            return null;
        }

        if ($startedAt->lt($serverNow->copy()->subHours(4))) {
            return null;
        }

        return $startedAt;
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
            'penaltyType' => ['prohibited'],
            'penaltyValue' => ['prohibited'],
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
                'penalty_rule' => 'PHP 20 per completed 5-minute block after original rental end',
                'message' => $validated['message'] ?? null,
            ]
        );

        return back()->with(
            'success',
            'Return deadline set to ' . $deadline->format('M d, Y g:i A')
            . ' for rental ' . ($rental->rentalId ?? $rental->id)
            . ' · late fee is PHP 20 per completed 5-minute block after the original rental end time.'
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
