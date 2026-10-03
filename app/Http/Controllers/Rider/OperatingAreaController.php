<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Services\RiderOperatingAreaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live operating-area feed for the rider's own rental.
 *
 * The bicycle is derived entirely from the authenticated rider's rental. There
 * is deliberately no bicycle id, rental id or any other selector in the request,
 * so there is nothing for a rider to tamper with in order to see a bicycle they
 * are not riding: the endpoint can only ever describe their own.
 */
class OperatingAreaController extends Controller
{
    public function __construct(
        private RiderOperatingAreaService $operatingArea
    ) {}

    public function live(Request $request): JsonResponse
    {
        return response()->json($this->operatingArea->livePayloadFor($request->user()));
    }
}
