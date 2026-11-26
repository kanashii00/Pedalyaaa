<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\Notification;
use App\Models\Rental;
use App\Services\IoTService;
use App\Services\RentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * The five-minute return grace is no longer something an administrator types
 * in: the bicycle's own lock reports it parked and the window opens by itself.
 *
 * These tests pin down the three things that has to stay true - the unlock ->
 * lock transition is what counts for a running ride (so a heartbeat arriving
 * before the unlock is acknowledged cannot end a ride that has not begun), the
 * window is idempotent, and once it lapses the existing deadline -> penalty
 * pipeline still fires.
 */
class ReturnGraceDetectionTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    private function park(IoTService $iot, Bicycle $bicycle): void
    {
        $iot->processHeartbeat([
            'bicycleId' => $bicycle->id,
            'locked' => true,
            'batteryLevel' => 80,
        ]);
    }

    public function test_early_parking_anchors_the_five_minute_return_period_to_scheduled_end(): void
    {
        $this->travelTo(now()->startOfSecond());

        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle([
            'status' => Bicycle::STATUS_RENTED,
            'lockStatus' => Bicycle::LOCK_UNLOCKED,
        ]);

        $expectedEnd = now()->addMinutes(40);
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => now()->subMinutes(20),
            'expectedEndTime' => $expectedEnd,
        ]);

        $this->park(app(IoTService::class), $bike);

        $rental = $rental->fresh();

        $this->assertSame(Rental::STATUS_AWAITING_RETURN, $rental->status);
        $this->assertSame('IoT park detection', $rental->returnDeadlineSetBy);
        $this->assertTrue($rental->returnDeadline->equalTo($expectedEnd->copy()->addSeconds(300)));
        $this->assertTrue($rental->returnGraceDeadline->equalTo($rental->returnDeadline));

        // The lock re-engaging opens the return flow, but the physical arrival
        // is still verified and recorded by an administrator during Process
        // Return. Only the request/detection instant is recorded here.
        $this->assertNull($rental->endTime);
        $this->assertNotNull($rental->returnRequestedAt);

        // No per-rental rule is written; the fixed policy remains centralized.
        $this->assertNull($rental->penaltyType);
        $this->assertNull($rental->penaltyValue);

        $this->assertSame(
            1,
            Notification::where('userId', $rider->id)
                ->where('type', 'rental_deadline')
                ->where('title', 'Return Grace Started')
                ->count()
        );

        $this->assertSame(
            1,
            Notification::where('userId', $admin->id)
                ->where('type', 'rental_deadline')
                ->where('title', 'Return Deadline Auto-Set')
                ->count()
        );
    }

    public function test_parking_after_expiry_keeps_the_existing_grace_deadline(): void
    {
        $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle([
            'status' => Bicycle::STATUS_RENTED,
            'lockStatus' => Bicycle::LOCK_UNLOCKED,
        ]);
        $expectedEnd = now()->subMinutes(2);
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => $expectedEnd->copy()->subHour(),
            'expectedEndTime' => $expectedEnd,
        ]);

        app(RentalService::class)->expireDueRentals(now());
        $storedGraceDeadline = $rental->fresh()->returnGraceDeadline;
        $this->assertSame(
            $expectedEnd->copy()->addMinutes(5)->getTimestamp(),
            $storedGraceDeadline->getTimestamp()
        );

        $this->park(app(IoTService::class), $bike);

        $rental->refresh();
        $this->assertSame(Rental::STATUS_AWAITING_RETURN, $rental->status);
        $this->assertTrue($rental->returnDeadline->equalTo($storedGraceDeadline));
        $this->assertTrue($rental->returnGraceDeadline->equalTo($storedGraceDeadline));
    }

    public function test_a_heartbeat_before_the_unlock_ack_never_ends_a_fresh_ride(): void
    {
        $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle([
            'status' => Bicycle::STATUS_RENTED,
            'lockStatus' => Bicycle::LOCK_LOCKED,
        ]);

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => now(),
            'expectedEndTime' => now()->addMinutes(60),
        ]);

        // The bicycle is still carrying the parked lock state it had before the
        // rental started, so this heartbeat is not a park: it is the unit
        // reporting the state the queued unlock has not moved it out of yet.
        $this->park(app(IoTService::class), $bike);

        $rental = $rental->fresh();

        $this->assertSame(Rental::STATUS_ACTIVE, $rental->status);
        $this->assertNull($rental->returnDeadline);
        $this->assertNull($rental->endTime);
        $this->assertSame(0, Notification::where('title', 'Return Grace Started')->count());
    }

    public function test_the_grace_window_is_idempotent_across_heartbeats(): void
    {
        $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle([
            'status' => Bicycle::STATUS_RENTED,
            'lockStatus' => Bicycle::LOCK_UNLOCKED,
        ]);

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => now()->subMinutes(10),
            'expectedEndTime' => now()->addMinutes(50),
        ]);

        $iot = app(IoTService::class);

        $this->park($iot, $bike);
        $firstDeadline = $rental->fresh()->returnDeadline;
        $this->assertNotNull($firstDeadline);

        $this->travel(90)->seconds();
        $this->park($iot, $bike);
        $this->park($iot, $bike);

        $this->assertTrue($rental->fresh()->returnDeadline->equalTo($firstDeadline));
        $this->assertSame(
            1,
            Notification::where('userId', $rider->id)
                ->where('type', 'rental_deadline')
                ->where('title', 'Return Grace Started')
                ->count()
        );
    }

    public function test_an_expired_rental_uses_its_scheduled_grace_deadline_without_an_end_time(): void
    {
        $this->travelTo(now()->startOfSecond());

        $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle([
            'status' => Bicycle::STATUS_RENTED,
            'lockStatus' => Bicycle::LOCK_UNLOCKED,
        ]);
        $expectedEnd = now()->subMinutes(10);

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_EXPIRED,
            'startTime' => now()->subHours(2),
            'expectedEndTime' => $expectedEnd,
            'expiredAt' => $expectedEnd,
        ]);

        $this->park(app(IoTService::class), $bike);

        $rental = $rental->fresh();

        $this->assertSame(Rental::STATUS_AWAITING_RETURN, $rental->status);
        $this->assertTrue($rental->returnDeadline->equalTo($expectedEnd->copy()->addSeconds(300)));

        // The expiry path has never recorded an end time, and settlement does
        // not need one, so the automatic window must not invent one either.
        $this->assertNull($rental->endTime);

        app(RentalService::class)->markOverdueReturnDeadlines(now());
        $rental->refresh();

        $this->assertSame(Rental::STATUS_OVERDUE_RETURN, $rental->status);
        $this->assertSame(20.0, (float) $rental->penaltyAccrued);
    }

    public function test_an_administered_deadline_survives_the_park_detection(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle([
            'status' => Bicycle::STATUS_RENTED,
            'lockStatus' => Bicycle::LOCK_UNLOCKED,
        ]);

        $adminDeadline = now()->addMinutes(45)->startOfMinute();

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
            'startTime' => now()->subHours(2),
            'expectedEndTime' => now()->subMinutes(30),
            'returnDeadline' => $adminDeadline,
            'returnDeadlineSetBy' => $admin->name,
        ]);

        $this->park(app(IoTService::class), $bike);

        $rental = $rental->fresh();

        $this->assertTrue($rental->returnDeadline->equalTo($adminDeadline));
        $this->assertSame($admin->name, $rental->returnDeadlineSetBy);
        $this->assertSame(0, Notification::where('title', 'Return Grace Started')->count());
    }

    public function test_the_return_grace_is_always_five_minutes(): void
    {
        $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle([
            'status' => Bicycle::STATUS_RENTED,
            'lockStatus' => Bicycle::LOCK_UNLOCKED,
        ]);

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => now()->subMinutes(10),
            'expectedEndTime' => now()->addMinutes(50),
        ]);

        $this->park(app(IoTService::class), $bike);

        $rental = $rental->fresh();

        $this->assertSame(Rental::STATUS_AWAITING_RETURN, $rental->status);
        $this->assertSame(
            $rental->expectedEndTime->copy()->addSeconds(RentalService::RETURN_GRACE_SECONDS)->getTimestamp(),
            $rental->returnDeadline->getTimestamp()
        );
    }

    public function test_early_park_keeps_return_period_anchored_to_the_scheduled_end(): void
    {
        $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle([
            'status' => Bicycle::STATUS_RENTED,
            'lockStatus' => Bicycle::LOCK_UNLOCKED,
        ]);

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => now()->subMinutes(20),
            'expectedEndTime' => now()->addMinutes(40),
        ]);

        $this->park(app(IoTService::class), $bike);

        $this->assertSame(Rental::STATUS_AWAITING_RETURN, $rental->fresh()->status);

        // A delayed park report or early park cannot move the authoritative
        // return-period deadline from scheduled end + five minutes.
        $this->travel(11)->minutes();

        Artisan::call('rentals:check-return-deadlines');

        $rental = $rental->fresh();

        $this->assertSame(Rental::STATUS_AWAITING_RETURN, $rental->status);
        $this->assertSame(0.0, (float) $rental->penaltyAccrued);
        $this->assertNull($rental->overdueReturnAt);
    }

    public function test_late_fee_begins_after_scheduled_end_plus_five_minute_grace(): void
    {
        $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle([
            'status' => Bicycle::STATUS_RENTED,
            'lockStatus' => Bicycle::LOCK_UNLOCKED,
        ]);

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => now()->subMinutes(20),
            'expectedEndTime' => now()->subMinutes(6),
        ]);

        $this->park(app(IoTService::class), $bike);
        $this->travel(11)->minutes();

        Artisan::call('rentals:check-return-deadlines');

        $rental = $rental->fresh();

        $this->assertSame(Rental::STATUS_OVERDUE_RETURN, $rental->status);
        // 12 minutes past the return deadline = two completed blocks.
        $this->assertSame(40.0, (float) $rental->penaltyAccrued);
    }
}
