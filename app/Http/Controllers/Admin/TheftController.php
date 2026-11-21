<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accident;
use App\Models\AuditLog;
use App\Models\Bicycle;
use App\Models\GeofenceBreach;
use App\Models\Notification;
use App\Services\AuditLogger;
use App\Services\GeofenceService;
use App\Services\TheftDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class TheftController extends Controller
{
    public function __construct(
        private GeofenceService $geofenceService,
        private TheftDetectionService $theftDetectionService
    ) {}

    public function index(Request $request): Response
    {
        $statusFilter = $request->query('status', 'all');
        $severityFilter = $request->query('severity', 'all');
        $search = trim((string) $request->query('search', ''));
        $incidentIdParam = $request->query('incident');

        $geofence = $this->geofenceService->getConfig();

        // Exact same live bicycle feed + zone derivation as the shared location map.
        $mapBicycles = Bicycle::with(['latestTelemetry', 'latestGpsLog', 'currentRiderUser', 'currentRental'])
            ->where('status', '!=', 'removed')
            ->orderBy('id')
            ->get();

        // Reconcile outside map pins so red pins have active database-backed alert rows
        $this->reconcileOutsideBicycles($mapBicycles, $geofence);

        // Run escalation check on unacknowledged critical theft alerts
        $this->theftDetectionService->escalateUnacknowledgedAlerts();

        $query = Accident::with(['bicycle', 'riderUser', 'rental.rider', 'resolver'])
            ->where('type', TheftDetectionService::TYPE_THEFT);

        // Status filters
        if ($statusFilter === 'unresolved') {
            $query->whereIn('status', [TheftDetectionService::STATUS_OPEN, TheftDetectionService::STATUS_INVESTIGATING]);
        } elseif ($statusFilter === 'open') {
            $query->where('status', TheftDetectionService::STATUS_OPEN);
        } elseif ($statusFilter === 'investigating') {
            $query->where('status', TheftDetectionService::STATUS_INVESTIGATING);
        } elseif ($statusFilter === 'resolved') {
            $query->whereIn('status', [TheftDetectionService::STATUS_RESOLVED, TheftDetectionService::STATUS_RETURNED]);
        } elseif ($statusFilter === 'false_alarm') {
            $query->where('status', TheftDetectionService::STATUS_FALSE_ALARM);
        }

        // Severity filter
        if (in_array($severityFilter, [TheftDetectionService::SEVERITY_CRITICAL, TheftDetectionService::SEVERITY_WARNING, TheftDetectionService::SEVERITY_INFORMATIONAL], true)) {
            $query->where('severity', $severityFilter);
        }

        // Search filter
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                  ->orWhere('riderName', 'like', "%{$search}%")
                  ->orWhereHas('bicycle', function ($bq) use ($search) {
                      $bq->where('name', 'like', "%{$search}%")
                         ->orWhere('serialNumber', 'like', "%{$search}%");
                  })
                  ->orWhereHas('riderUser', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $alerts = $query->latest('id')->paginate(20)->withQueryString();

        $openTheftAlerts = Accident::where('type', TheftDetectionService::TYPE_THEFT)
            ->whereIn('status', [TheftDetectionService::STATUS_OPEN, TheftDetectionService::STATUS_INVESTIGATING])
            ->get()
            ->mapWithKeys(fn (Accident $a) => [
                (string) $a->bicycleId => [
                    'id' => $a->id,
                    'status' => $a->status,
                    'severity' => $a->severity,
                    'acknowledged' => (bool) $a->acknowledged,
                ],
            ]);

        $bicycles = Bicycle::with('currentRiderUser')
            ->whereIn('status', [Bicycle::STATUS_RENTED, Bicycle::STATUS_MAINTENANCE])
            ->get();

        $openBreachCount = GeofenceBreach::whereNull('resolvedAt')->count();
        $unresolvedCount = Accident::where('type', TheftDetectionService::TYPE_THEFT)
            ->whereIn('status', [TheftDetectionService::STATUS_OPEN, TheftDetectionService::STATUS_INVESTIGATING])
            ->count();
        $criticalCount = Accident::where('type', TheftDetectionService::TYPE_THEFT)
            ->where('severity', TheftDetectionService::SEVERITY_CRITICAL)
            ->whereIn('status', [TheftDetectionService::STATUS_OPEN, TheftDetectionService::STATUS_INVESTIGATING])
            ->count();
        $unacknowledgedCount = Accident::where('type', TheftDetectionService::TYPE_THEFT)
            ->where('acknowledged', false)
            ->count();

        $selectedIncident = null;
        if ($incidentIdParam) {
            $selectedIncident = Accident::with(['bicycle', 'riderUser', 'rental.rider', 'resolver', 'acknowledger'])->find($incidentIdParam);
        }
        $focusParam = $request->query('focus', '');

        return response()->view('admin.theft', compact(
            'alerts',
            'geofence',
            'mapBicycles',
            'openTheftAlerts',
            'bicycles',
            'openBreachCount',
            'unresolvedCount',
            'criticalCount',
            'unacknowledgedCount',
            'statusFilter',
            'severityFilter',
            'search',
            'selectedIncident',
            'focusParam'
        ));
    }

    public function live(): JsonResponse
    {
        $geofence = $this->geofenceService->getConfig();

        $bicycles = Bicycle::with(['latestTelemetry', 'latestGpsLog', 'currentRiderUser', 'currentRental'])
            ->where('status', '!=', 'removed')
            ->orderBy('id')
            ->get();

        $this->reconcileOutsideBicycles($bicycles, $geofence);

        $mapBicycles = [];
        foreach ($bicycles as $bike) {
            if ($bike->currentLat !== null && $bike->currentLng !== null) {
                $zone = $this->geofenceService->checkPoint((float) $bike->currentLat, (float) $bike->currentLng, $geofence);
            } else {
                $zone = ['inside' => null, 'distance' => null, 'level' => 'unknown', 'warning' => false];
            }
            $mapBicycles[] = [
                'id' => $bike->id,
                'name' => $bike->name,
                'serialNumber' => $bike->serialNumber,
                'rider' => $bike->currentRiderUser?->name,
                'rental' => $bike->currentRental ? ('#' . ($bike->currentRental->rentalId ?? $bike->currentRental->id)) : null,
                'current_lat' => $bike->currentLat,
                'current_lng' => $bike->currentLng,
                'status' => $bike->status,
                'battery_level' => $bike->batteryLevel,
                'lock_status' => $bike->lockStatus,
                'last_heartbeat' => $bike->lastHeartbeat,
                'zone_level' => $zone['level'],
                'zone_distance' => $zone['distance'],
            ];
        }

        $alerts = Accident::with(['bicycle', 'riderUser', 'rental.rider'])
            ->where('type', TheftDetectionService::TYPE_THEFT)
            ->whereIn('status', [TheftDetectionService::STATUS_OPEN, TheftDetectionService::STATUS_INVESTIGATING])
            ->latest('id')
            ->limit(50)
            ->get();

        $unresolvedCount = Accident::where('type', TheftDetectionService::TYPE_THEFT)
            ->whereIn('status', [TheftDetectionService::STATUS_OPEN, TheftDetectionService::STATUS_INVESTIGATING])
            ->count();
        $criticalCount = Accident::where('type', TheftDetectionService::TYPE_THEFT)
            ->where('severity', TheftDetectionService::SEVERITY_CRITICAL)
            ->whereIn('status', [TheftDetectionService::STATUS_OPEN, TheftDetectionService::STATUS_INVESTIGATING])
            ->count();

        return response()->json([
            'geofence' => $geofence,
            'bicycles' => $mapBicycles,
            'alerts' => $alerts->map(fn (Accident $a) => [
                'id' => $a->id,
                'bicycleId' => $a->bicycleId,
                'bicycle' => $a->bicycle->name ?? 'Unknown',
                'serialNumber' => $a->bicycle->serialNumber ?? null,
                'rider' => $a->riderDisplayName,
                'rentalId' => $a->rentalId,
                'severity' => $a->severity ?? 'critical',
                'lat' => $a->gpsLocation['lat'] ?? null,
                'lng' => $a->gpsLocation['lng'] ?? null,
                'distance' => $a->breachDistance ?? $a->distanceFromBoundary,
                'status' => $a->status,
                'investigationStatus' => $a->investigationStatusDisplay,
                'acknowledged' => (bool) $a->acknowledged,
                'timestamp' => $a->created_at->toIso8601String(),
                'updatedAt' => $a->updated_at->toIso8601String(),
            ])->values(),
            'openBreaches' => GeofenceBreach::whereNull('resolvedAt')->count(),
            'unresolved' => $unresolvedCount,
            'criticalCount' => $criticalCount,
            'unacknowledged' => $alerts->where('acknowledged', false)->count(),
            'atRisk' => Bicycle::whereIn('status', [Bicycle::STATUS_RENTED, Bicycle::STATUS_MAINTENANCE])->count(),
        ]);
    }

    /**
     * Get structured incident details for the modal view.
     */
    public function details(int $id): JsonResponse
    {
        $alert = Accident::with(['bicycle', 'riderUser', 'rental.rider', 'resolver', 'acknowledger'])->findOrFail($id);

        $loc = is_array($alert->gpsLocation) ? $alert->gpsLocation : [];
        $riderDisplay = $alert->riderDisplayName;

        return response()->json([
            'id' => $alert->id,
            'bicycle' => [
                'id' => $alert->bicycleId,
                'name' => $alert->bicycle->name ?? 'Unknown',
                'serialNumber' => $alert->bicycle->serialNumber ?? 'N/A',
                'model' => $alert->bicycle->model ?? 'N/A',
                'condition' => $alert->bicycle->condition ?? 'N/A',
                'batteryLevel' => $alert->bicycle->batteryLevel ?? null,
                'lockStatus' => $alert->bicycle->lockStatus ?? 'unknown',
                'lastHeartbeat' => $alert->bicycle->lastHeartbeat?->diffForHumans() ?? 'N/A',
                'lastGpsUpdate' => $alert->bicycle->lastGpsUpdate?->diffForHumans() ?? 'N/A',
            ],
            'rental' => $alert->rental ? [
                'id' => $alert->rental->id,
                'status' => $alert->rental->status,
                'startTime' => $alert->rental->startTime?->format('M d, Y h:i A') ?? 'N/A',
                'expectedEndTime' => $alert->rental->expectedEndTime?->format('M d, Y h:i A') ?? 'N/A',
                'totalFee' => $alert->rental->totalFee ? number_format((float) $alert->rental->totalFee, 2) : '0.00',
            ] : null,
            'rider' => [
                'displayName' => $riderDisplay,
                'hasActiveRental' => $riderDisplay !== 'No Active Rental',
                'email' => $alert->riderUser?->email ?? $alert->rental?->riderEmail ?? null,
                'phoneNumber' => $alert->riderUser?->phoneNumber ?? null,
                'studentId' => $alert->riderUser?->studentId ?? null,
            ],
            'incident' => [
                'severity' => $alert->severity ?? 'critical',
                'status' => $alert->status,
                'investigationStatus' => $alert->investigationStatusDisplay,
                'acknowledged' => (bool) $alert->acknowledged,
                'acknowledgedBy' => $alert->acknowledger?->name,
                'acknowledgedAt' => $alert->acknowledgedAt?->format('M d, Y h:i A'),
                'acknowledgedAtAgo' => $alert->acknowledgedAt?->diffForHumans(),
                'detectedAt' => $alert->created_at->format('M d, Y h:i A'),
                'detectedAtAgo' => $alert->created_at->diffForHumans(),
                'lastUpdate' => $alert->updated_at->format('M d, Y h:i A'),
                'lastUpdateAgo' => $alert->updated_at->diffForHumans(),
                'lat' => $loc['lat'] ?? null,
                'lng' => $loc['lng'] ?? null,
                'distance' => $alert->breachDistance ?? $alert->distanceFromBoundary,
                'description' => $alert->description,
                'actionTaken' => $alert->actionTaken,
                'resolutionNotes' => $alert->resolutionNotes,
                'resolvedBy' => $alert->resolver?->name,
                'resolvedAt' => $alert->resolvedAt?->format('M d, Y h:i A'),
            ],
            'liveTrackingUrl' => route('admin.monitoring.index', ['section' => 'gps', 'bicycle' => $alert->bicycleId]),
            'theftMapUrl' => route('admin.theft-alerts.index', ['incident' => $alert->id, 'focus' => 'map']),
        ]);
    }

    /**
     * Action: Acknowledge alert.
     */
    public function acknowledge(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $alert = Accident::with(['bicycle', 'riderUser', 'rental', 'acknowledger'])->findOrFail($id);

        if ($alert->acknowledged) {
            $msg = "Theft incident #{$alert->id} is already acknowledged.";
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'alreadyAcknowledged' => true,
                    'message' => $msg,
                    'incidentId' => $alert->id,
                    'acknowledged' => true,
                    'acknowledgedBy' => $alert->acknowledger?->name ?? 'Administrator',
                    'acknowledgedAt' => $alert->acknowledgedAt?->format('M d, Y h:i A') ?? $alert->updated_at->format('M d, Y h:i A'),
                    'acknowledgedAtAgo' => $alert->acknowledgedAt?->diffForHumans() ?? $alert->updated_at->diffForHumans(),
                ]);
            }
            return redirect()->back()->with(['info' => $msg]);
        }

        $admin = auth()->user();
        $now = now();

        $alert->update([
            'acknowledged' => true,
            'acknowledgedBy' => $admin->id,
            'acknowledgedAt' => $now,
            'actionTaken' => 'Acknowledged by administrator: ' . $admin->name . ' on ' . $now->format('M d, Y h:i A'),
        ]);

        GeofenceBreach::where('bicycleId', $alert->bicycleId)
            ->whereNull('resolvedAt')
            ->update(['acknowledged' => true]);

        // Mark corresponding unread theft notifications as read
        Notification::where('type', TheftDetectionService::TYPE_THEFT)
            ->where(function ($q) use ($alert) {
                $q->where('bicycleId', $alert->bicycleId)
                  ->orWhere('incidentId', $alert->id)
                  ->orWhere('message', 'like', "%#{$alert->id}%");
            })
            ->where('read', false)
            ->update([
                'read' => true,
                'readAt' => $now,
            ]);

        AuditLog::record(AuditLogger::THEFT_ALERT_ACKNOWLEDGED, $admin->id, [
            'accidentId' => $alert->id,
            'bicycleId' => $alert->bicycleId,
            'bicycleName' => $alert->bicycle?->name,
            'rentalId' => $alert->rentalId,
            'rider' => $alert->riderDisplayName,
            'severity' => $alert->severity,
            'acknowledgedBy' => $admin->name,
            'acknowledgedAt' => $now->toIso8601String(),
            'outcome' => 'success',
        ]);

        $message = "Theft incident #{$alert->id} acknowledged.";

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'incidentId' => $alert->id,
                'acknowledged' => true,
                'acknowledgedBy' => $admin->name,
                'acknowledgedAt' => $now->format('M d, Y h:i A'),
                'acknowledgedAtAgo' => $now->diffForHumans(),
            ]);
        }

        return redirect()->back()->with([
            'success' => $message,
            'toast' => [
                'title' => 'Success',
                'message' => $message,
                'type' => 'success',
            ],
        ]);
    }

    /**
     * Action: Mark incident under investigation.
     */
    public function investigate(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $alert = Accident::with(['bicycle', 'riderUser', 'rental'])->findOrFail($id);
        $notes = trim((string) $request->input('notes', ''));

        $actionTaken = 'Investigation initiated by ' . auth()->user()->name;
        if ($notes !== '') {
            $actionTaken .= ': ' . $notes;
        }

        $updateData = [
            'status' => TheftDetectionService::STATUS_INVESTIGATING,
            'investigationStatus' => TheftDetectionService::STATUS_INVESTIGATING,
            'acknowledged' => true,
            'actionTaken' => $actionTaken,
            'resolutionNotes' => $notes ?: $alert->resolutionNotes,
        ];
        if (!$alert->acknowledged) {
            $updateData['acknowledgedBy'] = auth()->id();
            $updateData['acknowledgedAt'] = now();
        }

        $alert->update($updateData);

        AuditLog::record(AuditLogger::THEFT_ALERT_INVESTIGATED, auth()->id(), [
            'accidentId' => $alert->id,
            'bicycleId' => $alert->bicycleId,
            'bicycleName' => $alert->bicycle?->name,
            'rentalId' => $alert->rentalId,
            'rider' => $alert->riderDisplayName,
            'severity' => $alert->severity,
            'notes' => $notes,
            'outcome' => 'success',
        ]);

        $message = "Theft incident #{$alert->id} status updated to Investigating.";

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return redirect()->back()->with([
            'success' => $message,
            'toast' => [
                'title' => 'Success',
                'message' => $message,
                'type' => 'success',
            ],
        ]);
    }

    /**
     * Action: Mark incident as False Alarm.
     * Requires an appropriate resolution note.
     */
    public function markFalseAlarm(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'resolution_notes' => ['required', 'string', 'min:3', 'max:1000'],
        ], [
            'resolution_notes.required' => 'Please provide a resolution note explaining why this incident is a false alarm.',
            'resolution_notes.min' => 'The resolution note must be at least 3 characters.',
        ]);

        $alert = Accident::with(['bicycle', 'riderUser', 'rental'])->findOrFail($id);
        $notes = $validated['resolution_notes'];

        $alert->update([
            'status' => TheftDetectionService::STATUS_FALSE_ALARM,
            'investigationStatus' => TheftDetectionService::STATUS_FALSE_ALARM,
            'acknowledged' => true,
            'resolutionNotes' => $notes,
            'resolvedBy' => auth()->id(),
            'resolvedAt' => now(),
            'actionTaken' => 'Marked as false alarm by ' . auth()->user()->name . ': ' . $notes,
        ]);

        GeofenceBreach::where('bicycleId', $alert->bicycleId)
            ->whereNull('resolvedAt')
            ->update(['resolvedAt' => now()]);

        AuditLog::record(AuditLogger::THEFT_ALERT_FALSE_ALARM, auth()->id(), [
            'accidentId' => $alert->id,
            'bicycleId' => $alert->bicycleId,
            'bicycleName' => $alert->bicycle?->name,
            'rentalId' => $alert->rentalId,
            'rider' => $alert->riderDisplayName,
            'severity' => $alert->severity,
            'notes' => $notes,
            'outcome' => 'success',
        ]);

        $message = "Theft incident #{$alert->id} marked as False Alarm.";

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return redirect()->back()->with([
            'success' => $message,
            'toast' => [
                'title' => 'Success',
                'message' => $message,
                'type' => 'success',
            ],
        ]);
    }

    /**
     * Action: Resolve incident.
     * Requires an appropriate resolution note.
     */
    public function resolve(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'resolution_notes' => ['required', 'string', 'min:3', 'max:1000'],
        ], [
            'resolution_notes.required' => 'Please provide a resolution note detailing the incident resolution.',
            'resolution_notes.min' => 'The resolution note must be at least 3 characters.',
        ]);

        $alert = Accident::with(['bicycle', 'riderUser', 'rental'])->findOrFail($id);
        $notes = $validated['resolution_notes'];

        $alert->update([
            'status' => TheftDetectionService::STATUS_RESOLVED,
            'investigationStatus' => TheftDetectionService::STATUS_RESOLVED,
            'acknowledged' => true,
            'resolutionNotes' => $notes,
            'resolvedBy' => auth()->id(),
            'resolvedAt' => now(),
            'actionTaken' => 'Resolved by ' . auth()->user()->name . ': ' . $notes,
        ]);

        GeofenceBreach::where('bicycleId', $alert->bicycleId)
            ->whereNull('resolvedAt')
            ->update(['resolvedAt' => now()]);

        AuditLog::record(AuditLogger::THEFT_ALERT_RESOLVED, auth()->id(), [
            'accidentId' => $alert->id,
            'bicycleId' => $alert->bicycleId,
            'bicycleName' => $alert->bicycle?->name,
            'rentalId' => $alert->rentalId,
            'rider' => $alert->riderDisplayName,
            'severity' => $alert->severity,
            'notes' => $notes,
            'outcome' => 'success',
        ]);

        $message = "Theft incident #{$alert->id} successfully resolved.";

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return redirect()->back()->with([
            'success' => $message,
            'toast' => [
                'title' => 'Success',
                'message' => $message,
                'type' => 'success',
            ],
        ]);
    }

    /**
     * Reconcile map bicycles currently outside geofence.
     */
    private function reconcileOutsideBicycles($bicycles, array $geofence): void
    {
        foreach ($bicycles as $bike) {
            $lat = $bike->currentLat;
            $lng = $bike->currentLng;
            if ($lat === null || $lng === null) {
                continue;
            }

            $result = $this->geofenceService->checkPoint((float) $lat, (float) $lng, $geofence);
            $bike->zone = $result;

            if (! ($result['inside'] ?? false)) {
                Log::debug('[TheftController] reconcile outside pin -> ensure alert', [
                    'bicycleId' => $bike->id,
                    'lat' => $lat,
                    'lng' => $lng,
                    'level' => $result['level'] ?? null,
                    'distanceOutside' => $result['distanceOutside'] ?? null,
                ]);

                $this->theftDetectionService->ensureActiveAlertForOutside(
                    $bike,
                    (float) $lat,
                    (float) $lng,
                    $result['distanceOutside'] ?? null,
                    $result
                );
            }
        }
    }
}
