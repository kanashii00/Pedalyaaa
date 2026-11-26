<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Bicycle;
use App\Models\Rental;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\RentalService;
use App\Services\RiderCacheService;
use App\Services\RiderOperatingAreaService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService,
        protected RiderCacheService $riderCacheService,
        protected RiderOperatingAreaService $operatingArea,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        // Live data — never cached: the current rental drives the on-page
        // countdown (ride end or return deadline). Any rental the rider must
        // still act on is shown for the return workflow.
        // The status list is shared with the operating-area endpoint so the map
        // and the countdown always track the same rental.
        $activeRental = $this->operatingArea->liveRentalFor($user);

        $recentRentals = $user->rentals()
            ->with(['bicycle', 'payment'])
            ->latest()
            ->limit(5)
            ->get();

        // Cached data: user dashboard summary + shared bicycle catalog.
        $summary = $this->riderCacheService->summary($user->id);

        // The operating area is the same geofence configuration the admin
        // monitoring map draws, so a rider and an admin always agree on where
        // the bicycle may be ridden.
        $geofence = $this->operatingArea->geofence();

        $mapBicycles = $this->mapBicycles($activeRental, $geofence);

        $warningMinutes = (int) SystemSetting::getValue('overdueBuzzerMinutes', 5);

        $currentPenalty = null;
        $overdueMinutes = null;
        $countdownDeadline = null;
        if ($activeRental) {
            $rentalService = app(RentalService::class);
            $countdownDeadline = $rentalService->riderCountdownDeadline($activeRental);
            $currentPenalty = $rentalService->calculatePenaltyForRental($activeRental, now());
        }
        if (
            $activeRental
            && $activeRental->status === Rental::STATUS_OVERDUE_RETURN
            && $activeRental->returnDeadline
        ) {
            $overdueMinutes = abs((int) now()->diffInMinutes($activeRental->returnDeadline));
        }

        return view('rider.dashboard', [
            'user' => $user,
            'activeRental' => $activeRental,
            'countdownDeadline' => $countdownDeadline,
            'recentRentals' => $recentRentals,
            'mapBicycles' => $mapBicycles,
            'geofence' => $geofence,
            'warningMinutes' => $warningMinutes,
            'currentPenalty' => $currentPenalty,
            'overdueMinutes' => $overdueMinutes,
            'totalRentals' => $summary['totalRentals'],
            'totalSpent' => $summary['totalSpent'],
            'unreadCount' => $summary['unreadCount'],
        ]);
    }

    /**
     * Live dashboard state for the return-confirmation watcher.
     *
     * The dashboard is server-rendered, so after the rider submits a return
     * the pending card would sit stale until a manual reload: the admin
     * confirms in a separate session. The browser re-reads the authoritative
     * state here and swaps to the normal (no active rental) UI in place.
     *
     * Everything is read fresh from the database — never from the cached
     * summary — so a confirmation is visible on the very next poll. The
     * notification created at confirmation already bumps the user's cache
     * version, so a later full page load agrees with what this endpoint
     * reported.
     */
    public function state(Request $request): JsonResponse
    {
        $user = $request->user();
        $now = now();

        $activeRental = $this->operatingArea->liveRentalFor($user);

        $recentRentals = $user->rentals()
            ->with(['bicycle', 'payment'])
            ->latest()
            ->limit(5)
            ->get();

        $freshUser = User::query()->find($user->id);

        $lastSettled = $user->rentals()
            ->whereIn('status', [Rental::STATUS_RETURNED, Rental::STATUS_COMPLETED])
            ->latest('id')
            ->first();

        $rentalService = app(RentalService::class);

        return response()->json([
            'activeRental' => $activeRental ? [
                'id' => $activeRental->id,
                'status' => $activeRental->status,
                'returnSubmitted' => $activeRental->returnRequestedAt !== null
                    || in_array($activeRental->status, [
                        Rental::STATUS_AWAITING_RETURN,
                        Rental::STATUS_OVERDUE_RETURN,
                    ], true),
                'deadline' => $rentalService->riderCountdownDeadline($activeRental)?->copy()->utc()->toIso8601String(),
                'currentPenalty' => $rentalService->calculatePenaltyForRental($activeRental, $now),
            ] : null,
            'stats' => [
                'totalRentals' => $user->rentals()->count(),
                'activeCount' => $activeRental ? 1 : 0,
                'totalSpent' => (float) ($freshUser?->totalSpent ?? 0),
            ],
            'recentRentals' => $recentRentals->map(fn (Rental $rental) => [
                'id' => $rental->id,
                'serial' => $rental->bicycle->serialNumber ?? 'N/A',
                'dateLabel' => $rental->startTime?->format('M d') ?? '—',
                'durationLabel' => $rental->durationMinutes
                    ? floor($rental->durationMinutes / 60).'h '.($rental->durationMinutes % 60).'m'
                    : '—',
                'cost' => $rental->finalAmountCollected(),
                'status' => $rental->status,
            ])->values(),
            'lastSettled' => $lastSettled ? [
                'rentalId' => $lastSettled->rentalId,
                'baseFee' => (float) ($lastSettled->totalFee ?? 0),
                'finalPenalty' => (float) ($lastSettled->overdueFee ?? 0),
                'finalFee' => (float) ($lastSettled->finalFee ?? $lastSettled->totalFee ?? 0),
            ] : null,
            'serverTime' => $now->copy()->utc()->toIso8601String(),
        ])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * The bicycles this rider's map is allowed to draw.
     *
     * While a rental is live this is exactly one entry: the bicycle being
     * ridden. No other bicycle, position or status reaches the page, so a rider
     * cannot see where another rider or another bicycle is. Before a rental
     * exists the available catalogue is shown instead, which is what a rider
     * needs in order to pick a bike, and those bicycles are by definition not in
     * someone else's rental.
     */
    private function mapBicycles(?Rental $activeRental, array $geofence): array
    {
        if ($activeRental) {
            $own = $this->operatingArea->bicycleSeed($activeRental->bicycle, $geofence);

            return $own ? [$own] : [];
        }

        return $this->riderCacheService->locatedAvailableBicycles()
            ->map(fn (Bicycle $bicycle) => $this->operatingArea->bicycleSeed($bicycle, $geofence))
            ->filter()
            ->values()
            ->all();
    }
}
