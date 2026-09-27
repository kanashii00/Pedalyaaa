<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\GeofenceService;
use App\Services\RentalService;
use App\Services\RiderCacheService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService,
        protected RiderCacheService $riderCacheService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        // Live data — never cached: the current rental drives the on-page
        // countdown (ride end or return deadline) and battery reading. Any
        // rental the rider must still act on is shown for the return workflow.
        $activeRental = $user->rentals()
            ->with('bicycle')
            ->whereIn('status', [
                \App\Models\Rental::STATUS_ACTIVE,
                \App\Models\Rental::STATUS_OVERDUE,
                \App\Models\Rental::STATUS_EXPIRED,
                \App\Models\Rental::STATUS_AWAITING_RETURN,
                \App\Models\Rental::STATUS_OVERDUE_RETURN,
            ])
            ->latest('id')
            ->first();

        $recentRentals = $user->rentals()
            ->with('bicycle')
            ->latest()
            ->limit(5)
            ->get();

        // Cached data: user dashboard summary + shared bicycle catalog.
        $summary = $this->riderCacheService->summary($user->id);

        $bicycles = $this->riderCacheService->locatedAvailableBicycles();

        $geofenceCenter = app(GeofenceService::class)->getConfig();

        $warningMinutes = (int) SystemSetting::getValue('overdueBuzzerMinutes', 5);

        $lateReturnPenaltyPerHour = (float) SystemSetting::getValue('lateReturnPenaltyPerHour', 20);

        $currentPenalty = null;
        $overdueMinutes = null;
        if (
            $activeRental
            && $activeRental->status === \App\Models\Rental::STATUS_OVERDUE_RETURN
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
            'bicycles' => $bicycles,
            'geofenceCenter' => $geofenceCenter,
            'warningMinutes' => $warningMinutes,
            'lateReturnPenaltyPerHour' => $lateReturnPenaltyPerHour,
            'currentPenalty' => $currentPenalty,
            'overdueMinutes' => $overdueMinutes,
            'totalRentals' => $summary['totalRentals'],
            'totalSpent' => $summary['totalSpent'],
            'unreadCount' => $summary['unreadCount'],
        ]);
    }
}
