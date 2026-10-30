<?php

namespace App\Http\Controllers\Rider;

use App\Exceptions\RentalException;
use App\Http\Controllers\Controller;
use App\Models\Bicycle;
use App\Models\Rental;
use App\Models\SystemSetting;
use App\Services\GeofenceService;
use App\Services\RentalService;
use App\Services\RiderCacheService;
use App\Services\RiderOperatingAreaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class RentController extends Controller
{
    public function __construct(
        protected RentalService $rentalService,
        protected RiderCacheService $riderCacheService,
        protected RiderOperatingAreaService $operatingArea,
    ) {}

    public function index(): View
    {
        // The whole listable fleet, not just the rentable slice: the page shows
        // an explicit, disabled card for a bicycle that is out of service so the
        // fleet a rider sees matches the fleet the admin manages. Which cards get
        // an enabled button is decided per row by Bicycle::isRentable().
        $bicycles = $this->riderCacheService->listedFleet();

        // The operating area the rider is about to ride inside. It comes from the
        // same GeofenceService configuration the admin monitoring map draws, so
        // the area shown before renting is the area enforced during the ride.
        $geofence = $this->operatingArea->geofence();

        return view('rider.rent', [
            'bicycles' => $bicycles,
            'geofence' => $geofence,
            // Boundary geometry is resolved server-side rather than redrawn in the
            // browser, so the shape on this map is exactly the shape that
            // GeofenceService measures a bicycle against.
            'geofenceGeoJson' => app(GeofenceService::class)->shapeGeoJson($geofence),
            'selectableBicycleIds' => $this->selectableBicycleIds(),
            'maxDurationMinutes' => $this->maxDurationMinutes(),
            'rentalRatePerMinute' => RentalService::RENTAL_RATE_PER_MINUTE,
        ]);
    }

    /**
     * Longest rental a rider may book, in minutes.
     *
     * The administrator configures the ceiling in hours (System Settings), so
     * it is converted here rather than duplicated as a second setting. This is
     * the single source for both the form hint and the server-side check.
     *
     * Hard-capped at MAX_DURATION_MINUTES (12 hours): a misconfigured setting
     * above that must never reopen longer bookings through this endpoint.
     */
    public const MAX_DURATION_MINUTES = 720;

    private function maxDurationMinutes(): int
    {
        $maxHours = (int) SystemSetting::getValue('rentalMaxDurationHours', 12);

        return min(max(1, $maxHours) * 60, self::MAX_DURATION_MINUTES);
    }

    /**
     * Whitelist of bicycle ids a rider may pick right now.
     *
     * An empty array means "no restriction", which is what the config returns
     * when single-bike mode is off. This is a feature flag, not availability:
     * it is intersected with the live bicycle status before a card is offered.
     */
    private function selectableBicycleIds(): array
    {
        $mode = config('pedalya.single_bike_mode');

        if (! ($mode['enabled'] ?? false)) {
            return [];
        }

        $featured = $mode['bicycle_id'] ?? null;

        return $featured === null ? [] : [(int) $featured => true];
    }

    public function store(Request $request): RedirectResponse
    {
        $maxDurationMinutes = $this->maxDurationMinutes();

        $request->validate([
            'bicycleId'       => ['required', 'integer', 'exists:bicycles,id'],
            'paymentMethod'   => ['required', 'string', 'in:cash'],
            'durationMinutes' => ['required', 'integer', 'min:1', "max:{$maxDurationMinutes}"],
        ], [
            'durationMinutes.required' => 'Please choose how long you want to rent the bicycle.',
            'durationMinutes.integer'  => 'The rental duration must be a whole number of minutes.',
            'durationMinutes.min'      => 'The rental duration must be at least 1 minute.',
            'durationMinutes.max'      => "The rental duration cannot exceed {$maxDurationMinutes} minutes.",
        ]);

        // The greyed-out cards are only a visual cue, so the same limit has to
        // be enforced here or a crafted POST could still rent another bicycle.
        $selectable = $this->selectableBicycleIds();

        if ($selectable !== [] && ! isset($selectable[(int) $request->bicycleId])) {
            return back()->withErrors([
                'bicycleId' => 'That bicycle is not available for rent right now.',
            ]);
        }

        // Re-read the bicycle and re-check its status at submit time. The card
        // the rider clicked was rendered from whatever status held when the
        // page loaded, and an administrator may have ended a ride (freeing the
        // bicycle) or pulled it into maintenance since. startRental() also
        // guards this, but failing here produces a field-level message instead
        // of a generic 500-equivalent error page.
        $bicycle = Bicycle::find($request->bicycleId);

        if ($bicycle === null) {
            return back()->withErrors([
                'bicycleId' => 'That bicycle no longer exists.',
            ]);
        }

        if ($bicycle->status !== Bicycle::STATUS_AVAILABLE) {
            return back()->withErrors([
                'bicycleId' => 'That bicycle is not available for rent right now. Please pick another bicycle.',
            ]);
        }

        // The last line of defence, and the one the greyed-out cards cannot skip:
        // a bicycle that is not in service has no lock to open, so it can never
        // start a rental no matter how the request was crafted.
        if (! $bicycle->isInService()) {
            return back()->withErrors([
                'bicycleId' => 'That bicycle is not in service and cannot be rented.',
            ]);
        }

        $user = $request->user();

        $redirect = redirect()->route('rider.dashboard')
            ->with('success', 'Rental started successfully. Pay at the station upon return.');

        try {
            $this->rentalService->startRental(
                $user,
                $request->bicycleId,
                (int) $request->durationMinutes,
                'cash',
            );
        } catch (RentalException $e) {
            return back()->withErrors(['bicycleId' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('Rider failed to start rental', ['error' => $e->getMessage()]);
            return back()->withErrors(['bicycleId' => 'Unable to start the rental. Please try again.']);
        }

        return $redirect;
    }

    /**
     * Live rental state for the rider dashboard countdown.
     *
     * The dashboard is server-rendered, so by the time its countdown reaches
     * zero the row may already have transitioned (active -> expired via the
     * rental.sync middleware on this route). The browser re-reads the
     * authoritative state here instead of guessing from its own clock, then
     * swaps the Return / Extend actions in place without a full-page reload.
     *
     * Never cached: Cache-Control no-store plus a cache-busting query string
     * from the caller, so neither the browser nor any proxy can serve a stale
     * status that hides the Return button.
     */
    public function activeStatus(Request $request): JsonResponse
    {
        $rental = $this->operatingArea->liveRentalFor($request->user());
        $now = now();

        if ($rental === null) {
            return response()->json([
                'rental' => null,
                'serverTime' => $now->toIso8601String(),
            ])->withHeaders([
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
        }

        // Same precedence as RentalService::rentalClockFor() and the dashboard
        // countdown target, so every surface counts down to the same instant.
        $deadline = $rental->returnDeadline
            ?? $rental->expectedEndTime
            ?? $rental->endTime;

        $returnSubmitted = $rental->returnRequestedAt !== null
            || in_array($rental->status, [
                Rental::STATUS_AWAITING_RETURN,
                Rental::STATUS_OVERDUE_RETURN,
            ], true);

        $canReturn = ! $returnSubmitted && in_array($rental->status, [
            Rental::STATUS_ACTIVE,
            Rental::STATUS_OVERDUE,
            Rental::STATUS_EXPIRED,
            Rental::STATUS_AWAITING_RETURN,
            Rental::STATUS_OVERDUE_RETURN,
        ], true);

        $canExtend = in_array($rental->status, [
            Rental::STATUS_ACTIVE,
            Rental::STATUS_OVERDUE,
            Rental::STATUS_EXPIRED,
        ], true);

        return response()->json([
            'rental' => [
                'id' => $rental->id,
                'rentalId' => $rental->rentalId,
                'status' => $rental->status,
                'returnRequestedAt' => optional($rental->returnRequestedAt)->toIso8601String(),
                'deadline' => optional($deadline)->toIso8601String(),
                'returnDeadline' => optional($rental->returnDeadline)->toIso8601String(),
                'expectedEndTime' => optional($rental->expectedEndTime)->toIso8601String(),
                'canReturn' => $canReturn,
                'canExtend' => $canExtend,
                'returnSubmitted' => $returnSubmitted,
            ],
            'serverTime' => $now->toIso8601String(),
        ])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    public function history(Request $request): View
    {
        $user = $request->user();

        $activeRental = $user->rentals()
            ->with('bicycle')
            ->whereIn('status', [
                Rental::STATUS_ACTIVE,
                Rental::STATUS_OVERDUE,
                Rental::STATUS_EXPIRED,
                Rental::STATUS_AWAITING_RETURN,
                Rental::STATUS_OVERDUE_RETURN,
            ])
            ->latest('id')
            ->first();

        $rentals = $user->rentals()
            ->with(['bicycle', 'payment'])
            ->latest()
            ->paginate(15);

        $totalRentals = $user->totalRentals ?? 0;
        $totalSpent = $user->totalSpent ?? 0;

        $completedRentals = $user->rentals()->where('status', 'completed')->get();
        $totalMinutes = $completedRentals->sum('durationMinutes');
        $totalTime = floor($totalMinutes / 60) . 'h ' . ($totalMinutes % 60) . 'm';

        $lateReturnPenaltyPerHour = (float) SystemSetting::getValue('lateReturnPenaltyPerHour', 20);
        $currentPenalty = null;
        $overdueMinutes = null;
        if (
            $activeRental
            && $activeRental->status === Rental::STATUS_OVERDUE_RETURN
            && $activeRental->returnDeadline
        ) {
            $currentPenalty = $this->rentalService->calculatePenaltyForRental($activeRental, now());
            $overdueMinutes = abs((int) now()->diffInMinutes($activeRental->returnDeadline));
        }

        return view('rider.history', compact(
            'rentals',
            'totalRentals',
            'totalSpent',
            'totalTime',
            'activeRental',
            'lateReturnPenaltyPerHour',
            'currentPenalty',
            'overdueMinutes'
        ));
    }

    /**
     * Extend an ongoing ride by buying extra time (the "Extend Ride" button).
     *
     * The extension is priced and recorded by RentalService, which also reopens
     * an expired ride and notifies both the rider and the administrators.
     */
    public function extendRental(Request $request, int $id): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'extensionMinutes' => ['required', 'integer', 'min:15', 'max:720'],
        ], [
            'extensionMinutes.required' => 'Please choose how long you want to extend the ride.',
            'extensionMinutes.integer'   => 'The extension must be a whole number of minutes.',
            'extensionMinutes.min'       => 'The extension must be at least 15 minutes.',
            'extensionMinutes.max'       => 'The extension cannot exceed 720 minutes.',
        ]);

        $rental = Rental::where('id', $id)
            ->where('riderId', $user->id)
            ->first();

        if ($rental === null) {
            abort(404);
        }

        try {
            $this->rentalService->extendRental($rental, (int) $request->extensionMinutes, $user);

            return redirect()->route('rider.dashboard')
                ->with('success', 'Ride extended! The extra time has been added to your rental fee.');
        } catch (RentalException $e) {
            return back()->withErrors(['extension' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('Rider failed to extend rental', ['error' => $e->getMessage(), 'rentalId' => $id]);
            return back()->withErrors(['extension' => 'Unable to extend the ride. Please try again.']);
        }
    }

    public function returnRental(Request $request, int $id): RedirectResponse
    {
        $user = $request->user();

        $rental = Rental::where('id', $id)
            ->where('riderId', $user->id)
            ->whereIn('status', [
                Rental::STATUS_ACTIVE,
                Rental::STATUS_OVERDUE,
                Rental::STATUS_EXPIRED,
                Rental::STATUS_AWAITING_RETURN,
                Rental::STATUS_OVERDUE_RETURN,
            ])
            ->firstOrFail();

        try {
            // Two-phase return: this only submits the return for inspection.
            // The rental moves to awaiting_return (persisted with
            // returnRequestedAt) and stays visible until an administrator
            // confirms it via processReturn(). A repeat submission is an
            // idempotent no-op in the service, so report it as such instead
            // of claiming another return was recorded.
            $result = $this->rentalService->returnRental(
                $rental,
                $user,
                $request->input('end_lat'),
                $request->input('end_lng'),
                $request->input('payment_method'),
                $request->input('payment_reference'),
                $request->input('notes'),
            );

            if (($result['requested'] ?? true) === false) {
                return redirect()->route('rider.dashboard')
                    ->with('success', 'Return already submitted — awaiting admin confirmation. Please wait for an administrator to inspect and confirm the return.');
            }

            return redirect()->route('rider.dashboard')
                ->with('success', 'Your return request has been submitted. Please wait for an administrator to inspect the bicycle and confirm the actual arrival time.');
        } catch (RentalException $e) {
            return back()->withErrors(['return' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('Rider failed to return rental', ['error' => $e->getMessage()]);
            return back()->withErrors(['return' => 'Unable to return the rental. Please try again.']);
        }
    }
}
