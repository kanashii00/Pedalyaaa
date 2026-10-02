<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BicycleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'name'               => $this->name,
            'serialNumber'       => $this->serialNumber,
            'model'              => $this->model,
            'description'        => $this->description,
            'status'             => $this->status,
            // Whether this unit is part of the rentable fleet at all. Status alone
            // cannot express that: a parked bicycle is also `available` in older
            // data, and `out_of_service` is the state that now says it clearly.
            'iotEnabled'         => (bool) $this->iotEnabled,
            'inService'          => $this->isInService(),
            'rentable'           => $this->isRentable(),
            'hourlyRate'         => $this->hourlyRate,
            'currentLat'         => $this->currentLat ? (float) $this->currentLat : null,
            'currentLng'         => $this->currentLng ? (float) $this->currentLng : null,
            'batteryLevel'       => $this->batteryLevel,
            'lockStatus'         => $this->lockStatus,
            'qrCode'             => $this->qrCode,
            'totalRentals'       => $this->totalRentals,
            'totalDistance'      => $this->totalDistance,
            'condition'          => $this->condition,
            'currentRider'       => $this->currentRider,
            'currentRentalId'    => $this->currentRentalId,
            'lastGpsUpdate'      => $this->lastGpsUpdate,
            'lastHeartbeat'      => $this->lastHeartbeat,
            'distance'           => $this->when(isset($this->distance), $this->distance),
            'latestTelemetry'    => $this->whenLoaded('latestTelemetry'),
            'latestGpsLog'       => $this->whenLoaded('latestGpsLog'),
            'created_at'         => $this->created_at,
            'updated_at'         => $this->updated_at,
        ];
    }
}
