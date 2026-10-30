<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class RentalReturnPaymentTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.paymongo' => [
                'secret_key' => 'sk_test',
                'public_key' => 'pk_test',
                'webhook_secret' => 'test',
            ],
        ]);
        SystemSetting::setValue('lateReturnPenaltyPerHour', 20);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_selected_return_time_is_saved_across_midnight_and_is_not_replaced_by_now(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);

        Carbon::setTestNow(Carbon::parse('2026-10-25 00:05:00'));

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_OVERDUE_RETURN,
            'startTime' => Carbon::parse('2026-10-24 23:00:00'),
            'expectedEndTime' => Carbon::parse('2026-10-24 23:33:00'),
            'returnDeadline' => Carbon::parse('2026-10-24 23:33:00'),
            'returnRequestedAt' => Carbon::parse('2026-10-24 23:50:00'),
            'ratePerHour' => 15.00,
            'totalFee' => 15.00,
            'paymentMethod' => 'cash',
            'paymentStatus' => 'pending',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'return_time' => '2026-10-25T00:01',
                'return_time_evidence' => 'Dock camera timestamp confirms physical arrival.',
                'condition' => Rental::CONDITION_GOOD,
            ])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionDoesntHaveErrors();

        $fresh = $rental->fresh();

        // The admin's manual arrival wins over the rider's Return click and
        // over the server clock at submission time.
        $this->assertSame('2026-10-25 00:01:00', $fresh->endTime->toDateTimeString());
        $this->assertSame('2026-10-24 23:50:00', $fresh->returnRequestedAt->toDateTimeString());
        $this->assertSame('2026-10-25 00:05:00', $fresh->returnProcessedAt->toDateTimeString());

        // 23:33 -> 00:01 is 28 minutes late: five completed 5-minute intervals.
        $this->assertSame(100.0, (float) $fresh->overdueFee);
        $this->assertSame(15.0, round((float) $fresh->finalFee - (float) $fresh->overdueFee, 2));
        $this->assertSame(115.0, (float) $fresh->finalFee);

        $payment = Payment::where('rentalId', $rental->id)->sole();
        $this->assertSame('cash', $payment->paymentMethod);
        $this->assertSame('paid', $payment->status);
        $this->assertSame(15.0, (float) $payment->amount);
        $this->assertSame(115.0, (float) $payment->totalAmount);
        $this->assertSame(115.0, (float) $payment->amountPaid);
        $this->assertSame(0.0, (float) $payment->balanceDue);
        $this->assertSame('paid', $fresh->paymentStatus);
    }

    public function test_gcash_return_preserves_method_and_original_payment_while_tracking_late_balance(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);
        $paidAt = Carbon::parse('2026-10-24 22:50:00');

        Carbon::setTestNow(Carbon::parse('2026-10-25 00:05:00'));

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_OVERDUE_RETURN,
            'startTime' => Carbon::parse('2026-10-24 23:00:00'),
            'expectedEndTime' => Carbon::parse('2026-10-24 23:33:00'),
            'returnDeadline' => Carbon::parse('2026-10-24 23:33:00'),
            'returnRequestedAt' => Carbon::parse('2026-10-24 23:50:00'),
            'ratePerHour' => 15.00,
            'totalFee' => 15.00,
            'paymentMethod' => 'gcash',
            'paymentReference' => 'GC-RETURN-TEST',
            'paymentStatus' => 'paid',
            'paidAt' => $paidAt,
        ]);

        $payment = Payment::create([
            'rentalId' => $rental->id,
            'userId' => $rider->id,
            'bicycleId' => $bike->id,
            'paymentReference' => 'GC-RETURN-TEST',
            'paymentMethod' => 'gcash',
            'amount' => 15.00,
            'convenienceFee' => 0,
            'totalAmount' => 15.00,
            'currency' => 'PHP',
            'status' => 'paid',
            'paidAt' => $paidAt,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'return_time' => '2026-10-25T00:01',
                'return_time_evidence' => 'Dock camera timestamp confirms physical arrival.',
                'condition' => Rental::CONDITION_GOOD,
            ])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionDoesntHaveErrors();

        $fresh = $rental->fresh();
        $freshPayment = $payment->fresh();

        $this->assertSame(1, Payment::where('rentalId', $rental->id)->count());
        $this->assertSame('gcash', $fresh->paymentMethod);
        $this->assertSame('gcash', $freshPayment->paymentMethod);
        $this->assertSame('paid', $freshPayment->status);
        $this->assertSame(15.0, (float) $freshPayment->amount);
        $this->assertSame(115.0, (float) $freshPayment->totalAmount);
        $this->assertSame(100.0, (float) $freshPayment->latePenalty);
        $this->assertSame(15.0, (float) $freshPayment->amountPaid);
        $this->assertSame(100.0, (float) $freshPayment->balanceDue);
        $this->assertSame($paidAt->toDateTimeString(), $freshPayment->paidAt->toDateTimeString());

        // The original GCash payment was not charged again, and the unpaid
        // late fee is visible on the rental as a partial payment.
        $this->assertSame('partial', $fresh->paymentStatus);
        $this->assertSame(15.0, (float) $rider->fresh()->totalSpent);
        $this->assertSame(115.0, (float) $fresh->finalFee);

        $this->actingAs($admin)
            ->get(route('admin.rentals.returns', ['view' => 'processed']))
            ->assertOk()
            ->assertSee('Partial', false)
            ->assertSee('100.00', false);

        $this->actingAs($admin)
            ->get(route('admin.payments.show', $freshPayment))
            ->assertOk()
            ->assertSee('Amount Paid', false)
            ->assertSee('Balance Due', false)
            ->assertSee('100.00', false);

        $this->actingAs($admin)
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertSee('Paid: ₱15.00', false)
            ->assertSee('Due: ₱100.00', false);
    }

    public function test_gcash_rental_marked_paid_without_a_payment_row_does_not_charge_the_base_again(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);

        Carbon::setTestNow(Carbon::parse('2026-10-25 00:05:00'));

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_OVERDUE_RETURN,
            'startTime' => Carbon::parse('2026-10-24 23:00:00'),
            'expectedEndTime' => Carbon::parse('2026-10-24 23:33:00'),
            'returnDeadline' => Carbon::parse('2026-10-24 23:33:00'),
            'returnRequestedAt' => Carbon::parse('2026-10-24 23:50:00'),
            'ratePerHour' => 15.00,
            'totalFee' => 15.00,
            'paymentMethod' => 'gcash',
            'paymentReference' => 'GC-MISSING-ROW',
            'paymentStatus' => 'paid',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.rentals.process-return', $rental->id), [
                'return_time' => '2026-10-25T00:01',
                'return_time_evidence' => 'Dock camera timestamp confirms physical arrival.',
                'condition' => Rental::CONDITION_GOOD,
            ])
            ->assertSessionDoesntHaveErrors();

        $payment = Payment::where('rentalId', $rental->id)->sole();

        $this->assertSame('gcash', $payment->paymentMethod);
        $this->assertSame('paid', $payment->status);
        $this->assertSame(15.0, (float) $payment->amount);
        $this->assertSame(15.0, (float) $payment->amountPaid);
        $this->assertSame(100.0, (float) $payment->balanceDue);
        $this->assertSame('partial', $rental->fresh()->paymentStatus);
    }

    public function test_on_time_gcash_return_has_no_balance_due(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);

        Carbon::setTestNow(Carbon::parse('2026-10-24 23:30:00'));

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
            'startTime' => Carbon::parse('2026-10-24 23:00:00'),
            'expectedEndTime' => Carbon::parse('2026-10-24 23:33:00'),
            'returnDeadline' => Carbon::parse('2026-10-24 23:33:00'),
            'returnRequestedAt' => Carbon::parse('2026-10-24 23:29:00'),
            'ratePerHour' => 15.00,
            'totalFee' => 15.00,
            'paymentMethod' => 'gcash',
            'paymentReference' => 'GC-ON-TIME',
            'paymentStatus' => 'paid',
        ]);

        $payment = Payment::create([
            'rentalId' => $rental->id,
            'userId' => $rider->id,
            'bicycleId' => $bike->id,
            'paymentReference' => 'GC-ON-TIME',
            'paymentMethod' => 'gcash',
            'amount' => 15.00,
            'convenienceFee' => 0,
            'totalAmount' => 15.00,
            'currency' => 'PHP',
            'status' => 'paid',
            'paidAt' => Carbon::parse('2026-10-24 22:55:00'),
        ]);

        $this->actingAs($admin)
            ->put(route('admin.rentals.process-return', $rental->id), [
                'return_time' => '2026-10-24T23:30',
                'condition' => Rental::CONDITION_GOOD,
            ])
            ->assertSessionDoesntHaveErrors();

        $freshPayment = $payment->fresh();
        $this->assertSame(15.0, (float) $freshPayment->totalAmount);
        $this->assertSame(15.0, (float) $freshPayment->amountPaid);
        $this->assertSame(0.0, (float) $freshPayment->balanceDue);
        $this->assertSame('paid', $rental->fresh()->paymentStatus);
    }

    public function test_return_without_a_recorded_method_does_not_invent_a_cash_payment(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
            'startTime' => now()->subHour(),
            'returnRequestedAt' => now()->subMinutes(10),
            'ratePerHour' => 15.00,
            'totalFee' => 15.00,
            'paymentMethod' => null,
            'paymentStatus' => 'pending',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.rentals.process-return', $rental->id), [
                'return_time' => now()->format('Y-m-d\TH:i'),
                'condition' => Rental::CONDITION_GOOD,
            ])
            ->assertSessionDoesntHaveErrors();

        $fresh = $rental->fresh();
        $this->assertSame(0, Payment::where('rentalId', $rental->id)->count());
        $this->assertNull($fresh->paymentMethod);
        $this->assertSame('pending', $fresh->paymentStatus);
        $this->assertNull($fresh->paidAt);
        $this->assertSame(15.0, (float) $fresh->finalFee);
    }

    public function test_duplicate_return_submission_does_not_duplicate_or_change_the_payment(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);

        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
            'startTime' => now()->subHour(),
            'returnRequestedAt' => now()->subMinutes(20),
            'ratePerHour' => 15.00,
            'totalFee' => 15.00,
            'paymentMethod' => 'cash',
            'paymentStatus' => 'pending',
        ]);

        $payload = [
            'return_time' => now()->format('Y-m-d\TH:i'),
            'condition' => Rental::CONDITION_GOOD,
        ];

        $this->actingAs($admin)
            ->put(route('admin.rentals.process-return', $rental->id), $payload)
            ->assertSessionDoesntHaveErrors();

        $payment = Payment::where('rentalId', $rental->id)->sole();
        $paymentId = $payment->id;
        $amountPaid = (float) $payment->amountPaid;
        $endTime = $rental->fresh()->endTime->toDateTimeString();

        $this->actingAs($admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), $payload)
            ->assertSessionHasErrors();

        $this->assertSame(1, Payment::where('rentalId', $rental->id)->count());
        $this->assertSame($paymentId, Payment::where('rentalId', $rental->id)->sole()->id);
        $this->assertSame($amountPaid, (float) Payment::find($paymentId)->amountPaid);
        $this->assertSame($endTime, $rental->fresh()->endTime->toDateTimeString());
    }
}
