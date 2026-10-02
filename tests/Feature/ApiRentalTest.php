<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\Rental;
use App\Services\IoTService;
use App\Services\RentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class ApiRentalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function test_active_lists_only_own_rentals_for_rider(): void
    {
        $rider = $this->makeRider();
        $other = $this->makeRider();
        Sanctum::actingAs($rider);

        $bike = $this->makeBicycle();
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);
        $this->makeRental(['riderId' => $other->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);

        $response = $this->getJson('/api/rentals/active')->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($rider->id, $data[0]['riderId'] ?? $data[0]['rider_id']);
    }

    public function test_active_lists_all_for_admin(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_PENDING]);

        $this->getJson('/api/rentals/active')->assertOk();
    }

    public function test_index_with_filters_returns_own_for_rider(): void
    {
        $rider = $this->makeRider();
        Sanctum::actingAs($rider);

        $bike = $this->makeBicycle();
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_COMPLETED]);

        $this->getJson('/api/rentals?status=completed')->assertOk();
        $this->getJson('/api/rentals?status=active')->assertOk();
    }

    public function test_index_filters_accept_every_rental_status(): void
    {
        $rider = $this->makeRider();
        Sanctum::actingAs($rider);

        foreach (Rental::STATUSES as $status) {
            $this->getJson('/api/rentals?status=' . $status)
                ->assertOk()
                ->assertJsonPath('meta.per_page', 20);
        }

        // A status the enum does not have is still rejected.
        $this->getJson('/api/rentals?status=not_a_status')->assertStatus(422);
    }

    public function test_return_is_idempotent_for_a_rental_already_awaiting_confirmation(): void
    {
        $rider = $this->makeRider();
        Sanctum::actingAs($rider);

        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
            'returnRequestedAt' => now()->subMinutes(10),
        ]);

        // Second tap of the Return button. markRideEnded() would have rejected
        // awaiting_return outright; the endpoint has to treat it as the no-op it
        // is, and must not claim a return it did not just record.
        $this->putJson("/api/rentals/{$rental->id}/return")
            ->assertOk()
            ->assertJsonPath('message', 'Return request already recorded and awaiting confirmation');

        $this->assertSame(
            Rental::STATUS_AWAITING_RETURN,
            $rental->fresh()->status,
            'the rental stays held until an administrator settles it'
        );
    }

    public function test_return_exposes_the_settlement_detail(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin, ['*']);

        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_RETURNED,
            'startTime' => now()->subHours(3),
            'endTime' => now()->subHour(),
            'returnDeadline' => now()->subHour(),
            'overdueReturnAt' => now()->subHour(),
            'penaltyType' => Rental::PENALTY_PER_HOUR,
            'penaltyValue' => 20,
            'penaltyAccrued' => 60,
            'overdueFee' => 60,
            'finalFee' => 135,
            'isOverdue' => true,
            'returnRequestedAt' => now()->subHour(),
            'returnProcessedAt' => now(),
            'returnCondition' => Rental::CONDITION_FAIR,
            'returnInspectedBy' => $admin->name,
        ]);

        $this->getJson("/api/rentals/{$rental->id}")
            ->assertOk()
            // DECIMAL columns surface as strings, not floats.
            ->assertJsonPath('data.finalFee', '135.00')
            ->assertJsonPath('data.overdueFee', '60.00')
            ->assertJsonPath('data.penaltyAccrued', '60.00')
            ->assertJsonPath('data.penaltyType', Rental::PENALTY_PER_HOUR)
            ->assertJsonPath('data.returnCondition', Rental::CONDITION_FAIR)
            ->assertJsonPath('data.returnInspectedBy', $admin->name)
            ->assertJsonPath('data.isOverdue', true);
    }

    public function test_show_own_rental_succeeds(): void
    {
        $rider = $this->makeRider();
        Sanctum::actingAs($rider);

        $bike = $this->makeBicycle();
        $rental = $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);

        $this->getJson("/api/rentals/{$rental->id}")->assertOk();
    }

    public function test_show_other_riders_rental_forbidden(): void
    {
        $rider = $this->makeRider();
        $other = $this->makeRider();
        Sanctum::actingAs($rider);

        $bike = $this->makeBicycle();
        $rental = $this->makeRental(['riderId' => $other->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);

        $this->getJson("/api/rentals/{$rental->id}")->assertStatus(403);
    }

    public function test_show_not_found(): void
    {
        $rider = $this->makeRider();
        Sanctum::actingAs($rider);
        $this->getJson('/api/rentals/99999')->assertStatus(404);
    }

    public function test_store_starts_rental(): void
    {
        $rider = $this->makeRider();
        Sanctum::actingAs($rider);

        $bike = $this->makeBicycle();
        $rental = $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);

        $service = Mockery::mock(RentalService::class);
        $service->shouldReceive('startRental')
            ->once()
            ->andReturn($rental);
        $this->app->instance(RentalService::class, $service);

        $this->postJson('/api/rentals', [
            'bicycle_id' => $bike->id,
            'duration_minutes' => 60,
        ])->assertStatus(201)->assertJsonPath('message', 'Rental started successfully');
    }

    public function test_store_validates_input(): void
    {
        $rider = $this->makeRider();
        Sanctum::actingAs($rider);

        $this->postJson('/api/rentals', [
            'bicycle_id' => 99999,
            'duration_minutes' => 10,
        ])->assertStatus(422);
    }

    public function test_return_rental_succeeds_for_owner(): void
    {
        $rider = $this->makeRider();
        Sanctum::actingAs($rider);

        $bike = $this->makeBicycle();
        $rental = $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);

        $service = Mockery::mock(RentalService::class);
        $service->shouldReceive('synchronizeLifecycle')->andReturn(['expired' => 0, 'overdue_return' => 0]);
        // The endpoint dispatches through returnRental(), which picks the right
        // half of the two-phase return for the current status.
        $service->shouldReceive('returnRental')
            ->once()
            ->andReturn([
                'rental'    => $rental->fresh()->load(['bicycle', 'rider']),
                'requested' => true,
            ]);
        $this->app->instance(RentalService::class, $service);

        $this->putJson("/api/rentals/{$rental->id}/return", [
            'return_lat' => 14.6,
            'return_lng' => 120.99,
            'payment_method' => 'cash',
        ])->assertOk()->assertJsonPath('message', 'Bicycle returned and awaiting confirmation');
    }

    public function test_return_unauthorized_for_other_rider(): void
    {
        $rider = $this->makeRider();
        $other = $this->makeRider();
        Sanctum::actingAs($rider);

        $bike = $this->makeBicycle();
        $rental = $this->makeRental(['riderId' => $other->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);

        $this->putJson("/api/rentals/{$rental->id}/return")->assertStatus(403);
    }

    public function test_return_not_found(): void
    {
        $rider = $this->makeRider();
        Sanctum::actingAs($rider);
        $this->putJson('/api/rentals/99999/return')->assertStatus(404);
    }

    public function test_approve_pending_rental_as_admin(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin, ['*']);

        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $rental = $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_PENDING]);

        $iot = Mockery::mock(IoTService::class);
        $iot->shouldReceive('sendCommand')->once()->andReturn(
            \App\Models\DeviceCommand::create(['bicycleId' => $bike->id, 'command' => 'unlock', 'status' => 'pending'])
        );
        $this->app->instance(IoTService::class, $iot);

        $this->putJson("/api/rentals/{$rental->id}/approve")
            ->assertOk()
            ->assertJsonPath('message', 'Rental approved successfully');

        $this->assertSame(Rental::STATUS_ACTIVE, $rental->fresh()->status);
        $this->assertSame('rented', $bike->fresh()->status);
    }

    public function test_approve_non_pending_rejected(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin, ['*']);

        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);

        $this->putJson("/api/rentals/{$rental->id}/approve")->assertStatus(422);
    }

    public function test_approve_requires_admin_role(): void
    {
        $rider = $this->makeRider();
        Sanctum::actingAs($rider, ['*']);

        $bike = $this->makeBicycle();
        $rental = $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_PENDING]);

        $this->putJson("/api/rentals/{$rental->id}/approve")->assertForbidden();
    }

    public function test_cancel_own_rental(): void
    {
        $rider = $this->makeRider();
        Sanctum::actingAs($rider);

        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);
        $rental = $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);
        $bike->update(['currentRentalId' => $rental->id]);

        $iot = Mockery::mock(IoTService::class);
        $iot->shouldReceive('sendCommand')->once()->andReturn(
            \App\Models\DeviceCommand::create(['bicycleId' => $bike->id, 'command' => 'lock', 'status' => 'pending'])
        );
        $this->app->instance(IoTService::class, $iot);

        $this->putJson("/api/rentals/{$rental->id}/cancel", ['notes' => 'changed mind'])
            ->assertOk()
            ->assertJsonPath('message', 'Rental cancelled successfully');

        $this->assertSame(Rental::STATUS_CANCELLED, $rental->fresh()->status);
        $this->assertSame('available', $bike->fresh()->status);
    }

    public function test_cancel_completed_rejected(): void
    {
        $rider = $this->makeRider();
        Sanctum::actingAs($rider);

        $bike = $this->makeBicycle();
        $rental = $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_COMPLETED]);

        $this->putJson("/api/rentals/{$rental->id}/cancel")->assertStatus(422);
    }
}
