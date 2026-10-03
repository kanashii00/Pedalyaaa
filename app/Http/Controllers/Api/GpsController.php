<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bicycle;
use App\Models\Geofence;
use App\Models\GpsLog;
use App\Services\GeofenceService;
use App\Services\GpsIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GpsController extends Controller
{
    public function __construct(
        private GeofenceService $geofenceService,
        private GpsIngestService $gpsIngest
    ) {}

    public function location(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bicycle_id' => 'required|integer|exists:bicycles,id',
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'speed' => 'nullable|numeric|min:0',
            'heading' => 'nullable|numeric|between:0,360',
            'altitude' => 'nullable|numeric',
            'accuracy' => 'nullable|numeric|min:0',
            'hdop' => 'nullable|numeric|min:0',
            'satellites' => 'nullable|integer|min:0',
            'battery' => 'nullable|numeric|between:0,100',
            'timestamp' => 'nullable|date',
        ]);

        $bicycle = Bicycle::findOrFail($validated['bicycle_id']);

        $recorded = $this->gpsIngest->record($bicycle, [
            'lat' => $validated['lat'],
            'lng' => $validated['lng'],
            'speed' => $validated['speed'] ?? null,
            'heading' => $validated['heading'] ?? null,
            'altitude' => $validated['altitude'] ?? null,
            'accuracy' => $validated['accuracy'] ?? null,
            'hdop' => $validated['hdop'] ?? null,
            'satellites' => $validated['satellites'] ?? null,
            'battery' => $validated['battery'] ?? null,
            'timestamp' => $validated['timestamp'] ?? null,
        ]);

        return response()->json([
            'message' => 'Location recorded',
            'gps_log' => $recorded['gps_log'],
            'geofence' => $recorded['geofence'],
        ]);
    }

    public function bicycleLocation(Request $request, Bicycle $bicycle): JsonResponse
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'speed' => 'nullable|numeric|min:0',
            'heading' => 'nullable|numeric|between:0,360',
            'altitude' => 'nullable|numeric',
            'accuracy' => 'nullable|numeric|min:0',
            'hdop' => 'nullable|numeric|min:0',
            'satellites' => 'nullable|integer|min:0',
            'battery' => 'nullable|numeric|between:0,100',
            'timestamp' => 'nullable|date',
        ]);

        $recorded = $this->gpsIngest->record($bicycle, [
            'lat' => $validated['lat'],
            'lng' => $validated['lng'],
            'speed' => $validated['speed'] ?? null,
            'heading' => $validated['heading'] ?? null,
            'altitude' => $validated['altitude'] ?? null,
            'accuracy' => $validated['accuracy'] ?? null,
            'hdop' => $validated['hdop'] ?? null,
            'satellites' => $validated['satellites'] ?? null,
            'battery' => $validated['battery'] ?? null,
            'timestamp' => $validated['timestamp'] ?? null,
        ]);

        return response()->json([
            'message' => 'Location recorded',
            'gps_log' => $recorded['gps_log'],
            'geofence' => $recorded['geofence'],
        ]);
    }

    public function track(int $id): JsonResponse
    {
        $bicycle = Bicycle::find($id);

        if (! $bicycle) {
            return response()->json(['message' => 'Bicycle not found'], 404);
        }

        $logs = GpsLog::where('bicycleId', $id)
            ->orderByDesc('id')
            ->limit(1000)
            ->get();

        return response()->json([
            'bicycle_id' => $id,
            'gps_logs' => $logs,
            'count' => $logs->count(),
        ]);
    }

    public function current(int $id): JsonResponse
    {
        $bicycle = Bicycle::find($id);

        if (! $bicycle) {
            return response()->json(['message' => 'Bicycle not found'], 404);
        }

        $latestLog = GpsLog::where('bicycleId', $id)
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'bicycle_id' => $id,
            'location' => $latestLog ? [
                'lat' => (float) $latestLog->lat,
                'lng' => (float) $latestLog->lng,
                'speed' => $latestLog->speed !== null ? (float) $latestLog->speed : null,
                'heading' => $latestLog->heading !== null ? (float) $latestLog->heading : null,
                'altitude' => $latestLog->altitude !== null ? (float) $latestLog->altitude : null,
                'accuracy' => $latestLog->accuracy !== null ? (float) $latestLog->accuracy : null,
                'hdop' => $latestLog->hdop !== null ? (float) $latestLog->hdop : null,
                'satellites' => $latestLog->satellites,
                'battery' => $latestLog->batteryLevel !== null ? (float) $latestLog->batteryLevel : null,
                'timestamp' => $latestLog->timestamp,
            ] : null,
        ]);
    }

    public function updateGeofence(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'center_lat' => 'required|numeric|between:-90,90',
            'center_lng' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|numeric|min:10|max:50000',
            'shape_type' => 'nullable|string|in:circle,oval_h,oval_v,rectangle,polygon',
            'width' => 'nullable|numeric|min:10|max:100000',
            'height' => 'nullable|numeric|min:10|max:100000',
            'rotation' => 'nullable|numeric|between:0,360',
            'points' => 'nullable|array|min:3',
            'points.*.lat' => 'required_with:points|numeric|between:-90,90',
            'points.*.lng' => 'required_with:points|numeric|between:-180,180',
            'name' => 'nullable|string|max:255',
            'alert_enabled' => 'nullable|boolean',
        ]);

        $shapeType = $validated['shape_type'] ?? 'circle';
        $radius = $validated['radius'] ?? 25;

        $geofence = Geofence::updateOrCreate(
            ['isActive' => true],
            [
                'centerLat' => $validated['center_lat'],
                'centerLng' => $validated['center_lng'],
                'radius' => $radius,
                'shapeType' => $shapeType,
                'width' => $validated['width'] ?? null,
                'height' => $validated['height'] ?? null,
                'rotation' => $validated['rotation'] ?? null,
                'points' => $shapeType === 'polygon' ? ($validated['points'] ?? null) : null,
                'name' => $validated['name'] ?? 'Azuela Cove Riding Zone',
                'alertEnabled' => $validated['alert_enabled'] ?? true,
            ]
        );

        Geofence::where('isActive', true)
            ->where('id', '!=', $geofence->id)
            ->update(['isActive' => false]);

        return response()->json([
            'message' => 'Geofence updated successfully',
            'geofence' => $this->geofenceService->getConfig(),
        ]);
    }

    public function getGeofence(): JsonResponse
    {
        return response()->json([
            'geofence' => $this->geofenceService->getConfig(),
        ]);
    }
}
