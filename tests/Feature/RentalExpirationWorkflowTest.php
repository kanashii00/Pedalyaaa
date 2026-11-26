<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bicycle;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\RentalService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class RentalExpirationWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function test_expiration_marks_rental_expired_and_notifies_only_admins(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
            'expectedEndTime' => now()->subMinutes(5),
        ]);

        $exit = Artisan::call('rentals:check-overdue');

        $this->assertSame(0, $exit);
        $this->assertDatabaseHas('rentals', [
            'id' => $rental->id,
            'status' => Rental::STATUS_EXPIRED,
        ]);
        $this->assertNotNull($rental->fresh()->expiredAt);

        $this->assertSame(1, Notification::where('userId', $admin->id)->where('type', 'rental_expired')->count());
        $this->assertSame(0, Notification::where('userId', $rider->id)->where('type', 'rental_expired')->count());
    }

    public function test_expiration_command_with_no_due_rentals_is_idle(): void
    {
        $this->makeRental([
            'status' => Rental::STATUS_ACTIVE,
            'expectedEndTime' => now()->addHours(1),
        ]);

        $exit = Artisan::call('rentals:check-overdue');

        $this->assertSame(0, $exit);
        $this->assertSame(0, Notification::count());
    }

    public function test_set_deadline_route_creates_awaiting_return_notifies_rider_and_audits(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_EXPIRED,
            'expiredAt' => now()->subHour(),
            'expectedEndTime' => now()->subHour(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.set-deadline', $rental->id), [
                'minutes' => 90,
                'message' => 'Please return the bicycle on time.',
            ])
            ->assertRedirect(route('admin.rentals.returns'));

        $fresh = $rental->fresh();
        $this->assertSame(Rental::STATUS_AWAITING_RETURN, $fresh->status);
        $this->assertNotNull($fresh->returnDeadline);
        // Deadline = server time + 90 minutes.
        $this->assertTrue($fresh->returnDeadline->between(now()->addMinutes(89), now()->addMinutes(91)));
        $this->assertSame($admin->name, $fresh->returnDeadlineSetBy);
        $this->assertNull($fresh->penaltyType);
        $this->assertNull($fresh->penaltyValue);

        $this->assertSame(1, Notification::where('userId', $rider->id)->where('type', 'rental_deadline')->count());
        $this->assertStringContainsString(
            'PHP 20 for each completed 5-minute block after the original rental end time',
            Notification::where('userId', $rider->id)->where('type', 'rental_deadline')->first()->message
        );
        $this->assertDatabaseHas('audit_logs', ['action' => 'rental_deadline_set']);
    }

    public function test_set_deadline_route_uses_the_fixed_penalty_policy(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_EXPIRED,
            'expiredAt' => now()->subHour(),
            'expectedEndTime' => now()->subHour(),
        ]);

        // The deadline controls the return workflow only; fees stay fixed.
        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.set-deadline', $rental->id), ['minutes' => 30])
            ->assertRedirect(route('admin.rentals.returns'));

        $fresh = $rental->fresh();
        $this->assertNull($fresh->penaltyType);
        $this->assertNull($fresh->penaltyValue);
        $this->assertTrue($fresh->returnDeadline->between(now()->addMinutes(29), now()->addMinutes(31)));

        $notification = Notification::where('userId', $rider->id)->where('type', 'rental_deadline')->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('PHP 20 for each completed 5-minute block', $notification->message);
    }

    public function test_set_deadline_route_requires_minutes_or_deadline(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_EXPIRED,
            'expiredAt' => now()->subHour(),
            'expectedEndTime' => now()->subHour(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.set-deadline', $rental->id), [])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionHasErrors('minutes');

        $this->assertSame(Rental::STATUS_EXPIRED, $rental->fresh()->status);
    }

    public function test_manual_deadline_action_is_replaced_by_the_live_return_countdown(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
            'expiredAt' => now()->subHour(),
            'expectedEndTime' => now()->subHour(),
            'returnDeadline' => now()->addMinutes(5),
            'returnDeadlineSetBy' => 'IoT park detection',
        ]);

        // The five-minute return grace is opened by the bicycle's lock
        // heartbeat, so neither admin page offers a "type your own deadline and
        // penalty" dialog any more. The countdown that replaced it must be
        // rendered instead, and the route itself stays wired up for the
        // programmatic callers that still use it.
        $urls = [
            route('admin.rentals.returns'),
        ];
        foreach ($urls as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('id="rvDeadlineForm"', $html);
            $this->assertStringNotContainsString('id="prDeadlineForm"', $html);
            $this->assertStringNotContainsString('data-deadline-open', $html);
            $this->assertStringNotContainsString('data-return-deadline-open', $html);
            $this->assertStringNotContainsString('Set return deadline', $html);
            $this->assertStringNotContainsString('name="minutes"', $html);

            $this->assertStringContainsString(
                'data-return-countdown="' . $rental->returnDeadline->toIso8601String() . '"',
                $html
            );
            $this->assertStringContainsString('data-return-countdown-text', $html);
        }

        $this->assertStringContainsString(
            '/admin/rentals/' . $rental->id . '/set-deadline',
            route('admin.rentals.set-deadline', $rental->id)
        );
    }

    public function test_set_deadline_route_accepts_every_preset_minutes_value(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        foreach ([10, 15, 30, 60] as $minutes) {
            $rental = $this->makeRental([
                'riderId' => $rider->id,
                'bicycleId' => $bike->id,
                'status' => Rental::STATUS_EXPIRED,
                'expiredAt' => now()->subHour(),
                'expectedEndTime' => now()->subHour(),
            ]);

            $this->actingAs($admin)
                ->from(route('admin.rentals.index'))
                ->put(route('admin.rentals.set-deadline', $rental->id), [
                    'minutes' => $minutes,
                ])
                ->assertRedirect(route('admin.rentals.index'))
                ->assertSessionDoesntHaveErrors();

            $fresh = $rental->fresh();
            $this->assertSame(Rental::STATUS_AWAITING_RETURN, $fresh->status);
            // Deadline = server time + the submitted minutes.
            $this->assertTrue(
                $fresh->returnDeadline->between(now()->addMinutes($minutes - 1), now()->addMinutes($minutes + 1)),
                "Expected a deadline about {$minutes} minutes out."
            );
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'rental_deadline_set',
                'userId' => $admin->id,
            ]);
            $audit = AuditLog::where('action', 'rental_deadline_set')
                ->orderByDesc('id')
                ->first();
            $this->assertSame($rental->rentalId, $audit->details['rentalId'] ?? null);
            $this->assertSame($minutes, $audit->details['allowed_minutes'] ?? null);
        }
    }

    /**
     * True when the element carrying $inputId is a descendant of the <form>
     * carrying $formId in the rendered HTML.
     */
    public function test_set_deadline_guard_rejects_non_eligible_statuses(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.set-deadline', $rental->id), [
                'deadline' => now()->addHours(1)->format('Y-m-d\TH:i'),
            ])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionHasErrors('rental');

        $this->assertSame(Rental::STATUS_ACTIVE, $rental->fresh()->status);
    }

    public function test_set_deadline_repeating_unchanged_value_is_idempotent(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_EXPIRED,
            'expiredAt' => now()->subHour(),
            'expectedEndTime' => now()->subHour(),
        ]);

        $service = app(RentalService::class);
        $deadline = now()->addHours(2);

        $service->setReturnDeadline($rental, $admin, $deadline);
        $service->setReturnDeadline($rental->fresh(), $admin, $deadline);

        $this->assertSame(1, Notification::where('userId', $rider->id)->where('type', 'rental_deadline')->count());
        $this->assertDatabaseHas('rentals', [
            'id' => $rental->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
        ]);
    }

    public function test_deadline_pass_marks_overdue_return_and_notifies_rider_and_admins(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
            'startTime' => now()->subHours(3),
            'expectedEndTime' => now()->subHour(),
            'returnRequestedAt' => now()->subHours(2),
            'returnDeadline' => now()->subMinutes(10),
        ]);

        $exit = Artisan::call('rentals:check-return-deadlines');

        $this->assertSame(0, $exit);
        $this->assertDatabaseHas('rentals', [
            'id' => $rental->id,
            'status' => Rental::STATUS_OVERDUE_RETURN,
        ]);
        $this->assertNotNull($rental->fresh()->overdueReturnAt);

        $this->assertSame(1, Notification::where('userId', $rider->id)->where('type', 'rental_overdue_return')->count());
        $this->assertSame(1, Notification::where('userId', $admin->id)->where('type', 'rental_overdue_return')->count());
    }

    public function test_request_return_is_idempotent(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
            'returnRequestedAt' => null,
        ]);

        $service = app(RentalService::class);

        $first = $service->requestReturn($rental, $rider);
        $this->assertTrue($first['requested']);
        $firstRequestedAt = $rental->fresh()->returnRequestedAt;
        $this->assertNotNull($firstRequestedAt);

        $second = $service->requestReturn($rental->fresh(), $rider);
        $this->assertFalse($second['requested']);

        $this->assertSame(1, Notification::where('type', 'return_requested')->where('userId', $admin->id)->count());
        $this->assertSame(
            $firstRequestedAt->toDateTimeString(),
            $rental->fresh()->returnRequestedAt->toDateTimeString()
        );
    }

    public function test_rider_web_route_ends_ride_for_active_rental(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED, 'lockStatus' => Bicycle::LOCK_UNLOCKED]);

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
        ]);

        $this->actingAs($rider)
            ->from(route('rider.dashboard'))
            ->post(route('rider.rentals.return', $rental->id))
            ->assertRedirect(route('rider.dashboard'));

        $this->assertDatabaseHas('rentals', [
            'id' => $rental->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
        ]);
        $this->assertNotNull($rental->fresh()->returnRequestedAt);
    }

    public function test_rider_web_route_for_past_deadline_rental_records_return_request_and_notifies_admins(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_OVERDUE_RETURN,
            'returnRequestedAt' => null,
            'returnDeadline' => now()->subHours(2),
            'overdueReturnAt' => now()->subHours(2),
        ]);

        $this->actingAs($rider)
            ->from(route('rider.dashboard'))
            ->post(route('rider.rentals.return', $rental->id))
            ->assertRedirect(route('rider.dashboard'));

        $this->assertSame(Rental::STATUS_OVERDUE_RETURN, $rental->fresh()->status);
        $this->assertNotNull($rental->fresh()->returnRequestedAt);
        $this->assertSame(1, Notification::where('type', 'return_requested')->where('userId', $admin->id)->count());
    }

    public function test_process_return_applies_late_penalty_once_from_deadline(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);

        SystemSetting::setValue('lateReturnPenaltyPerHour', 20);

        // Freeze the clock so the 3-hour lateness resolves to exactly 36
        // completed 5-minute intervals regardless of wall-clock drift.
        $frozenNow = now();
        Carbon::setTestNow($frozenNow);
        $deadline = $frozenNow->copy()->startOfMinute()->subHours(3);

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_OVERDUE_RETURN,
            'startTime' => $frozenNow->copy()->subHours(5),
            'expectedEndTime' => $frozenNow->copy()->subHours(4),
            'returnDeadline' => $deadline,
            // A later operational grace deadline must not move the original
            // rental deadline used for the late-penalty calculation.
            'expectedEndTime' => $deadline,
            'returnDeadlineSetBy' => $admin->name,
            'overdueReturnAt' => $deadline,
            'ratePerHour' => 15.00,
            'totalFee' => 75.00,
            'durationMinutes' => 300,
            'durationFormatted' => '5h 0m',
            'chargedHours' => 5,
            'paymentStatus' => 'pending',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'condition' => 'good',
                'returnTime' => $frozenNow->format('Y-m-d\TH:i'),
                'note' => 'All good',
            ])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionDoesntHaveErrors();

        Carbon::setTestNow(); // unfreeze once the charge is frozen server-side

        $fresh = $rental->fresh();

        // Base fee stays the stored Estimated Cost (₱75.00); the fixed ₱20.00
        // rate charges 36 completed 5-minute intervals for 3 hours late.
        $this->assertSame(Rental::STATUS_RETURNED, $fresh->status);
        $this->assertSame(720.0, (float) $fresh->overdueFee);
        $this->assertSame(795.0, (float) $fresh->finalFee);
        $this->assertSame(75.0, (float) $fresh->totalFee);
        $this->assertSame($admin->name, $fresh->returnInspectedBy);

        $this->assertDatabaseHas('payments', [
            'rentalId' => $rental->id,
            'status' => 'paid',
            'totalAmount' => 795.0,
        ]);

        $this->assertDatabaseHas('bicycles', [
            'id' => $bike->id,
            'status' => Bicycle::STATUS_AVAILABLE,
            'currentRentalId' => null,
            'currentRider' => null,
            'lockStatus' => Bicycle::LOCK_LOCKED,
        ]);

        $this->assertSame(1, Notification::where('userId', $rider->id)->where('type', 'rental_completed')->count());

        // Confirming a second time must be rejected and must not duplicate records.
        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), ['condition' => 'good'])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionHasErrors();

        $this->assertSame(1, Notification::where('userId', $rider->id)->where('type', 'rental_completed')->count());
        $this->assertSame(1, Payment::where('rentalId', $rental->id)->count());
    }

    public function test_process_return_keeps_the_stored_base_and_charges_every_completed_5_minutes(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);

        // Freeze the clock so the 28-minute lateness resolves to exactly five
        // completed 5-minute intervals no matter when the suite runs. Their
        // example: deadline 11:33 PM, actual arrival 12:01 AM => 28 minutes
        // late => 5 intervals => ₱100.00.
        $frozenNow = now();
        Carbon::setTestNow($frozenNow);
        $deadline = $frozenNow->copy()->startOfMinute()->subMinutes(28);

        // A one-minute rental: the stored Estimated Cost is ₱15.00 and the
        // stored deadline passed before the actual arrival.
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_OVERDUE_RETURN,
            'startTime' => $frozenNow->copy()->subMinutes(29),
            'expectedEndTime' => $deadline,
            'returnDeadline' => $deadline,
            'returnDeadlineSetBy' => $admin->name,
            'overdueReturnAt' => $deadline,
            'ratePerHour' => 15.00,
            'totalFee' => 15.00,
            'durationMinutes' => 1,
            'durationFormatted' => '1m 0s',
            'chargedHours' => 1,
            'paymentStatus' => 'pending',
        ]);

        // The modal preview is display-only: no fee/penalty/total field is sent,
        // and the server derives every amount from the original rental end.
        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'condition' => Rental::CONDITION_GOOD,
                'return_time' => $frozenNow->format('Y-m-d\TH:i'),
            ])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionDoesntHaveErrors();

        Carbon::setTestNow(); // unfreeze once the charge is frozen server-side

        $fresh = $rental->fresh();

        $this->assertSame(Rental::STATUS_RETURNED, $fresh->status);
        // Late penalty = 5 completed 5-minute intervals x the default ₱20.00.
        $this->assertSame(100.0, (float) $fresh->overdueFee);
        // Base fee stays the stored Estimated Cost, not the recomputed ~29-minute
        // ride; final = base + late penalty = 15.00 + 100.00.
        $this->assertSame(15.0, round((float) $fresh->finalFee - (float) $fresh->overdueFee, 2));
        $this->assertSame(115.0, (float) $fresh->finalFee);
        $this->assertSame(15.0, (float) $fresh->totalFee);

        $this->assertDatabaseHas('payments', [
            'rentalId' => $rental->id,
            'status' => 'paid',
            'totalAmount' => 115.0,
        ]);
    }

    public function test_process_return_without_return_deadline_uses_original_end_penalty(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);

        // 4h ride, returned 2.5h past the expected end. The fixed penalty is
        // 30 completed 5-minute blocks @ PHP 20 = 600; the stored base remains
        // PHP 15, so the final fee is PHP 615.
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
            'startTime' => now()->subHours(4),
            'expectedEndTime' => now()->subHours(2)->subMinutes(30),
            'returnRequestedAt' => now()->subMinutes(45),
            'ratePerHour' => 15.00,
            'paymentStatus' => 'pending',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'condition' => Rental::CONDITION_GOOD,
                'returnTime' => now()->format('Y-m-d\TH:i'),
            ])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionDoesntHaveErrors();

        $fresh = $rental->fresh();
        $this->assertSame(Rental::STATUS_RETURNED, $fresh->status);
        $this->assertSame(600.0, (float) $fresh->overdueFee);
        $this->assertSame(15.0, round((float) $fresh->finalFee - (float) $fresh->overdueFee, 2));
        $this->assertSame(615.0, (float) $fresh->finalFee);
    }

    public function test_late_penalty_only_counts_completed_five_minute_intervals(): void
    {
        $service = app(RentalService::class);
        $deadline = Carbon::parse('2026-10-24 23:33:00');

        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_OVERDUE_RETURN,
            'returnDeadline' => $deadline,
            'expectedEndTime' => $deadline,
            'penaltyType' => null,
            'penaltyValue' => null,
        ]);

        // 4:59 late is inside the current 5-minute interval: no penalty.
        $this->assertSame(0.0, $service->calculatePenaltyForRental(
            $rental,
            $deadline->copy()->addMinutes(4)->addSeconds(59)
        ));

        // Exactly 5:00 late completes the first interval.
        $this->assertSame(20.0, $service->calculatePenaltyForRental(
            $rental,
            $deadline->copy()->addMinutes(5)
        ));

        $this->assertSame(40.0, $service->calculatePenaltyForRental($rental, $deadline->copy()->addMinutes(10)));
        $this->assertSame(60.0, $service->calculatePenaltyForRental($rental, $deadline->copy()->addMinutes(15)));

        // Timestamp arithmetic crosses midnight without resetting elapsed time:
        // 23:33 -> 00:38 is 65 minutes, or 13 completed intervals.
        $this->assertSame(260.0, $service->calculatePenaltyForRental(
            $rental,
            Carbon::parse('2026-10-25 00:38:00')
        ));

        // 28 minutes completes five intervals.
        $this->assertSame(100.0, $service->calculatePenaltyForRental(
            $rental,
            $deadline->copy()->addMinutes(28)
        ));
        $this->assertSame(120.0, $service->calculatePenaltyForRental($rental, $deadline->copy()->addMinutes(30)));
    }

    public function test_process_return_accepts_camel_case_time_and_ignores_client_fee_fields(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);

        Carbon::setTestNow(Carbon::parse('2026-10-24 23:42:00'));
        $deadline = Carbon::parse('2026-10-24 23:33:00');

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_OVERDUE_RETURN,
            'startTime' => Carbon::parse('2026-10-24 23:32:00'),
            'expectedEndTime' => $deadline,
            'returnDeadline' => $deadline,
            'overdueReturnAt' => $deadline,
            'ratePerHour' => 15.00,
            'totalFee' => 15.00,
            'durationMinutes' => 1,
            'durationFormatted' => '1m 0s',
            'chargedHours' => 1,
            'paymentStatus' => 'pending',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'condition' => Rental::CONDITION_GOOD,
                // The camelCase key is accepted too, not silently replaced by
                // the server's current time.
                'returnTime' => '2026-10-24 23:42:00',
                'return_time_evidence' => 'Dock camera timestamp confirms physical arrival.',
                // Tampered preview values must never be charged.
                'baseFee' => 1.00,
                'latePenalty' => 1.00,
                'overdueFee' => 1.00,
                'finalFee' => 1.00,
                'totalFee' => 1.00,
            ])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionDoesntHaveErrors();

        Carbon::setTestNow();

        $fresh = $rental->fresh();
        // 9 completed minutes => one 5-minute interval = PHP 20.
        $this->assertSame(20.0, (float) $fresh->overdueFee);
        $this->assertSame(15.0, (float) $fresh->totalFee, 'the stored estimate remains the base fee');
        $this->assertSame(15.0, round((float) $fresh->finalFee - (float) $fresh->overdueFee, 2));
        $this->assertSame(35.0, (float) $fresh->finalFee);
    }

    public function test_late_penalty_is_always_20_per_completed_five_minutes(): void
    {
        SystemSetting::setValue('lateReturnPenaltyPerHour', 30);

        $service = app(RentalService::class);

        $penalty = $service->calculateLateReturnPenalty(
            now()->subMinutes(150),
            now()
        );

        // 150 minutes late = 30 completed five-minute blocks @ PHP 20.
        $this->assertSame(600.0, $penalty);

        $none = $service->calculateLateReturnPenalty(now()->addHour(), now());
        $this->assertSame(0.0, $none);
    }

    public function test_request_return_rejects_irrelevant_statuses(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_RETURNED,
        ]);

        $service = app(RentalService::class);

        $this->expectException(\App\Exceptions\RentalException::class);
        $service->requestReturn($rental, $rider);
    }

    public function test_calculate_penalty_ignores_legacy_fixed_rule_values(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        $deadline = now()->subMinutes(90);

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
            'returnDeadline' => $deadline,
            'expectedEndTime' => $deadline,
            'penaltyType' => Rental::PENALTY_FIXED,
            'penaltyValue' => 120.00,
        ]);

        $service = app(RentalService::class);

        // Legacy fixed values are ignored; the current fixed rule is per 5 min.
        $this->assertSame(360.0, $service->calculatePenaltyForRental($rental, now()));

        // Not yet overdue => no penalty.
        $rental->returnDeadline = now()->addHour();
        $rental->expectedEndTime = now()->addHour();
        $this->assertSame(0.0, $service->calculatePenaltyForRental($rental, now()));
    }

    public function test_calculate_penalty_ignores_legacy_per_minute_rule_values(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_OVERDUE_RETURN,
            'returnDeadline' => now()->subMinutes(90),
            'expectedEndTime' => now()->subMinutes(90),
            'penaltyType' => Rental::PENALTY_PER_MINUTE,
            'penaltyValue' => 0.50,
        ]);

        $service = app(RentalService::class);

        // Legacy per-minute values are ignored: 18 completed blocks x PHP 20.
        $this->assertSame(360.0, $service->calculatePenaltyForRental($rental, now()));
    }

    public function test_calculate_penalty_ignores_retired_global_rate_setting(): void
    {
        SystemSetting::setValue('lateReturnPenaltyPerHour', 40);

        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        // No per-rental rule configured => global rate is used.
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_OVERDUE_RETURN,
            'returnDeadline' => now()->subMinutes(90),
            'expectedEndTime' => now()->subMinutes(90),
        ]);

        $service = app(RentalService::class);

        // The retired setting is ignored: 18 completed 5-minute blocks @ PHP 20.
        $this->assertSame(360.0, $service->calculatePenaltyForRental($rental, now()));
        $this->assertSame(20.0, $service->resolvedPenaltyRule($rental)['value']);
    }

    public function test_deadline_pass_snapshots_penalty_accrued_and_notifies_rider_with_message(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        $deadline = now()->subMinutes(90);

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
            'returnDeadline' => $deadline,
            'expectedEndTime' => $deadline,
            'penaltyType' => Rental::PENALTY_PER_HOUR,
            'penaltyValue' => 20.00,
        ]);

        Artisan::call('rentals:check-return-deadlines');

        $fresh = $rental->fresh();
        $this->assertSame(Rental::STATUS_OVERDUE_RETURN, $fresh->status);
        // 18 completed 5-minute blocks @ PHP 20 = 360.
        $this->assertSame(360.0, (float) $fresh->penaltyAccrued);
        $this->assertNotNull($fresh->overdueReturnAt);

        $notification = Notification::where('userId', $rider->id)
            ->where('type', 'rental_overdue_return')
            ->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('360.00', $notification->message);
        $this->assertSame(1, Notification::where('userId', $admin->id)->where('type', 'rental_overdue_return')->count());
    }

    public function test_setting_the_same_deadline_is_idempotent_under_fixed_policy(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_EXPIRED,
            'expiredAt' => now()->subHour(),
            'expectedEndTime' => now()->subHour(),
        ]);

        $service = app(RentalService::class);
        $deadline = now()->addHours(1);

        $service->setReturnDeadline($rental, $admin, $deadline);
        $service->setReturnDeadline($rental->fresh(), $admin, $deadline);

        $this->assertSame(1, Notification::where('userId', $rider->id)->where('type', 'rental_deadline')->count());
        $this->assertNull($rental->fresh()->penaltyType);
        $this->assertNull($rental->fresh()->penaltyValue);
    }

    public function test_deadline_message_is_appended_to_notes_and_sent_in_notification(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_EXPIRED,
            'expiredAt' => now()->subHour(),
            'expectedEndTime' => now()->subHour(),
        ]);

        $service = app(RentalService::class);

        $service->setReturnDeadline(
            $rental,
            $admin,
            now()->addHours(2),
            'Kindly return at the designated station.'
        );

        $this->assertStringContainsString('Kindly return at the designated station.', $rental->fresh()->notes);

        $notification = Notification::where('userId', $rider->id)->where('type', 'rental_deadline')->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('Kindly return at the designated station.', $notification->message);
        $this->assertStringContainsString('PHP 20 for each completed 5-minute block', $notification->message);
    }

    public function test_set_deadline_route_rejects_legacy_custom_penalty_fields(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_EXPIRED,
            'expiredAt' => now()->subHour(),
            'expectedEndTime' => now()->subHour(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.set-deadline', $rental->id), [
                'deadline' => now()->addHours(1)->format('Y-m-d\TH:i'),
                'penaltyType' => Rental::PENALTY_PER_MINUTE,
                'penaltyValue' => 20,
            ])
            ->assertSessionHasErrors(['penaltyType', 'penaltyValue']);

        $this->assertSame(Rental::STATUS_EXPIRED, $rental->fresh()->status);
    }

    public function test_request_time_sync_expires_due_rentals_without_scheduler(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        // Active rental whose expected end time already passed — the old,
        // scheduler-dependent behavior would keep it "Active" forever.
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => now()->subHours(2),
            'expectedEndTime' => now()->subMinutes(5),
        ]);

        // Visiting the Rental Requests page must synchronize stale rentals.
        $this->actingAs($admin)
            ->get(route('admin.rentals.index'))
            ->assertOk()
            ->assertDontSee('rvDeadlineForm', false)
            ->assertSee('expired', false);

        $this->assertDatabaseHas('rentals', [
            'id' => $rental->id,
            'status' => Rental::STATUS_EXPIRED,
        ]);
        $this->assertSame(1, Notification::where('userId', $admin->id)->where('type', 'rental_expired')->count());

        // A second page load must not re-notify or mutate anything (idempotent).
        $this->actingAs($admin)
            ->get(route('admin.rentals.index'))
            ->assertOk();

        $this->assertSame(1, Notification::where('userId', $admin->id)->where('type', 'rental_expired')->count());
        $this->assertSame(Rental::STATUS_EXPIRED, $rental->fresh()->status);
    }

    public function test_full_lifecycle_active_to_returned_with_php10_per_minute_penalty(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => now()->subHours(2),
            'expectedEndTime' => now()->subMinutes(5),
            'ratePerHour' => 15.00,
            'durationMinutes' => 120,
            'totalFee' => 30.00,
            'paymentStatus' => 'pending',
            'paymentMethod' => 'cash',
        ]);

        $service = app(RentalService::class);

        // 1. active -> expired (command path) + admin notified once.
        Artisan::call('rentals:check-overdue');
        $this->assertSame(Rental::STATUS_EXPIRED, $rental->fresh()->status);
        $this->assertSame(1, Notification::where('userId', $admin->id)->where('type', 'rental_expired')->count());

        // 2. expired -> awaiting_return via minutes-based deadline (server time).
        $this->actingAs($admin)
            ->put(route('admin.rentals.set-deadline', $rental->id), [
                'minutes' => 30,
            ])
            ->assertSessionDoesntHaveErrors();

        $fresh = $rental->fresh();
        $this->assertSame(Rental::STATUS_AWAITING_RETURN, $fresh->status);
        $this->assertTrue($fresh->returnDeadline->between(now()->addMinutes(29), now()->addMinutes(31)));
        $this->assertNull($fresh->penaltyValue);
        $this->assertSame(1, Notification::where('userId', $rider->id)->where('type', 'rental_deadline')->count());

        // 3. awaiting_return -> overdue_return exactly once the deadline passes.
        // Freeze time so the five-minute penalty calculation is deterministic across
        // the full test suite regardless of wall-clock drift between the DB write
        // and the Artisan command execution (which can shift diffInMinutes by ±1).
        $frozenNow = now();
        Carbon::setTestNow($frozenNow);
        $overdueDeadline = $frozenNow->copy()->subMinutes(25);
        $rental->update([
            'expectedEndTime' => $overdueDeadline,
            'returnDeadline' => $overdueDeadline,
        ]);
        Artisan::call('rentals:check-return-deadlines');

        $fresh = $rental->fresh();
        $this->assertSame(Rental::STATUS_OVERDUE_RETURN, $fresh->status);
        // Five completed blocks x PHP 20 = 100.00, server-side.
        $this->assertSame(100.0, (float) $fresh->penaltyAccrued);
        $this->assertSame(1, Notification::where('userId', $rider->id)->where('type', 'rental_overdue_return')->count());
        $this->assertSame(1, Notification::where('userId', $admin->id)->where('type', 'rental_overdue_return')->count());

        // Running the overdue-check again must not duplicate notifications.
        Artisan::call('rentals:check-return-deadlines');
        $this->assertSame(1, Notification::where('userId', $rider->id)->where('type', 'rental_overdue_return')->count());

        // Keep the clock frozen through settlement. The return timestamp is
        // minute-precision, so unfreezing here could shave a few seconds off
        // the elapsed time and turn 25 whole minutes into 24.
        // 4. overdue_return -> returned (confirm return): final fee 30 + 100 = 130.
        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'condition' => Rental::CONDITION_GOOD,
                // Keep the seconds: the stored deadline carries the frozen
                // clock's seconds, and truncating this to the minute would
                // turn a 25-minute gap into 24 completed intervals.
                'returnTime' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertSessionDoesntHaveErrors();

        $final = $rental->fresh();
        $this->assertSame(Rental::STATUS_RETURNED, $final->status);
        $this->assertSame(100.0, (float) $final->overdueFee);
        $this->assertSame(130.0, (float) $final->finalFee);
        $this->assertDatabaseHas('payments', ['rentalId' => $rental->id, 'status' => 'paid', 'totalAmount' => 130.0]);
        $this->assertDatabaseHas('bicycles', ['id' => $bike->id, 'status' => Bicycle::STATUS_AVAILABLE]);
        $this->assertSame(1, Notification::where('userId', $rider->id)->where('type', 'rental_completed')->count());

        // Confirming a second time must be rejected and must not duplicate.
        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), ['condition' => Rental::CONDITION_GOOD])
            ->assertSessionHasErrors();

        $this->assertSame(1, Payment::where('rentalId', $rental->id)->count());
        $this->assertSame(1, Notification::where('userId', $rider->id)->where('type', 'rental_completed')->count());

        Carbon::setTestNow(); // unfreeze after the minute-sensitive assertions
    }

    public function test_confirming_a_return_reports_the_state_the_bicycle_is_left_in(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
            'startTime' => now()->subHours(2),
            'returnRequestedAt' => now()->subMinutes(30),
            'ratePerHour' => 15.00,
            'paymentStatus' => 'pending',
        ]);

        // The confirmation is the one moment an admin looks at to decide whether
        // the bicycle is back in the rentable fleet, so it must describe the
        // settled row rather than the rented state the page was loaded in.
        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'condition' => Rental::CONDITION_GOOD,
            ])
            ->assertSessionDoesntHaveErrors()
            ->assertSessionHas('success', fn ($message) => str_contains($message, Bicycle::STATUS_AVAILABLE)
                && ! str_contains($message, Bicycle::STATUS_RENTED));

        $this->assertSame(
            Bicycle::STATUS_AVAILABLE,
            $bike->fresh()->status,
            'the reported state has to match what the settlement actually did'
        );
    }

    public function test_confirming_a_damaged_return_reports_maintenance(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
            'startTime' => now()->subHours(2),
            'returnRequestedAt' => now()->subMinutes(30),
            'ratePerHour' => 15.00,
            'paymentStatus' => 'pending',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'condition' => Rental::CONDITION_DAMAGED,
            ])
            ->assertSessionDoesntHaveErrors()
            ->assertSessionHas('success', fn ($message) => str_contains($message, Bicycle::STATUS_MAINTENANCE));
    }
}
