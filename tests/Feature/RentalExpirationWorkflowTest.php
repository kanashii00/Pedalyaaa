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
                'penaltyType' => Rental::PENALTY_PER_MINUTE,
                'penaltyValue' => 10,
                'message' => 'Please return the bicycle on time.',
            ])
            ->assertRedirect(route('admin.rentals.returns'));

        $fresh = $rental->fresh();
        $this->assertSame(Rental::STATUS_AWAITING_RETURN, $fresh->status);
        $this->assertNotNull($fresh->returnDeadline);
        // Deadline = server time + 90 minutes.
        $this->assertTrue($fresh->returnDeadline->between(now()->addMinutes(89), now()->addMinutes(91)));
        $this->assertSame($admin->name, $fresh->returnDeadlineSetBy);
        $this->assertSame(Rental::PENALTY_PER_MINUTE, $fresh->penaltyType);
        $this->assertSame('10.00', $fresh->penaltyValue);

        $this->assertSame(1, Notification::where('userId', $rider->id)->where('type', 'rental_deadline')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'rental_deadline_set']);
    }

    public function test_set_deadline_route_applies_default_penalty_of_10_per_minute(): void
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

        // Only the allowed return minutes are supplied; the default penalty
        // rate of PHP 10.00 per minute must kick in server-side.
        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.set-deadline', $rental->id), ['minutes' => 30])
            ->assertRedirect(route('admin.rentals.returns'));

        $fresh = $rental->fresh();
        $this->assertSame(Rental::PENALTY_PER_MINUTE, $fresh->penaltyType);
        $this->assertSame('10.00', $fresh->penaltyValue);
        $this->assertTrue($fresh->returnDeadline->between(now()->addMinutes(29), now()->addMinutes(31)));

        $notification = Notification::where('userId', $rider->id)->where('type', 'rental_deadline')->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('10.00 per minute', $notification->message);
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

    public function test_set_deadline_modal_submits_the_minutes_field_with_the_request(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_EXPIRED,
            'expiredAt' => now()->subHour(),
            'expectedEndTime' => now()->subHour(),
        ]);

        // The minutes input (and the preset chips driving it) must live inside
        // the deadline form; otherwise nothing is posted and the controller
        // rejects the request with "Specify the allowed return time ...".
        foreach ([
            [route('admin.rentals.index'), 'rvDeadlineForm', 'rvDlMinutes'],
            [route('admin.rentals.returns'), 'prDeadlineForm', 'prDlMinutes'],
        ] as [$url, $formId, $inputId]) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('id="' . $formId . '"', $html);
            $this->assertStringContainsString('id="' . $inputId . '"', $html);
            $this->assertStringContainsString('name="minutes"', $html);

            $this->assertTrue(
                $this->inputIsInsideForm($html, $inputId, $formId),
                "Expected #{$inputId} to be nested inside #{$formId} on {$url}."
            );
        }
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
                    'penaltyType' => Rental::PENALTY_PER_MINUTE,
                    'penaltyValue' => 10.00,
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
    private function inputIsInsideForm(string $html, string $inputId, string $formId): bool
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($doc);

        $inputs = $xpath->query("//*[@id='" . $inputId . "']");
        $forms = $xpath->query("//form[@id='" . $formId . "']");

        if ($inputs === false || $forms === false || $inputs->length === 0 || $forms->length === 0) {
            return false;
        }

        $form = $forms->item(0);

        for ($node = $inputs->item(0); $node !== null; $node = $node->parentNode) {
            if ($node === $form) {
                return true;
            }
        }

        return false;
    }

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
                'penaltyType' => Rental::PENALTY_PER_HOUR,
                'penaltyValue' => 20,
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

        $deadline = now()->subHours(3);

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_OVERDUE_RETURN,
            'startTime' => now()->subHours(5),
            'expectedEndTime' => now()->subHours(4),
            'returnDeadline' => $deadline,
            'returnDeadlineSetBy' => $admin->name,
            'overdueReturnAt' => $deadline,
            'ratePerHour' => 15.00,
            'paymentStatus' => 'pending',
        ]);

        $returnTime = now()->format('Y-m-d\TH:i');

        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'condition' => 'good',
                'returnTime' => $returnTime,
                'note' => 'All good',
            ])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionDoesntHaveErrors();

        $fresh = $rental->fresh();

        // 5h ride @ 15 = 75 base; 3h late @ 20/hr = 60 penalty; final 135.
        $this->assertSame(Rental::STATUS_RETURNED, $fresh->status);
        $this->assertSame(60.0, (float) $fresh->overdueFee);
        $this->assertSame(135.0, (float) $fresh->finalFee);
        $this->assertSame(135.0, (float) $fresh->totalFee);
        $this->assertSame($admin->name, $fresh->returnInspectedBy);

        $this->assertDatabaseHas('payments', [
            'rentalId' => $rental->id,
            'status' => 'paid',
            'totalAmount' => 135.0,
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

    public function test_process_return_without_deadline_uses_legacy_overdue_surcharge(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);

        // 4h ride, returned 2.5h past the expected end => legacy surcharge =
        // ceil(2.5) = 3h @ 15 = 45; base = 4h @ 15 = 60; final = 105.
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
        $this->assertSame(45.0, (float) $fresh->overdueFee);
        $this->assertSame(105.0, (float) $fresh->finalFee);
    }

    public function test_late_penalty_uses_configurable_rate_from_settings(): void
    {
        SystemSetting::setValue('lateReturnPenaltyPerHour', 30);

        $service = app(RentalService::class);

        $penalty = $service->calculateLateReturnPenalty(
            now()->subMinutes(150),
            now()
        );

        // 150 minutes late => ceil(150/60) = 3 hours @ 30 = 90.
        $this->assertSame(90.0, $penalty);

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

    public function test_calculate_penalty_supports_fixed_rule(): void
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
            'penaltyType' => Rental::PENALTY_FIXED,
            'penaltyValue' => 120.00,
        ]);

        $service = app(RentalService::class);

        // Fixed rule applies the flat amount regardless of how overdue.
        $this->assertSame(120.0, $service->calculatePenaltyForRental($rental, now()));

        // Not yet overdue => no penalty.
        $rental->returnDeadline = now()->addHour();
        $this->assertSame(0.0, $service->calculatePenaltyForRental($rental, now()));
    }

    public function test_calculate_penalty_supports_per_minute_rule(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_OVERDUE_RETURN,
            'returnDeadline' => now()->subMinutes(90),
            'penaltyType' => Rental::PENALTY_PER_MINUTE,
            'penaltyValue' => 0.50,
        ]);

        $service = app(RentalService::class);

        // 90 minutes x 0.50 = 45.
        $this->assertSame(45.0, $service->calculatePenaltyForRental($rental, now()));
    }

    public function test_calculate_penalty_falls_back_to_global_hourly_rate(): void
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
        ]);

        $service = app(RentalService::class);

        // ceil(90/60) = 2 hours @ 40 = 80.
        $this->assertSame(80.0, $service->calculatePenaltyForRental($rental, now()));
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
            'penaltyType' => Rental::PENALTY_PER_HOUR,
            'penaltyValue' => 20.00,
        ]);

        Artisan::call('rentals:check-return-deadlines');

        $fresh = $rental->fresh();
        $this->assertSame(Rental::STATUS_OVERDUE_RETURN, $fresh->status);
        // ceil(90/60) = 2 hours @ 20 = 40 accrued snapshot.
        $this->assertSame(40.0, (float) $fresh->penaltyAccrued);
        $this->assertNotNull($fresh->overdueReturnAt);

        $notification = Notification::where('userId', $rider->id)
            ->where('type', 'rental_overdue_return')
            ->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('40.00', $notification->message);
        $this->assertSame(1, Notification::where('userId', $admin->id)->where('type', 'rental_overdue_return')->count());
    }

    public function test_changing_penalty_configuration_is_not_idempotent(): void
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

        $service->setReturnDeadline($rental, $admin, $deadline, ['type' => Rental::PENALTY_PER_HOUR, 'value' => 20]);
        $service->setReturnDeadline($rental->fresh(), $admin, $deadline, ['type' => Rental::PENALTY_FIXED, 'value' => 150]);

        // Two notifications: one for each distinct configuration.
        $this->assertSame(2, Notification::where('userId', $rider->id)->where('type', 'rental_deadline')->count());
        $this->assertSame(Rental::PENALTY_FIXED, $rental->fresh()->penaltyType);
        $this->assertSame('150.00', $rental->fresh()->penaltyValue);
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
            ['type' => Rental::PENALTY_PER_HOUR, 'value' => 20],
            'Kindly return at the designated station.'
        );

        $this->assertStringContainsString('Kindly return at the designated station.', $rental->fresh()->notes);

        $notification = Notification::where('userId', $rider->id)->where('type', 'rental_deadline')->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('Kindly return at the designated station.', $notification->message);
        $this->assertStringContainsString('20', $notification->message);
    }

    public function test_set_deadline_route_rejects_invalid_penalty_configuration(): void
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
                'penaltyType' => 'not_a_valid_type',
                'penaltyValue' => 20,
            ])
            ->assertSessionHasErrors('penaltyType');

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
            ->assertSee('Set return deadline', false)
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
                'penaltyType' => Rental::PENALTY_PER_MINUTE,
                'penaltyValue' => 10.00,
            ])
            ->assertSessionDoesntHaveErrors();

        $fresh = $rental->fresh();
        $this->assertSame(Rental::STATUS_AWAITING_RETURN, $fresh->status);
        $this->assertTrue($fresh->returnDeadline->between(now()->addMinutes(29), now()->addMinutes(31)));
        $this->assertSame('10.00', $fresh->penaltyValue);
        $this->assertSame(1, Notification::where('userId', $rider->id)->where('type', 'rental_deadline')->count());

        // 3. awaiting_return -> overdue_return exactly once the deadline passes.
        $rental->update(['returnDeadline' => now()->subMinutes(25)]);
        Artisan::call('rentals:check-return-deadlines');

        $fresh = $rental->fresh();
        $this->assertSame(Rental::STATUS_OVERDUE_RETURN, $fresh->status);
        // Penalty = whole overdue minutes (25) x PHP 10.00 = 250.00, server-side.
        $this->assertSame(250.0, (float) $fresh->penaltyAccrued);
        $this->assertSame(1, Notification::where('userId', $rider->id)->where('type', 'rental_overdue_return')->count());
        $this->assertSame(1, Notification::where('userId', $admin->id)->where('type', 'rental_overdue_return')->count());

        // Running the overdue-check again must not duplicate notifications.
        Artisan::call('rentals:check-return-deadlines');
        $this->assertSame(1, Notification::where('userId', $rider->id)->where('type', 'rental_overdue_return')->count());

        // 4. overdue_return -> returned (confirm return): final fee 30 + 250 = 280.
        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'condition' => Rental::CONDITION_GOOD,
                'returnTime' => now()->format('Y-m-d\TH:i'),
            ])
            ->assertSessionDoesntHaveErrors();

        $final = $rental->fresh();
        $this->assertSame(Rental::STATUS_RETURNED, $final->status);
        $this->assertSame(250.0, (float) $final->overdueFee);
        $this->assertSame(280.0, (float) $final->finalFee);
        $this->assertDatabaseHas('payments', ['rentalId' => $rental->id, 'status' => 'paid', 'totalAmount' => 280.0]);
        $this->assertDatabaseHas('bicycles', ['id' => $bike->id, 'status' => Bicycle::STATUS_AVAILABLE]);
        $this->assertSame(1, Notification::where('userId', $rider->id)->where('type', 'rental_completed')->count());

        // Confirming a second time must be rejected and must not duplicate.
        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), ['condition' => Rental::CONDITION_GOOD])
            ->assertSessionHasErrors();

        $this->assertSame(1, Payment::where('rentalId', $rental->id)->count());
        $this->assertSame(1, Notification::where('userId', $rider->id)->where('type', 'rental_completed')->count());
    }
}