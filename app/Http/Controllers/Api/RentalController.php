<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\RentalException;
use App\Http\Controllers\Controller;
use App\Http\Resources\RentalResource;
use App\Models\Bicycle;
use App\Models\Rental;
use App\Models\User;
use App\Services\IoTService;
use App\Services\RentalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class RentalController extends Controller
{
    private const RENTAL_NOT_FOUND = 'Rental not found';

    public function __construct(
        private RentalService $rentalService,
        private IoTService $iotService
    ) {}

    public function active(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = Rental::with(['bicycle', 'rider'])
            ->whereIn('status', [Rental::STATUS_ACTIVE, Rental::STATUS_OVERDUE]);

        if ($user->role !== 'admin') {
            $query->where('riderId', $user->id);
        }

        $rentals = $query->orderByDesc('startTime')->get();

        return RentalResource::collection($rentals);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status'     => ['nullable', 'string', Rule::in(Rental::STATUSES)],
            'rider_id'   => 'nullable|integer|exists:users,id',
            'bicycle_id' => 'nullable|integer|exists:bicycles,id',
            'from'       => 'nullable|date',
            'to'         => 'nullable|date|after_or_equal:from',
            'per_page'   => 'nullable|integer|min:1|max:100',
        ]);

        $user = $request->user();
        $query = Rental::with(['bicycle', 'rider']);

        if ($user->role !== 'admin') {
            $query->where('riderId', $user->id);
        }

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['rider_id'])) {
            $query->where('riderId', $validated['rider_id']);
        }

        if (!empty($validated['bicycle_id'])) {
            $query->where('bicycleId', $validated['bicycle_id']);
        }

        if (!empty($validated['from'])) {
            $query->where('startTime', '>=', $validated['from']);
        }

        if (!empty($validated['to'])) {
            $query->where('startTime', '<=', $validated['to']);
        }

        $rentals = $query->orderByDesc('startTime')
            ->paginate($validated['per_page'] ?? 20);

        return RentalResource::collection($rentals);
    }

    public function show(int $id): RentalResource|JsonResponse
    {
        $rental = Rental::with(['bicycle', 'rider'])->find($id);

        if (!$rental) {
            return response()->json(['message' => self::RENTAL_NOT_FOUND], 404);
        }

        $user = request()->user();
        if ($user->role !== 'admin' && $rental->riderId !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return new RentalResource($rental);
    }

public function store(Request $request): JsonResponse
{
    $validated = $request->validate([
        'bicycle_id' => 'required|integer|exists:bicycles,id',
        'duration_minutes' => 'required|integer|min:1|max:720',
    ]);

    $user = $request->user();

    try {
        $rental = $this->rentalService->startRental(
            $user,
            $validated['bicycle_id'],
            $validated['duration_minutes']
        );

        return response()->json([
            'message' => 'Rental started successfully',
            'rental' => new RentalResource(
                $rental->load(['bicycle', 'rider'])
            ),
        ], 201);
    } catch (RentalException $e) {
        return response()->json([
            'message' => $e->getMessage(),
        ], 422);
    } catch (\Throwable $e) {
        Log::error('Failed to start rental', ['error' => $e->getMessage()]);
        return response()->json([
            'message' => 'Unable to start the rental.',
        ], 500);
    }
}

    public function returnRental(Request $request, int $id): JsonResponse
    {
        $rental = Rental::find($id);

        if (!$rental) {
            return response()->json(['message' => self::RENTAL_NOT_FOUND], 404);
        }

        if (!$this->isAuthorizedFor($request->user(), $rental)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'return_lat'      => 'nullable|numeric|between:-90,90',
            'return_lng'      => 'nullable|numeric|between:-180,180',
            'payment_method'  => 'nullable|string',
            'payment_reference' => 'nullable|string',
            'notes'           => 'nullable|string',
        ]);

        return $this->completeReturn($rental, $request->user(), $validated);
    }

    private function completeReturn(Rental $rental, User $user, array $validated): JsonResponse
    {
        try {
            // Dispatch through returnRental() rather than markRideEnded() directly:
            // a rider who already asked to hand the bicycle back is in
            // awaiting_return or overdue_return, and markRideEnded rejects those
            // outright. returnRental() routes each status to the right half of the
            // two-phase return, so a repeated request is an idempotent no-op here
            // exactly as it is on the rider web route.
            $result = $this->rentalService->returnRental(
                $rental,
                $user,
                $validated['return_lat'] ?? null,
                $validated['return_lng'] ?? null,
                $validated['payment_method'] ?? null,
                $validated['payment_reference'] ?? null,
                $validated['notes'] ?? null,
            );

            // "requested" is false when the rider had already asked to hand the bicycle
            // back, so say so rather than claiming a return was just recorded.
            $message = ($result['requested'] ?? true) === false
                ? 'Return request already recorded and awaiting confirmation'
                : 'Bicycle returned and awaiting confirmation';

            return response()->json([
                'message' => $message,
                'rental'  => new RentalResource($result['rental']->load(['bicycle', 'rider'])),
            ]);
        } catch (RentalException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to return rental', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Unable to return the rental.',
            ], 500);
        }
    }

    private function isAuthorizedFor(User $user, Rental $rental): bool
    {
        return $user->role === 'admin' || $rental->riderId === $user->id;
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $rental = Rental::find($id);

        if (!$rental) {
            return response()->json(['message' => self::RENTAL_NOT_FOUND], 404);
        }

        if ($rental->status !== Rental::STATUS_PENDING) {
            return response()->json(['message' => 'Only pending rentals can be approved'], 422);
        }

        $rental->update([
            'status'     => Rental::STATUS_ACTIVE,
            'approvedBy' => $request->user()->id,
            'approvedAt' => now(),
        ]);

// Rental approved: bicycle becomes Rented and the smart lock is
        // Unlocked because the rider is now authorized to use it. Updates go
        // through the model so the bicycle cache observer fires.
        $bicycle = Bicycle::find($rental->bicycleId);
        if ($bicycle) {
            $bicycle->update([
                'status' => Bicycle::STATUS_RENTED,
                'currentRider' => $rental->riderId,
                'currentRentalId' => $rental->id,
                'lockStatus' => Bicycle::LOCK_UNLOCKED,
            ]);
        }

        $this->iotService->sendCommand($rental->bicycleId, 'unlock', [], $request->user());

        return response()->json([
            'message' => 'Rental approved successfully',
            'rental'  => new RentalResource($rental->fresh()->load(['bicycle', 'rider'])),
        ]);
    }

