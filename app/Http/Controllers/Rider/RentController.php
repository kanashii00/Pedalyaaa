<?php

namespace App\Http\Controllers\Rider;

use App\Exceptions\RentalException;
use App\Http\Controllers\Controller;
use App\Models\Bicycle;
use App\Models\Rental;
use App\Models\SystemSetting;
use App\Services\RentalService;
use App\Services\RiderCacheService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class RentController extends Controller
{
    public function __construct(
        protected RentalService $rentalService,
        protected RiderCacheService $riderCacheService,
    ) {}

    public function index(): View
    {
        $bicycles = $this->riderCacheService->availableBicycles();

        return view('rider.rent', [
            'bicycles' => $bicycles,
            'selectableBicycleIds' => $this->selectableBicycleIds(),
            'maxDurationMinutes' => $this->maxDurationMinutes(),
        ]);
    }

    /**
     * Longest rental a rider may book, in minutes.
     *
     * The administrator configures the ceiling in hours (System Settings), so
     * it is converted here rather than duplicated as a second setting. This is
     * the single source for both the form hint and the server-side check.
     */
    private function maxDurationMinutes(): int
    {
        $maxHours = (int) SystemSetting::getValue('rentalMaxDurationHours', 12);

        return max(1, $maxHours) * 60;
    }

    /**
     * Whitelist of bicycle ids a rider may pick right now.
     *
     * An empty array means "no restriction", which is what the config returns
     * when single-bike mode is off. The cached bicycle collection is never
     * mutated, because availableBicycles() hands back shared cached models.
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
            'paymentMethod'   => ['required', 'string', 'in:cash,gcash'],
            'durationMinutes' => ['required', 'integer', 'min:1', "max:{$maxDurationMinutes}"],
            'paymentReference'=> ['required_if:paymentMethod,gcash', 'nullable', 'string', 'max:100'],
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

        $user = $request->user();

        $redirect = $request->paymentMethod === 'gcash'
            ? redirect()->route('rider.rentals.index')
                ->with('success', 'Rental submitted! Your payment is pending verification. You will be notified once approved.')
            : redirect()->route('rider.dashboard')
                ->with('success', 'Rental started successfully. Pay at the station upon return.');

        try {
            $this->rentalService->startRental(
                $user,
                $request->bicycleId,
                (int) $request->durationMinutes,
                $request->paymentMethod,
                $request->paymentMethod === 'gcash'
                    ? $request->paymentReference
                    : null,
            );
        } catch (RentalException $e) {
            return back()->withErrors(['bicycleId' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('Rider failed to start rental', ['error' => $e->getMessage()]);
            return back()->withErrors(['bicycleId' => 'Unable to start the rental. Please try again.']);
        }

        return $redirect;
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
            ->with('bicycle')
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
            $this->rentalService->returnRental(
                $rental,
                $user,
                $request->input('end_lat'),
                $request->input('end_lng'),
                $request->input('payment_method'),
                $request->input('payment_reference'),
                $request->input('notes'),
            );

            return redirect()->route('rider.dashboard')
                ->with('success', 'Rental returned successfully.');
        } catch (RentalException $e) {
            return back()->withErrors(['return' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('Rider failed to return rental', ['error' => $e->getMessage()]);
            return back()->withErrors(['return' => 'Unable to return the rental. Please try again.']);
        }
    }
}
