<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Bicycle;
use App\Models\DeviceStatus;
use App\Services\AuditLogger;
use App\Services\GeofenceService;
use App\Services\IoTService;
use App\Services\MaintenanceService;
use App\Services\TheftDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class BicycleController extends Controller
{
    public function __construct(
        private IoTService $iotService,
        private MaintenanceService $maintenanceService,
    ) {}

    public function index(Request $request): Response
    {
        $query = Bicycle::query();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('battery')) {
            $query->where('batteryLevel', '<=', $request->input('battery'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('serialNumber', 'like', "%{$search}%");
            });
        }

        // The inventory shows the live renter and rental, so both are eager
        // loaded: currentRental() is a belongsTo off the denormalised
        // currentRentalId, which would otherwise be one query per page row.
        $bicycles = $query
            ->with([
                'currentRiderUser:id,name,email,studentId',
                'currentRental:id,rentalId,status,startTime,expectedEndTime,durationMinutes,durationFormatted',
            ])
            ->latest()
            ->paginate(20);

        return response()->view('admin.bicycles', compact('bicycles'));
    }

    public function show(int $id): Response
    {
        $bicycle = Bicycle::with(['latestTelemetry', 'latestGpsLog', 'currentRiderUser'])->findOrFail($id);

        return response()->view('admin.bicycles.show', compact('bicycle'));
    }

    public function create(): Response
    {
        $nextSerial = $this->generateSerialNumber();

        return response()->view('admin.bicycles.create', compact('nextSerial'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'hourlyRate' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'currentLat' => ['nullable', 'numeric'],
            'currentLng' => ['nullable', 'numeric'],
        ]);

        $serialNumber = $this->generateSerialNumber();

        $bicycle = Bicycle::create([
            'name' => $validated['name'],
            'serialNumber' => $serialNumber,
            'model' => $validated['model'] ?? 'Beach Cruiser',
            'description' => $validated['description'] ?? null,
            'hourlyRate' => $validated['hourlyRate'],
            // A newly added unit is parked, not deployed: it has no smart lock
            // fitted, so it starts out of service and cannot be rented until it
            // is explicitly provisioned. `available` here would advertise it to
            // riders with no way to unlock it. Identification uses the unique
            // serial number plus the IoT device credential, not a QR code.
            'status' => Bicycle::STATUS_OUT_OF_SERVICE,
            'batteryLevel' => 100,
            'lockStatus' => Bicycle::LOCK_LOCKED,
            'condition' => 'good',
            'currentLat' => $validated['currentLat'] ?? null,
            'currentLng' => $validated['currentLng'] ?? null,
            'addedBy' => auth()->id(),
        ]);

        // Explicit rather than inherited from the column default, so the intent
        // survives if that default is ever changed.
        $bicycle->sendOutOfService();

        AuditLog::record('bicycle_created', auth()->id(), ['bicycleId' => $bicycle->id]);

        if ($bicycle->currentLat !== null && $bicycle->currentLng !== null) {
            $this->reconcilePosition($bicycle);
        }

        // The inventory already surfaces this confirmation as a compact
        // top-right toast (see layouts/admin): attaching the structured toast
        // payload suppresses the competing full-width success banner so the
        // add action notifies exactly once. Plain success flashes without
        // toast data keep the banner on other flows.
        $addedMessage = 'Bicycle "'.$bicycle->name.'" ('.$serialNumber.') added successfully.';

        return redirect()->route('admin.bicycles.index')->with([
            'success' => $addedMessage,
            'toast' => [
                'title' => 'Bicycle added successfully',
                'message' => $addedMessage,
                'type' => 'success',
            ],
        ]);
    }

    private function generateSerialNumber(): string
    {
        $year = date('Y');
        $prefix = "PDY-{$year}-";

        // Derive the next sequence from the highest one currently in use rather
        // than from the row count: a deleted serial leaves a gap, and a
        // count-based sequence would hand out a number that is still taken.
        $highest = Bicycle::where('serialNumber', 'like', $prefix.'%')
            ->pluck('serialNumber')
            ->map(fn (string $serial): int => (int) substr($serial, strlen($prefix)))
            ->filter(fn (int $seq): bool => $seq > 0)
            ->max() ?? 0;

        do {
            $seq = str_pad((string) ++$highest, 5, '0', STR_PAD_LEFT);
            $serial = $prefix.$seq;
        } while (Bicycle::where('serialNumber', $serial)->exists());

        return $serial;
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $bicycle = Bicycle::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'serialNumber' => ['sometimes', 'string', 'max:255', "unique:bicycles,serialNumber,{$bicycle->id}"],
            'model' => ['nullable', 'string', 'max:255'],
            'batteryLevel' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'status' => ['sometimes', Rule::in(Bicycle::STATUSES)],
            'hourlyRate' => ['sometimes', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'currentLat' => ['nullable', 'numeric'],
            'currentLng' => ['nullable', 'numeric'],
        ]);

        $previousStatus = $bicycle->status;
        $newStatus = $validated['status'] ?? $previousStatus;

        if ($newStatus !== $previousStatus) {
            if ($newStatus === 'maintenance' && $previousStatus === 'rented') {
                return back()->withErrors(['status' => 'Cannot move a rented bicycle to maintenance. End the active rental first.']);
            }

            if ($newStatus !== 'maintenance' && $previousStatus === 'maintenance' && ! $this->maintenanceService->canReleaseBicycle($bicycle)) {
                return back()->withErrors(['status' => 'Cannot change status while active maintenance records exist. Complete or cancel them first.']);
            }

            if ($newStatus === 'maintenance') {
                $this->maintenanceService->placeBicycleInMaintenance(
                    $bicycle,
                    'Maintenance initiated from Bicycle Inventory',
                    auth()->id(),
                );

                AuditLog::record('bicycle_maintenance_auto', auth()->id(), [
                    'bicycleId' => $bicycle->id,
                ]);
            }

            // Only the in-service unit has a smart lock to secure on the way in,
            // and only it returns to Available on the way out. A parked bicycle
            // that has been repaired is still parked: no lock to hand out, so it
            // stays out of service and keeps the lock state it is recorded in.
            if ($bicycle->isInService()) {
                if ($newStatus === 'maintenance') {
                    $validated['lockStatus'] = Bicycle::LOCK_LOCKED;
                }

                if ($newStatus === 'available' && $previousStatus === 'maintenance') {
                    $validated['lockStatus'] = Bicycle::LOCK_LOCKED;
                }
            } elseif ($newStatus === 'available' && $previousStatus === 'maintenance') {
                $validated['status'] = Bicycle::STATUS_OUT_OF_SERVICE;
                unset($validated['lockStatus']);
            }
        }

        $before = $bicycle->only([
            'name', 'serialNumber', 'model', 'batteryLevel', 'status',
            'hourlyRate', 'description', 'currentLat', 'currentLng', 'lockStatus',
        ]);

        $bicycle->update($validated);

        if (array_key_exists('currentLat', $validated) || array_key_exists('currentLng', $validated)) {
            $this->reconcilePosition($bicycle);
        }

        AuditLog::record('bicycle_updated', auth()->id(), [
            'bicycleId' => $bicycle->id,
            'serialNumber' => $bicycle->serialNumber,
            'outcome' => 'success',
            'changes' => AuditLogger::changes($before, $bicycle->fresh()->only([
                'name', 'serialNumber', 'model', 'batteryLevel', 'status',
                'hourlyRate', 'description', 'currentLat', 'currentLng', 'lockStatus',
            ])),
        ]);

        return back()->with('success', 'Bicycle updated successfully.');
    }

    /**
     * Evaluate the geofence whenever a bicycle's coordinates are written so an
     * outside position immediately gets a single open theft alert and an inside
     * position resolves it — keeping red pins on the shared map and the Theft
     * Alert Log in sync no matter which path wrote the position.
     */
    private function reconcilePosition(Bicycle $bicycle): void
    {
        $lat = $bicycle->currentLat;
        $lng = $bicycle->currentLng;
        if ($lat === null || $lng === null) {
            return;
        }

        $geofenceService = app(GeofenceService::class);
        $theftService = app(TheftDetectionService::class);
        $result = $geofenceService->checkPointInGeofence((float) $lat, (float) $lng);

        Log::debug('[AdminBicycle] reconcile position', [
            'bicycleId' => $bicycle->id,
            'lat' => $lat,
            'lng' => $lng,
            'inside' => $result['inside'] ?? null,
            'level' => $result['level'] ?? null,
        ]);

        if (! ($result['inside'] ?? false)) {
            $theftService->ensureActiveAlertForOutside(
                $bicycle,
                (float) $lat,
                (float) $lng,
                $result['distanceOutside'] ?? null,
                $result
            );
        } elseif (! in_array($result['level'] ?? null, ['approaching', 'warning'], true)) {
            $theftService->resolveAlertOnReturn($bicycle);
        }
    }

    public function destroy(int $id): RedirectResponse
    {
        $bicycle = Bicycle::findOrFail($id);

        if ($bicycle->status === Bicycle::STATUS_MAINTENANCE) {
            return back()->withErrors(['bicycle' => 'Cannot remove a bicycle that is under maintenance. Complete maintenance first.']);
        }

        if ($bicycle->rentals()->whereIn('status', ['active', 'pending', 'overdue'])->exists()) {
            return back()->withErrors(['bicycle' => 'Cannot remove bicycle with active or pending rentals.']);
        }

        // Snapshot first: the audit entry must stay traceable even though the
        // bicycle leaves the rentable fleet here.
        $removedSnapshot = $bicycle->only([
            'name', 'serialNumber', 'model', 'status', 'condition', 'hourlyRate',
        ]);

        $bicycle->update([
            'status' => Bicycle::STATUS_REMOVED,
            'removedAt' => now(),
            'removedBy' => auth()->id(),
        ]);

        AuditLog::record('bicycle_removed', auth()->id(), [
            'bicycleId' => $bicycle->id,
            'outcome' => 'success',
            'snapshot' => $removedSnapshot,
        ]);

        return back()->with('success', 'Bicycle removed successfully.');
    }

    public function lock(Request $request, int $id): RedirectResponse
    {
        $bicycle = Bicycle::findOrFail($id);

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:lock,unlock'],
        ]);

        if (in_array($bicycle->status, ['rented', 'maintenance'])) {
            return back()->withErrors(['bicycle' => 'Cannot change lock status while bicycle is rented or in maintenance.']);
        }

        // A bicycle that is not in service has no servo or smart lock behind the
        // dashboard control, so there is nothing to actuate. Say so instead of
        // queueing a command that could never be acknowledged.
        if (! $bicycle->isInService()) {
            return back()->withErrors([
                'bicycle' => "{$bicycle->serialNumber} is not in service and has no controllable lock.",
            ]);
        }

        $command = $this->iotService->sendCommand($bicycle->id, $validated['action'], [], auth()->user());

        if ($command === null) {
            return back()->withErrors([
                'bicycle' => "Could not queue the {$validated['action']} command for {$bicycle->serialNumber}.",
            ]);
        }

        AuditLog::record('bicycle_lock_'.$validated['action'], auth()->id(), [
            'bicycleId' => $bicycle->id,
            'serialNumber' => $bicycle->serialNumber,
            'outcome' => 'success',
            'changes' => [
                'lockStatus' => [
                    'from' => $bicycle->lockStatus,
                    'to' => $validated['action'] === 'lock' ? 'locked' : 'unlocked',
                ],
            ],
        ]);

        return back()->with('success', "Remote {$validated['action']} command sent to device.");
    }

    public function telemetry(int $id): JsonResponse
    {
        $bicycle = Bicycle::findOrFail($id);

        $deviceStatus = DeviceStatus::where('bicycleId', $id)
            ->latest('eventTimestamp')
            ->first();

        return response()->json([
            'bicycle' => $bicycle,
            'device_status' => $deviceStatus,
        ]);
    }
}