public function cancel(Request $request, int $id): JsonResponse
    {
        $rental = Rental::find($id);

        $cancelRefusal = $this->cancelRefusal($request->user(), $rental);

        if ($cancelRefusal !== null) {
            return response()->json(['message' => $cancelRefusal[1]], $cancelRefusal[0]);
        }

        $user = $request->user();

        $rental->update([
            'status' => Rental::STATUS_CANCELLED,
            'cancelledBy' => (string) $user->id,
            'notes' => $request->input('notes'),
        ]);

        // Single source of truth for freeing the bicycle on cancellation, shared
        // with the rider-facing and admin cancel paths.
        $this->rentalService->releaseBicycleOnCancel($rental, $user);

        return response()->json([
            'message' => 'Rental cancelled successfully',
            'rental'  => new RentalResource($rental->fresh()->load(['bicycle', 'rider'])),
        ]);
    }

    private function cancelRefusal(User $user, ?Rental $rental): ?array
    {
        if ($rental === null) {
            return [404, self::RENTAL_NOT_FOUND];
        }

        $guards = [
            [$user->role !== 'admin' && $rental->riderId !== $user->id, [403, 'Unauthorized']],
            [in_array($rental->status, [Rental::STATUS_COMPLETED, Rental::STATUS_CANCELLED], true), [422, 'Rental is already ' . $rental->status]],
        ];

        foreach ($guards as $guard) {
            if ($guard[0]) {
                return $guard[1];
            }
        }

        return null;
    }
}
