<?php

namespace App\Services;

use App\Events\RentalFeedUpdated;
use Throwable;

/**
 * Best-effort "rental data changed" signal for open admin dashboards.
 *
 * Call touch() after any transition that moves rentals between the Active
 * Rentals queue, the Returns module or the settled history (start, approve,
 * return, confirm, extend, deadline, expiry, cancel). The state change itself
 * is already committed; a down broadcaster must never fail that request, so
 * dispatch errors are reported and swallowed exactly like notification
 * broadcasts.
 */
class RentalFeed
{
    public static function touch(): void
    {
        try {
            RentalFeedUpdated::dispatch();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
