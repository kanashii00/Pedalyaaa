<?php

namespace App\Http\Resources;

use App\Models\Rental;
use App\Services\RentalService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RentalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'rentalId'          => $this->rentalId,
            'bicycleId'         => $this->bicycleId,
            'bicycleName'       => $this->bicycleName,
            'bicycleSerial'     => $this->bicycleSerial,
            'riderId'           => $this->riderId,
            'riderName'         => $this->riderName,
            'riderEmail'        => $this->riderEmail,
            'status'            => $this->status,
            'startTime'         => $this->startTime,
            'expectedEndTime'   => $this->expectedEndTime,
            'endTime'           => $this->endTime,
            'startLocation'     => $this->startLocation,
            'endLocation'       => $this->endLocation,
            'ratePerHour'       => $this->ratePerHour,
            'totalFee'          => $this->totalFee,
            'durationMinutes'   => $this->durationMinutes,
            'durationFormatted' => $this->durationFormatted,
            'chargedHours'      => $this->chargedHours,
            'totalDistance'     => $this->totalDistance,
            'paymentStatus'     => $this->paymentStatus,
            'paymentMethod'     => $this->paymentMethod,
            'paymentReference'  => $this->paymentReference,
            'notes'             => $this->notes,
            'cancelledBy'       => $this->cancelledBy,
            'cancelReason'      => $this->cancelReason,
            'approvedBy'        => $this->approvedBy,
            'approvedAt'        => $this->approvedAt,
            // Return workflow: what was asked for, when, what the inspection
            // found, and what it settled for. Without these an API client cannot
            // tell a pending return from a settled one, nor reconcile the amount
            // it was charged against the bicycle condition it left behind.
            'returnGraceStartedAt' => $this->returnGraceStartedAt,
            'returnGraceDeadline' => $this->returnGraceDeadline,
            'returnDeadline'    => $this->returnDeadline,
            'overdueReturnAt'   => $this->overdueReturnAt,
            'penaltyType'       => $this->penaltyType,
            'penaltyValue'      => $this->penaltyValue,
            'penaltyPolicy'     => [
                'type' => Rental::PENALTY_PER_5_MINUTES,
                'value' => RentalService::LATE_PENALTY_PER_COMPLETED_FIVE_MINUTES,
                'basis' => 'original_rental_end',
            ],
            'penaltyAccrued'    => $this->penaltyAccrued,
            'overdueFee'        => $this->overdueFee,
            'finalFee'          => $this->finalFee,
            'returnRequestedAt' => $this->returnRequestedAt,
            'returnCondition'   => $this->returnCondition,
            'returnInspectedBy' => $this->returnInspectedBy,
            'returnProcessedAt' => $this->returnProcessedAt,
            'returnNote'        => $this->returnNote,
            'isOverdue'         => (bool) $this->isOverdue,
            'bicycle'           => new BicycleResource($this->whenLoaded('bicycle')),
            'rider'             => new UserResource($this->whenLoaded('rider')),
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}
