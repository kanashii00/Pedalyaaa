<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Bicycle;
use App\Models\Rental;
use App\Models\SystemSetting;
use App\Services\RentalService;
use App\Services\RiderCacheService;
use App\Services\RiderOperatingAreaService;
use App\Services\NotificationService;
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
        // countdown (ride end or return deadline) and battery reading. Any
        // rental the rider must still act on is shown for the return workflow.
        // The status list is shared with the operating-area endpoint so the map
        // and the countdown always track the same rental.
        $activeRental = $this->operatingArea->liveRentalFor($user);

        $recentRentals = $user->rentals()
            ->with('bicycle')
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

        $lateReturnPenaltyPerHour = (float) SystemSetting::getValue('lateReturnPenaltyPerHour', 20);

        $currentPenalty = null;
        $overdueMinutes = null;
        if (
            $activeRental
            && $activeRental->status === Rental::STATUS_OVERDUE_RETURN
            && $activeRental->returnDeadline
        ) {
            $rentalService = app(RentalService::class);
            $currentPenalty = $rentalService->calculatePenaltyForRental($activeRental, now());
            $overdueMinutes = abs((int) now()->diffInMinutes($activeRental->returnDeadline));
        }

        return view('rider.dashboard', [
            'user' => $user,
            'activeRental' => $activeRental,
            'recentRentals' => $recentRentals,
            'mapBicycles' => $mapBicycles,
            'geofence' => $geofence,
            'warningMinutes' => $warningMinutes,
            'lateReturnPenaltyPerHour' => $lateReturnPenaltyPerHour,
            'currentPenalty' => $currentPenalty,
            'overdueMinutes' => $overdueMinutes,
            'totalRentals' => $summary['totalRentals'],
            'totalSpent' => $summary['totalSpent'],
            'unreadCount' => $summary['unreadCount'],
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
