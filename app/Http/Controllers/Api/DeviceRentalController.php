<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceRentalResource;
use App\Models\Bicycle;
use App\Models\Rental;
use App\Models\DeviceCommand;
use App\Services\IoTService;
use App\Services\RentalService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Read-only surface consumed by the ESP32 countdown display bolted to a
 * bicycle's handlebar.
 *
 * The device holds no rental state of its own: it asks the server what is
 * happening, counts down locally between polls, and re-syncs on the next poll.
 * That keeps Laravel the only source of truth for the deadline and the money.
 */
class DeviceRentalController extends Controller
{
    public function __construct(
        private RentalService $rentalService,
        private IoTService $iotService
    ) {}

    /**
     * Current rental for a bicycle, or null when the bicycle is idle.
     *
     * Only LIVE_STATUSES count. A returned, completed or cancelled rental is
     * history and must not be resurrected onto the display.
     */
    public function show(Request $request, Bicycle $bicycle): JsonResponse|JsonResource
    {
        $rental = Rental::where('bicycleId', $bicycle->id)
            ->whereIn('status', Rental::LIVE_STATUSES)
            ->latest('id')
            ->first();

        return new DeviceRentalResource($bicycle, $rental, $this->rentalService, Carbon::now());
    }

    /**
     * The per-bicycle display key can read only this bicycle's existing command
     * queue. This deliberately reuses IoTService and device_commands rather
     * than creating a parallel actuator command store.
     */
    public function commands(Bicycle $bicycle): JsonResponse
    {
        return response()->json([
            'data' => [
                'commands' => $this->iotService->getPendingCommands($bicycle->id),
            ],
        ]);
    }

    public function acknowledgeCommand(Request $request, Bicycle $bicycle, DeviceCommand $command): JsonResponse
    {
        if ((int) $command->bicycleId !== (int) $bicycle->id) {
            return response()->json(['message' => 'Command not found for this bicycle'], 404);
        }

        $validated = $request->validate([
            'result' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', 'string', 'in:executed,failed'],
        ]);

        $updated = $this->iotService->acknowledgeDeviceCommand(
            $command->id,
            $validated['result'] ?? null,
            $validated['status']
        );

        return response()->json([
            'message' => $updated ? 'Command acknowledged' : 'Command update failed',
            'data' => ['command' => $command->fresh()],
        ], $updated ? 200 : 500);
    }
}
