<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bicycle;
use App\Models\Payment;
use App\Models\Rental;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * A rider's Return tap is only a request. The physical arrival is a separate,
 * administrator-verified fact, and the late penalty is always derived from
 * that verified instant against the stored deadline.
 */
class RentalReturnVerificationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{0: \App\Models\User, 1: \App\Models\User, 2: Bicycle, 3: Rental}
     */
    private function pendingReturn(array $overrides = []): array
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);

        $rental = $this->makeRental(array_merge([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
            'startTime' => Carbon::parse('2026-10-24 23:00:00'),
            'expectedEndTime' => Carbon::parse('2026-10-24 23:33:00'),
            'returnDeadline' => Carbon::parse('2026-10-24 23:33:00'),
            'returnDeadlineSetBy' => $admin->name,
            'returnRequestedAt' => Carbon::parse('2026-10-24 23:33:00'),
            'ratePerHour' => 15.00,
            'totalFee' => 15.00,
            'durationMinutes' => 33,
            'durationFormatted' => '33m 0s',
            'chargedHours' => 1,
            'paymentMethod' => 'cash',
            'paymentStatus' => 'pending',
        ], $overrides));

        return [$admin, $rider, $bike, $rental];
    }

    public function test_rider_return_request_records_submitted_at_without_becoming_the_actual_return(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-24 23:33:00'));

        [, $rider, , $rental] = $this->pendingReturn([
            'status' => Rental::STATUS_ACTIVE,
            'returnDeadline' => Carbon::parse('2026-10-24 23:33:00'),
            'returnRequestedAt' => null,
        ]);

        $this->actingAs($rider)
            ->post(route('rider.rentals.return', $rental->id))
            ->assertRedirect(route('rider.dashboard'));

        $fresh = $rental->fresh();

        // The click is recorded for the audit trail...
        $this->assertSame(Rental::STATUS_AWAITING_RETURN, $fresh->status);
        $this->assertSame('2026-10-24 23:33:00', $fresh->returnRequestedAt->toDateTimeString());

        // ...but it is not the physical arrival, and it cannot move the deadline.
        $this->assertNull($fresh->endTime);
        $this->assertSame('2026-10-24 23:33:00', $fresh->returnDeadline->toDateTimeString());
        $this->assertSame('2026-10-24 23:33:00', $fresh->expectedEndTime->toDateTimeString());
    }

    public function test_admin_defaults_arrival_to_server_now_so_an_early_return_click_cannot_avoid_the_penalty(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 00:01:00'));

        [$admin, , , $rental] = $this->pendingReturn();

        // The rider clicked Return on time at 11:33 PM, but the bicycle only
        // physically arrived at 12:01 AM. With no manual correction the admin
        // accepts the server default: 12:01 AM.
        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'condition' => Rental::CONDITION_GOOD,
            ])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionDoesntHaveErrors();

        $fresh = $rental->fresh();

        $this->assertSame(Rental::STATUS_RETURNED, $fresh->status);
        $this->assertSame('2026-10-25 00:01:00', $fresh->endTime->toDateTimeString());
        $this->assertSame('2026-10-24 23:33:00', $fresh->returnRequestedAt->toDateTimeString());
        $this->assertSame('2026-10-24 23:33:00', $fresh->returnDeadline->toDateTimeString());

        // 23:33 -> 00:01 is 28 minutes late: five completed 5-minute intervals.
        $this->assertSame(100.0, (float) $fresh->overdueFee);
        $this->assertSame(15.0, (float) $fresh->totalFee - (float) $fresh->overdueFee);
        $this->assertSame(115.0, (float) $fresh->finalFee);

        $this->assertDatabaseHas('payments', [
            'rentalId' => $rental->id,
            'status' => 'paid',
            'totalAmount' => 115.0,
        ]);
    }

    public function test_admin_can_correct_arrival_with_evidence_and_the_audit_log_records_the_change(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 00:10:00'));

        [$admin, , , $rental] = $this->pendingReturn();

        // The admin verified that the bicycle actually arrived at 12:01 AM,
        // nine minutes before this submission. The correction needs evidence.
        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'return_time' => '2026-10-25T00:01',
                'return_time_evidence' => 'Dock camera timestamp and signed inspection log.',
                'condition' => Rental::CONDITION_GOOD,
            ])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionDoesntHaveErrors();

        $fresh = $rental->fresh();

        $this->assertSame('2026-10-25 00:01:00', $fresh->endTime->toDateTimeString());
        $this->assertSame('2026-10-25 00:10:00', $fresh->returnProcessedAt->toDateTimeString());
        $this->assertSame(100.0, (float) $fresh->overdueFee);
        $this->assertSame(115.0, (float) $fresh->finalFee);

        $log = AuditLog::where('action', 'rental_return_processed')->latest('id')->firstOrFail();
        $details = $log->details;

        $this->assertTrue($details['returnTimeCorrected']);
        $this->assertSame('Dock camera timestamp and signed inspection log.', $details['returnTimeEvidence']);
        $this->assertSame('2026-10-24 23:33:00', $details['returnSubmittedAt']);
        $this->assertSame('2026-10-25 00:10:00', $details['defaultReturnTime']);
        $this->assertSame('2026-10-24 23:33:00', $details['returnDeadline']);
        $this->assertTrue($details['returnDeadlineUnchanged']);
        $this->assertSame('2026-10-25 00:10:00', $details['changes']['returnTime']['from']);
        $this->assertSame('2026-10-25 00:01:00', $details['changes']['returnTime']['to']);
    }

    public function test_material_arrival_correction_without_evidence_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 00:10:00'));

        [$admin, , , $rental] = $this->pendingReturn();

        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'return_time' => '2026-10-25T00:01',
                'condition' => Rental::CONDITION_GOOD,
            ])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionHasErrors('return_time_evidence');

        $fresh = $rental->fresh();

        $this->assertSame(Rental::STATUS_AWAITING_RETURN, $fresh->status);
        $this->assertNull($fresh->endTime);
        $this->assertSame(0, Payment::where('rentalId', $rental->id)->count());
        $this->assertSame(0, AuditLog::where('action', 'rental_return_processed')->count());
    }

    public function test_any_manual_arrival_correction_requires_verified_evidence(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 00:10:00'));

        [$admin, , , $rental] = $this->pendingReturn();

        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'return_time' => '2026-10-25T00:09',
                'condition' => Rental::CONDITION_GOOD,
            ])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionHasErrors('return_time_evidence');

        $this->assertSame(Rental::STATUS_AWAITING_RETURN, $rental->fresh()->status);
        $this->assertSame(0, Payment::where('rentalId', $rental->id)->count());
    }

    public function test_exactly_one_full_penalty_interval_of_correction_requires_evidence(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 00:10:00'));

        [$admin, , , $rental] = $this->pendingReturn();

        // A five-minute shave can skip a whole ₱20 interval, so it is material
        // even though it is exactly at the tolerance boundary.
        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'return_time' => '2026-10-25T00:05',
                'condition' => Rental::CONDITION_GOOD,
            ])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionHasErrors('return_time_evidence');

        $this->assertSame(Rental::STATUS_AWAITING_RETURN, $rental->fresh()->status);
    }

    public function test_signed_processing_session_keeps_the_server_default_captured_when_the_dialog_opened(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 00:01:00'));

        [$admin, , , $rental] = $this->pendingReturn();

        $session = $this->actingAs($admin)
            ->getJson(route('admin.rentals.return-verification', $rental->id))
            ->assertOk();

        $this->assertSame('2026-10-25T00:01:00+08:00', $session->json('processingStartedAt'));

        // The admin inspects the bicycle for nine minutes. The arrival time
        // stays the server time captured at modal-open, not the submit time.
        Carbon::setTestNow(Carbon::parse('2026-10-25 00:10:00'));

        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'processing_token' => $session->json('token'),
                'condition' => Rental::CONDITION_GOOD,
            ])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionDoesntHaveErrors();

        $fresh = $rental->fresh();

        $this->assertSame('2026-10-25 00:01:00', $fresh->endTime->toDateTimeString());
        $this->assertSame('2026-10-25 00:10:00', $fresh->returnProcessedAt->toDateTimeString());
        $this->assertSame(100.0, (float) $fresh->overdueFee);
        $this->assertSame(115.0, (float) $fresh->finalFee);
    }

    public function test_verified_arrival_cannot_be_in_the_future(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 00:10:00'));

        [$admin, , , $rental] = $this->pendingReturn();

        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'return_time' => '2026-10-25T00:20',
                'condition' => Rental::CONDITION_GOOD,
            ])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionHasErrors('return_time');

        $this->assertSame(Rental::STATUS_AWAITING_RETURN, $rental->fresh()->status);
        $this->assertSame(0, Payment::where('rentalId', $rental->id)->count());
    }
}
