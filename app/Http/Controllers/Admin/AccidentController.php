<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accident;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AccidentController extends Controller
{
    public function index(Request $request): Response
    {
        $incidents = Accident::with([
            'bicycle.currentRental.rider',
            'bicycle.currentRiderUser',
            'riderUser',
            'rental.rider',
            'acknowledger',
        ])
            ->whereIn('type', ['accident', 'impact_detected'])
            ->latest('id')
            ->paginate(20);

        $selectedIncident = null;
        if ($request->filled('incident')) {
            $selectedIncident = Accident::with([
                'bicycle.currentRental.rider',
                'bicycle.currentRiderUser',
                'riderUser',
                'rental.rider',
                'acknowledger',
            ])
                ->whereIn('type', ['accident', 'impact_detected'])
                ->find($request->query('incident'));
        }

        return response()->view('admin.accidents', compact('incidents', 'selectedIncident'));
    }

    public function details(int $id): JsonResponse
    {
        $incident = Accident::with([
            'bicycle.currentRental.rider',
            'bicycle.currentRiderUser',
            'riderUser',
            'rental.rider',
            'acknowledger',
        ])
            ->whereIn('type', ['accident', 'impact_detected'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'incident' => $this->formatIncidentPayload($incident),
        ]);
    }

    public function acknowledge(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $incident = Accident::with([
            'bicycle.currentRental.rider',
            'bicycle.currentRiderUser',
            'riderUser',
            'rental.rider',
            'acknowledger',
        ])->findOrFail($id);

        $admin = auth()->user();
        $adminName = $admin?->name ?? 'Administrator';

        if ($incident->acknowledged) {
            $msg = 'Accident report already acknowledged.';
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'alreadyAcknowledged' => true,
                    'message' => $msg,
                    'incidentId' => $incident->id,
                    'acknowledged' => true,
                    'acknowledgedBy' => $incident->acknowledger?->name ?? $adminName,
                    'acknowledgedAt' => $incident->acknowledgedAt?->format('M d, Y h:i A') ?? $incident->updated_at->format('M d, Y h:i A'),
                    'acknowledgedAtAgo' => $incident->acknowledgedAt?->diffForHumans() ?? $incident->updated_at->diffForHumans(),
                ]);
            }
            return redirect()->back()->with(['info' => $msg]);
        }

        $now = now();
        $incident->update([
            'acknowledged' => true,
            'acknowledgedBy' => $admin?->id,
            'acknowledgedAt' => $now,
            'actionTaken' => 'Acknowledged by administrator: ' . $adminName . ' on ' . $now->format('M d, Y h:i A'),
        ]);

        // Mark corresponding unread accident notifications as read
        Notification::whereIn('type', ['accident', 'impact_detected', 'system'])
            ->where(function ($q) use ($incident) {
                $q->where('bicycleId', $incident->bicycleId)
                  ->orWhere('incidentId', $incident->id)
                  ->orWhere('message', 'like', "%{$incident->bicycleId}%");
            })
            ->where('read', false)
            ->update([
                'read' => true,
                'readAt' => $now,
            ]);

        AuditLog::record(AuditLogger::ACCIDENT_ACKNOWLEDGED, $admin?->id, [
            'accidentId' => $incident->id,
            'bicycleId' => $incident->bicycleId,
            'type' => $incident->type,
            'severity' => $incident->severity,
            'acknowledgedBy' => $adminName,
            'acknowledgedAt' => $now->toIso8601String(),
        ]);

        $message = 'Accident report acknowledged.';

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'incidentId' => $incident->id,
                'acknowledged' => true,
                'acknowledgedBy' => $adminName,
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

    public function formatIncidentPayload(Accident $incident): array
    {
        $hasGps = !empty($incident->gpsLocation['lat']) && !empty($incident->gpsLocation['lng']);
        $rentalId = $incident->rentalId ? ('#' . $incident->rentalId) : ($incident->bicycle?->currentRental ? ('#' . $incident->bicycle->currentRental->id) : null);

        $mapParams = ['bike' => $incident->bicycleId];
        if ($hasGps) {
            $mapParams['lat'] = $incident->gpsLocation['lat'];
            $mapParams['lng'] = $incident->gpsLocation['lng'];
        }

        $rental = $incident->rental ?? $incident->bicycle?->currentRental;
        $rentalStatus = $rental ? ucfirst($rental->status) : 'No Active Rental';
        $accountType = ($incident->riderId || $incident->riderUser || ($rental && $rental->riderId)) ? 'Registered Account' : 'Unassociated';

        return [
            'id' => $incident->id,
            'bicycleId' => $incident->bicycleId,
            'bicycleName' => $incident->bicycle?->name ?? ('Bicycle #' . $incident->bicycleId),
            'serialNumber' => $incident->bicycle?->serialNumber ?? '—',
            'riderName' => $incident->riderDisplayName,
            'rentalId' => $rentalId,
            'rentalStatus' => $rentalStatus,
            'accountType' => $accountType,
            'status' => $incident->status ?? 'open',
            'statusLabel' => ucfirst(str_replace('_', ' ', $incident->status ?? 'open')),
            'type' => $incident->type,
            'typeLabel' => ucfirst(str_replace('_', ' ', $incident->type)),
            'severity' => $incident->severity,
            'severityLabel' => ucfirst($incident->severity),
            'description' => $incident->description ?: 'No description provided.',
            'gpsLocation' => $incident->gpsLocation,
            'hasGps' => $hasGps,
            'lat' => $hasGps ? $incident->gpsLocation['lat'] : null,
            'lng' => $hasGps ? $incident->gpsLocation['lng'] : null,
            'locationText' => $hasGps ? ($incident->gpsLocation['lat'] . ', ' . $incident->gpsLocation['lng']) : 'No GPS Coordinates',
            'impactForce' => $incident->impactForce !== null ? number_format((float) $incident->impactForce, 2) . ' G' : null,
            'speedKmh' => $incident->speedKmh !== null ? (float) $incident->speedKmh : null,
            'timestamp' => $incident->created_at->format('M d, Y H:i'),
            'timestampFull' => $incident->created_at->format('M d, Y h:i A'),
            'timestampAgo' => $incident->created_at->diffForHumans(),
            'acknowledged' => (bool) $incident->acknowledged,
            'acknowledgedBy' => $incident->acknowledger?->name ?? ($incident->acknowledged ? 'Administrator' : null),
            'acknowledgedAt' => $incident->acknowledgedAt?->format('M d, Y h:i A'),
            'acknowledgedAtAgo' => $incident->acknowledgedAt?->diffForHumans(),
            'actionTaken' => $incident->actionTaken,
            'mapUrl' => route('admin.monitoring.index', $mapParams),
            'acknowledgeUrl' => $incident->acknowledged ? null : route('admin.accidents.acknowledge', $incident->id),
        ];
    }
}
