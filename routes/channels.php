<?php

use App\Models\Bicycle;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('gps.{bicycleId}', function (User $user, int $bicycleId) {
    if ($user->isAdmin()) {
        return true;
    }

    $bicycle = Bicycle::find($bicycleId);

    return $bicycle && (int) $bicycle->currentRider === $user->id;
});

Broadcast::channel('geofence-alerts', function (User $user) {
    return $user->isAdmin();
});

/**
 * Per-user notification channel used by the header dropdown of both the admin
 * and rider shells. Only the signed-in owner of the notifications may listen,
 * so a rider can never receive (or subscribe to) somebody else's rows.
 */
Broadcast::channel('App.Models.User.{id}', function (?User $user, int $id) {
    return $user !== null && (int) $user->id === $id;
});

/**
 * Admin-only rental lifecycle feed. Carries no rental data itself (clients
 * refetch the versioned feed), so the only thing guarded here is who gets
 * told that something changed.
 */
Broadcast::channel('rentals-feed', function (?User $user) {
    return $user !== null && $user->isAdmin();
});
