<?php

namespace App\Http\Middleware;

use App\Services\RentalService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requests a rental-lifecycle synchronization on relevant page/API requests
 * so stale records can never keep showing as "Active" or "Awaiting Return"
 * even when the scheduler is not running. The transitions are idempotent
 * (state-driven) and use the authoritative server clock.
 */
class SyncRentalLifecycle
{
    public function __construct(protected RentalService $rentalService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->rentalService->synchronizeLifecycle();

        return $next($request);
    }
}