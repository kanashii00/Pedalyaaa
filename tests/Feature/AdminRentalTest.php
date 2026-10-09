<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\DeviceCommand;
use App\Models\Payment;
use App\Models\Rental;
use App\Services\IoTService;
use App\Services\NotificationService;
use App\Services\RentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class AdminRentalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdmin();
    }

    public function test_index_renders_and_filters(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_PENDING]);

        $this->actingAs($this->admin)->get(route('admin.rentals.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.rentals.index') . '?status=active&bicycle_id=' . $bike->id . '&rider_id=' . $rider->id . '&date_from=2020-01-01&date_to=2030-01-01')->assertOk();
    }

    public function test_history_renders(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_COMPLETED]);
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_CANCELLED]);

        $this->actingAs($this->admin)->get(route('admin.rentals.history'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.rentals.history') . '?status=completed')->assertOk();
    }

    public function test_show_renders(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);

        $this->actingAs($this->admin)->get(route('admin.rentals.show', $rental->id))->assertOk();
    }

    public function test_approve_pending_rental(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $rental = $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_PENDING]);

        $this->mockIot();

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.index'))
            ->put(route('admin.rentals.approve', $rental->id))
            ->assertRedirect(route('admin.rentals.index'))
            ->assertSessionHas('success');

        $this->assertSame(Rental::STATUS_ACTIVE, $rental->fresh()->status);
        $this->assertSame('rented', $bike->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'rental_approved']);
    }

    public function test_approve_non_pending_rejected(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.index'))
            ->put(route('admin.rentals.approve', $rental->id))
            ->assertSessionHasErrors('rental');
    }

    public function test_verify_gcash_payment(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_PENDING,
            'paymentMethod' => 'gcash',
            'paymentStatus' => 'pending_verification',
        ]);
        Payment::create([
            'rentalId' => $rental->id,
            'userId' => $rider->id,
            'bicycleId' => $bike->id,
            'paymentReference' => 'GC-123',
            'paymentMethod' => 'gcash',
            'amount' => 15,
            'totalAmount' => 15,
            'currency' => 'PHP',
            'status' => 'pending',
        ]);

        $this->mockIot();

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.index'))
            ->put(route('admin.rentals.verify-gcash', $rental->id))
            ->assertRedirect(route('admin.rentals.index'));

        $this->assertSame(Rental::STATUS_ACTIVE, $rental->fresh()->status);
        $this->assertSame('paid', $rental->fresh()->paymentStatus);
        $this->assertSame(1, $rider->fresh()->totalRentals);
    }

    public function test_verify_gcash_only_for_pending_gcash(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE, 'paymentMethod' => 'cash']);

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.index'))
            ->put(route('admin.rentals.verify-gcash', $rental->id))
            ->assertSessionHasErrors('rental');
    }

    public function test_mark_paid_cash_rental(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
            'paymentMethod' => 'cash',
            'paymentStatus' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.index'))
            ->put(route('admin.rentals.mark-paid', $rental->id))
            ->assertRedirect(route('admin.rentals.index'));

        $this->assertSame('paid', $rental->fresh()->paymentStatus);
    }

    public function test_mark_paid_rejects_gcash(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
            'paymentMethod' => 'gcash',
            'paymentStatus' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.index'))
            ->put(route('admin.rentals.mark-paid', $rental->id))
            ->assertSessionHasErrors('rental');
    }

    public function test_mark_paid_rejects_already_paid(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
            'paymentMethod' => 'cash',
            'paymentStatus' => 'paid',
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.index'))
            ->put(route('admin.rentals.mark-paid', $rental->id))
            ->assertSessionHasErrors('rental');
    }

    public function test_end_ride_completes_rental(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);

        // End Ride is one step: it settles the rental rather than parking it in
        // the returns queue, so the controller calls endRideAndSettle().
        // The return value is built lazily so the rental is still 'active' in
        // the database when the controller runs its own status check.
        $service = Mockery::mock(RentalService::class);
        $service->shouldReceive('endRideAndSettle')->once()->andReturnUsing(function () use ($rental) {
            $rental->forceFill(['status' => Rental::STATUS_RETURNED])->save();

            return [
                'rental' => $rental->fresh(),
                'fees' => ['finalFee' => 120.0, 'latePenalty' => 0.0, 'durationFormatted' => '1h 0m'],
            ];
        });
        $this->app->instance(RentalService::class, $service);

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.index'))
            ->put(route('admin.rentals.end-ride', $rental->id), ['condition' => Rental::CONDITION_GOOD])
            ->assertRedirect(route('admin.rentals.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('rentals', ['id' => $rental->id, 'status' => Rental::STATUS_RETURNED]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'rental_ended_by_admin']);
    }

    public function test_end_ride_rejects_invalid_condition(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);

        $service = Mockery::mock(RentalService::class);
        $service->shouldNotReceive('endRideAndSettle');
        $this->app->instance(RentalService::class, $service);

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.index'))
            ->put(route('admin.rentals.end-ride', $rental->id), ['condition' => 'destroyed'])
            ->assertRedirect(route('admin.rentals.index'))
            ->assertSessionHasErrors('condition');

        $this->assertDatabaseHas('rentals', ['id' => $rental->id, 'status' => Rental::STATUS_ACTIVE]);
    }

    public function test_cancel_rental_locks_bicycle(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);
        $rental = $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);
        $bike->update(['currentRentalId' => $rental->id]);

        $this->mockIot();

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.index'))
            ->put(route('admin.rentals.cancel', $rental->id), ['reason' => 'test cancel'])
            ->assertRedirect(route('admin.rentals.index'));

        $this->assertSame(Rental::STATUS_CANCELLED, $rental->fresh()->status);
        $this->assertSame('available', $bike->fresh()->status);
    }

    public function test_cancel_completed_rejected(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_COMPLETED]);

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.index'))
            ->put(route('admin.rentals.cancel', $rental->id))
            ->assertSessionHasErrors('rental');
    }

    private function mockIot(): void
    {
        $iot = Mockery::mock(IoTService::class);
        $iot->shouldReceive('sendCommand')->andReturn(
            DeviceCommand::create(['bicycleId' => 1, 'command' => 'lock', 'status' => 'pending'])
        );
        $this->app->instance(IoTService::class, $iot);
    }

    public function test_history_filters_by_date_and_bicycle(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_COMPLETED]);

        $this->actingAs($this->admin)
            ->get(route('admin.rentals.history') . '?date_from=2020-01-01&date_to=2030-12-31&bicycle_id=' . $bike->id . '&rider_id=' . $rider->id)
            ->assertOk();
    }

    public function test_returns_pending_view(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_AWAITING_RETURN]);

        $this->actingAs($this->admin)
            ->get(route('admin.rentals.returns'))
            ->assertOk();
    }

    public function test_returns_processed_view(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_RETURNED]);

        $this->actingAs($this->admin)
            ->get(route('admin.rentals.returns') . '?view=processed')
            ->assertOk();
    }

    public function test_returns_filters(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_AWAITING_RETURN]);

        $this->actingAs($this->admin)
            ->get(route('admin.rentals.returns') . '?status=awaiting_return&date_from=2020-01-01&date_to=2030-12-31&bicycle_id=' . $bike->id . '&rider_id=' . $rider->id)
            ->assertOk();
    }

    public function test_returns_invalid_view_falls_back_to_pending(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.rentals.returns') . '?view=invalid')
            ->assertOk();
    }

    public function test_the_active_queue_offers_end_ride_with_a_shared_dialog(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['serialNumber' => 'PDY-E2']);
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
        ]);

        $html = $this->actingAs($this->admin)->get(route('admin.rentals.index'))->getContent();

        // End Ride is settled in one step here, so the row opens the dialog that
        // collects the condition instead of posting a bare form.
        $this->assertStringContainsString(
            'data-end-ride',
            $html,
            'an in-progress rental offers End Ride from the active queue'
        );
        $this->assertStringContainsString(
            'data-rental-id="' . $rental->id . '"',
            $html,
            'the opener names the rental it acts on'
        );
        $this->assertStringContainsString('id="endRideModal"', $html, 'the shared dialog is rendered once');
        $this->assertStringContainsString('name="condition"', $html, 'the dialog requires the inspected condition');

        // The URL lives once, in the shared dialog, with a placeholder the script
        // substitutes - so no per-row form repeats it.
        $this->assertSame(
            1,
            substr_count($html, 'id="endRideForm"'),
            'there is exactly one End Ride form on the page'
        );
    }

    public function test_an_expired_rental_can_still_be_ended_from_the_active_queue(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['serialNumber' => 'PDY-E3']);
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_EXPIRED,
            'endTime' => now()->subHours(3),
            'expectedEndTime' => now()->subHours(2),
        ]);

        $html = $this->actingAs($this->admin)->get(route('admin.rentals.index'))->getContent();

        $this->assertStringContainsString(
            'data-rental-id="' . $rental->id . '"',
            $html,
            'an expired ride is still endable; the rider has it in hand'
        );

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.index'))
            ->put(route('admin.rentals.end-ride', $rental->id), ['condition' => Rental::CONDITION_GOOD])
            ->assertSessionHasNoErrors();

        $this->assertSame(Rental::STATUS_RETURNED, $rental->fresh()->status);
    }

    public function test_end_ride_invalid_status_returns_error(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_COMPLETED]);

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.index'))
            ->put(route('admin.rentals.end-ride', $rental->id))
            ->assertSessionHasErrors('rental');
    }

    public function test_end_ride_service_exception_returns_error(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);

        $service = Mockery::mock(RentalService::class);
        $service->shouldReceive('endRideAndSettle')->once()->andThrow(new \RuntimeException('Service failure'));
        $this->app->instance(RentalService::class, $service);

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.index'))
            ->put(route('admin.rentals.end-ride', $rental->id), ['condition' => Rental::CONDITION_GOOD])
            ->assertSessionHasErrors('rental');
    }

    public function test_process_return_success(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
            'endTime' => now()->subHour(),
            'expectedEndTime' => now()->subHour(),
        ]);

        $service = Mockery::mock(RentalService::class);
        $service->shouldReceive('processReturn')->once()->andReturn([
            'rental' => $rental->fresh(),
            'fees' => ['baseFee' => 15, 'overdueFee' => 0, 'finalFee' => 15],
        ]);
        $this->app->instance(RentalService::class, $service);

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'condition' => 'good',
                'note' => 'Looks fine',
            ])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionHas('success');
    }

    public function test_process_return_shows_only_the_toast_not_the_banner(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
            'endTime' => now()->subHour(),
            'expectedEndTime' => now()->subHour(),
        ]);

        $service = Mockery::mock(RentalService::class);
        $service->shouldReceive('processReturn')->once()->andReturn([
            'rental' => $rental->fresh(),
            'fees' => ['baseFee' => 15, 'overdueFee' => 0, 'finalFee' => 15],
        ]);
        // The follow-up GET runs through the rental.sync middleware.
        $service->shouldReceive('synchronizeLifecycle')->andReturn(['expired' => 0, 'overdue_return' => 0]);
        $this->app->instance(RentalService::class, $service);

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), [
                'condition' => 'good',
                'note' => 'Looks fine',
            ])
            ->assertRedirect(route('admin.rentals.returns'))
            ->assertSessionHas('success')
            ->assertSessionHas('toast');

        // The competing full-width success banner is suppressed when the
        // structured toast carries the same message: exactly one notification.
        $this->actingAs($this->admin)->get(route('admin.rentals.returns'))
            ->assertOk()
            ->assertDontSee('alert-pedalya alert-success', false)
            ->assertSee('id="adminToasts"', false)
            ->assertSee('Return confirmed successfully', false);
    }

    public function test_plain_success_without_toast_data_keeps_the_banner(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $rental = $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_PENDING]);

        $this->mockIot();

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.index'))
            ->put(route('admin.rentals.approve', $rental->id))
            ->assertRedirect(route('admin.rentals.index'))
            ->assertSessionHas('success')
            ->assertSessionMissing('toast');

        $this->actingAs($this->admin)->get(route('admin.rentals.index'))
            ->assertOk()
            ->assertSee('alert-pedalya alert-success', false);
    }

    public function test_process_return_service_exception_returns_error(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
        ]);

        $service = Mockery::mock(RentalService::class);
        $service->shouldReceive('processReturn')->once()->andThrow(new \RuntimeException('Process failure'));
        $this->app->instance(RentalService::class, $service);

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.returns'))
            ->put(route('admin.rentals.process-return', $rental->id), ['condition' => 'good'])
            ->assertSessionHasErrors('rental');
    }

    public function test_cancel_with_matching_bicycle(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_RENTED]);
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
        ]);
        $bike->update(['currentRentalId' => $rental->id]);

        $this->mockIot();

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.index'))
            ->put(route('admin.rentals.cancel', $rental->id), ['reason' => 'Maintenance needed'])
            ->assertRedirect(route('admin.rentals.index'));

        $this->assertSame(Rental::STATUS_CANCELLED, $rental->fresh()->status);
        $this->assertSame(Bicycle::STATUS_AVAILABLE, $bike->fresh()->status);
    }

    public function test_cancel_without_matching_bicycle(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.index'))
            ->put(route('admin.rentals.cancel', $rental->id), ['reason' => 'Changed mind'])
            ->assertRedirect(route('admin.rentals.index'));

        $this->assertSame(Rental::STATUS_CANCELLED, $rental->fresh()->status);
    }

    public function test_history_view_filters_completed(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_RETURNED]);
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_EXPIRED]);

        $this->actingAs($this->admin)
            ->get(route('admin.rentals.history') . '?status=returned')
            ->assertOk();
    }

    public function test_sidebar_badge_counts_rentals_not_unread_notifications(): void
    {
        // The reported bug: the badge showed 49 next to a single rental row
        // because it was counting unread `rental_started` notifications.
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);

        $notificationService = app(NotificationService::class);
        for ($i = 0; $i < 49; $i++) {
            $notificationService->create($this->admin->id, 'New Rental', 'A bike was rented.', 'rental_started');
        }

        $response = $this->actingAs($this->admin)->get(route('admin.rentals.index'));

        $response->assertOk();
        $response->assertSee('Showing 1 record');
        $this->assertSidebarActiveRentalsBadge($response, 1);
    }

    public function test_sidebar_badge_counts_the_whole_queue(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();

        // Every status the queue page lists has to be counted.
        foreach ([Rental::STATUS_ACTIVE, Rental::STATUS_PENDING, Rental::STATUS_OVERDUE, Rental::STATUS_EXPIRED] as $status) {
            $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => $status]);
        }

        // ...and nothing that has already been settled.
        foreach ([Rental::STATUS_RETURNED, Rental::STATUS_COMPLETED, Rental::STATUS_CANCELLED] as $status) {
            $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => $status]);
        }

        $response = $this->actingAs($this->admin)->get(route('admin.rentals.index'));

        $response->assertOk();
        $response->assertSee('Showing 4 records');
        $this->assertSidebarActiveRentalsBadge($response, 4);
    }

    public function test_sidebar_badge_tracks_rentals_created_and_cancelled(): void
    {
        $this->assertSame(0, Rental::activeListCount());

        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_ACTIVE,
        ]);

        $this->assertSame(1, Rental::activeListCount());

        $this->actingAs($this->admin)
            ->from(route('admin.rentals.index'))
            ->put(route('admin.rentals.cancel', $rental->id), ['reason' => 'Rider never picked it up'])
            ->assertRedirect(route('admin.rentals.index'));

        $this->assertSame(0, Rental::activeListCount());

        $response = $this->actingAs($this->admin)->get(route('admin.rentals.index'));

        $response->assertOk();
        $response->assertSee('Showing 0 records');
        $this->assertSidebarActiveRentalsBadge($response, 0);
    }

    public function test_active_rental_count_follows_the_rental_lifecycle(): void
    {
        $rider = $this->makeRider();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental([
            'riderId' => $rider->id,
            'bicycleId' => $bike->id,
            'status' => Rental::STATUS_PENDING,
        ]);

        // A pending request is still something an admin has to act on.
        $this->assertSame(1, Rental::activeListCount());

        $rental->update(['status' => Rental::STATUS_ACTIVE]);
        $this->assertSame(1, Rental::activeListCount());

        $rental->update(['status' => Rental::STATUS_EXPIRED]);
        $this->assertSame(1, Rental::activeListCount());

        $rental->update(['status' => Rental::STATUS_RETURNED]);
        $this->assertSame(0, Rental::activeListCount());

        $rental->update(['status' => Rental::STATUS_ACTIVE]);
        $this->assertSame(1, Rental::activeListCount());

        $rental->update(['status' => Rental::STATUS_CANCELLED]);
        $this->assertSame(0, Rental::activeListCount());
    }

    /**
     * Assert what the sidebar Active Rentals badge renders.
     *
     * The badge is compared against its own element rather than a bare number so
     * a matching digit elsewhere in the page cannot make this pass.
     */
    private function assertSidebarActiveRentalsBadge(TestResponse $response, int $expected): void
    {
        $matched = preg_match(
            '/id="sidebarActiveRentalsBadge"[^>]*>([^<]*)</',
            $response->getContent(),
            $matches
        );

        $this->assertSame(1, $matched, 'Active Rentals sidebar badge was not rendered.');
        $this->assertSame((string) $expected, trim($matches[1]));
    }
}
