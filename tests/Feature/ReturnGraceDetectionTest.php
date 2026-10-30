<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\Notification;
use App\Models\Rental;
use App\Models\SystemSetting;
use App\Services\IoTService;
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
    use RefreshDatabase;
    use CreatesTestData;

    private function park(IoTService $iot, Bicycle $bicycle): void
    {
        $iot->processHeartbeat([
            'bicycleId' => $bicycle->id,
            'locked' => true,
            'batteryLevel' => 80,
        ]);
    }

    public function test_parking_a_running_rental_opens_the_five_minute_grace(): void
    {
        $admin = $this->makeAdmin();
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

        $this->travelTo(now()->startOfSecond());

        $this->park(app(IoTService::class), $bike);

        $rental = $rental->fresh();

        $this->assertSame(Rental::STATUS_AWAITING_RETURN, $rental->status);
        $this->assertSame('IoT park detection', $rental->returnDeadlineSetBy);
        $this->assertTrue($rental->returnDeadline->equalTo(now()->addSeconds(300)));

        // The lock re-engaging opens the return flow, but the physical arrival
        // is still verified and recorded by an administrator during Process
        // Return. Only the request/detection instant is recorded here.
        $this->assertNull($rental->endTime);
        $this->assertNotNull($rental->returnRequestedAt);

        // No per-rental rule is written: the global late-return rate stays the
        // source of truth until an administrator overrides it.
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

    public function test_an_expired_rental_gets_the_grace_without_an_end_time(): void
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
            'status' => Rental::STATUS_EXPIRED,
            'startTime' => now()->subHours(2),
            'expectedEndTime' => now()->subMinutes(10),
            'expiredAt' => now()->subMinutes(10),
        ]);

        $this->travelTo(now()->startOfSecond());

        $this->park(app(IoTService::class), $bike);

        $rental = $rental->fresh();

        $this->assertSame(Rental::STATUS_AWAITING_RETURN, $rental->status);
        $this->assertTrue($rental->returnDeadline->equalTo(now()->addSeconds(300)));

        // The expiry path has never recorded an end time, and settlement does
        // not need one, so the automatic window must not invent one either.
        $this->assertNull($rental->endTime);
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

    public function test_a_zero_second_grace_disables_the_automatic_window(): void
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

        SystemSetting::setValue('returnGraceSeconds', 0);

        $this->park(app(IoTService::class), $bike);

        $rental = $rental->fresh();

        $this->assertSame(Rental::STATUS_ACTIVE, $rental->status);
        $this->assertNull($rental->returnDeadline);
    }

    public function test_a_lapsed_grace_window_accrues_the_global_late_penalty(): void
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

        // Grace window (5 minutes) + 6 minutes overdue => one completed
        // 5-minute interval, charged at the default PHP 20.00 global rate that
        // resolvedPenaltyRule() falls back to when no per-rental rule was saved.
        $this->travel(11)->minutes();

        Artisan::call('rentals:check-return-deadlines');

        $rental = $rental->fresh();

        $this->assertSame(Rental::STATUS_OVERDUE_RETURN, $rental->status);
        $this->assertSame(20.0, (float) $rental->penaltyAccrued);
        $this->assertNotNull($rental->overdueReturnAt);
    }
}
