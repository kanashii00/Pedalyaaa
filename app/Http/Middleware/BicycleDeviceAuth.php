<?php

namespace App\Http\Middleware;

use App\Models\Bicycle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an on-board ESP32 against the credential bound to the specific
 * bicycle in the route.
 *
 * The fleet-wide services.device_api_key used by the telemetry endpoints is
 * shared by every unit, which would let one bicycle read another's renter and
 * penalty figures. Because the LCD surface is scoped to a single rental, each
 * unit authenticates with its own key: presenting a valid key that belongs to a
 * different bicycle is rejected exactly like an invalid one.
 */
class BicycleDeviceAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Route model binding runs ahead of route middleware, so the parameter
        // is normally already a Bicycle. Fall back to the raw identifier so the
        // middleware is safe on routes that opt out of implicit binding.
        $bicycle = $request->route('bicycle');

        if (!$bicycle instanceof Bicycle) {
            $identifier = is_string($bicycle) ? $bicycle : null;

            $bicycle = $identifier !== null
                ? Bicycle::find($identifier)
                : null;
        }

        if (!$bicycle instanceof Bicycle) {
            return response()->json(['message' => 'Bicycle not found'], 404);
        }

        $apiKey = $request->header('X-API-Key');

        if ($apiKey === null || $apiKey === '') {
            return response()->json(['message' => 'Missing API key'], 401);
        }

        if (!$bicycle->hasValidDeviceKey($apiKey)) {
            return response()->json(['message' => 'Invalid API key'], 401);
        }

        $request->attributes->set('device_bicycle', $bicycle);

        return $next($request);
    }
}
