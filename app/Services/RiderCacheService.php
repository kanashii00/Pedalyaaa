<?php

namespace App\Services;

use App\Models\Bicycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Cache access layer for rider-facing catalog + summary data.
 *
 * Only static / slowly-changing data is cached here:
 *   - per-bicycle catalog details (no live telemetry, GPS or lock state)
 *   - per-user dashboard summaries (totals + unread count)
 *
 * Live data is intentionally never cached and always read from the database:
 * active rental, rentals history, GPS position, smart-lock state, and
 * above all the available-bicycle list that drives the Rent page. Availability
 * changes the moment any rental settles, so a cached list would advertise
 * bicycles that are already gone and hide ones that just came free.
 */
class RiderCacheService
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    /**
     * Bicycles a rider may rent right now.
     *
     * This is deliberately NOT cached. The list is the whole of "what can I
     * rent", and every state transition that matters to a rider (a ride ending,
     * a bicycle being sent to maintenance, another rider grabbing the last one)
     * happens outside this request. Caching it for TTL_AVAILABLE_BICYCLES meant
     * the Rent page could show a bicycle as Available for up to five minutes
     * after it had already been claimed, or hide one that had just been freed
     * by an End Ride. Availability has to come straight from the bicycles table.
     *
     * Goes through Bicycle::rentable() rather than a bare `status = available`
     * filter, because a bicycle that is parked out of service carries that same
     * status. Those units have no hardware to unlock and must never be offered.
     *
     * The cache is still useful for the static parts of the fleet (the API list
     * and per-bicycle catalog lookups below), which change rarely.
     */
    public function availableBicycles(): Collection
    {
        return Bicycle::rentable()
            ->orderBy('batteryLevel', 'desc')
            ->get();
    }

    /**
     * Every bicycle the Rent page has to render, in-service or not.
     *
     * The page shows an explicit, disabled card for a bicycle that is not in
     * service instead of hiding it, so a rider can see the whole inventory and
     * understand why it cannot be chosen. Only the entries that are actually
     * rentable are turned into enabled buttons.
     */
    public function listedFleet(): Collection
    {
        return Bicycle::listable()
            ->orderBy('serialNumber', 'asc')
            ->get();
    }

    /**
     * Same live-availability guarantee as availableBicycles(), narrowed to
     * bicycles that have a usable position for the map.
     */
    public function locatedAvailableBicycles(): Collection
    {
        return Bicycle::rentable()
            ->whereNotNull('currentLat')
            ->whereNotNull('currentLng')
            ->get();
    }

    /**
     * Cached catalog entity. Live relations (latestTelemetry, latestGpsLog,
     * lock state, position) are loaded separately by the caller so cached
     * data never goes stale for real-time consumers.
     */
    public function bicycleCatalog(int $bicycleId): ?Bicycle
    {
        $key = CacheRegistry::bicycleCatalogKey($bicycleId);

        return Cache::remember($key, CacheRegistry::TTL_BICYCLE_CATALOG, function () use ($bicycleId) {
            return Bicycle::find($bicycleId);
        });
    }

    /**
     * Per-user dashboard summary. Invalidation is driven by version bumps
     * whenever the user's rentals, notifications or profile-affecting fields
     * change (observers), preventing cross-user data leakage by scoping the
     * key with the user id.
     *
     * @return array{totalRentals:int, totalSpent:float, unreadCount:int}
     */
    public function summary(int $userId): array
    {
        $key = CacheRegistry::userSummaryKey($userId);

        return Cache::remember($key, CacheRegistry::TTL_USER_SUMMARY, function () use ($userId) {
            $user = User::query()->find($userId);

            return [
                'totalRentals' => (int) ($user?->totalRentals ?? 0),
                'totalSpent' => (float) ($user?->totalSpent ?? 0),
                'unreadCount' => $this->notificationService->getUnreadCount($userId),
            ];
        });
    }
}