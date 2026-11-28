<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\DeviceCommand;
use App\Models\Rental;
use App\Models\SystemSetting;
use App\Services\RentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class DeviceRentalDisplayTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private Bicycle $bike;

    private string $deviceKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bike = $this->makeBicycle();
        $this->deviceKey = $this->bike->issueDeviceKey();
    }

    private function deviceHeaders(?string $key = null): array
    {
        return ['X-API-Key' => $key ?? $this->deviceKey];
    }

    private function endpoint(Bicycle $bicycle): string
    {
        return "/api/iot/bicycle/{$bicycle->id}/rental";
    }

    public function test_idle_bicycle_reports_a_null_rental(): void
    {
        $response = $this->getJson($this->endpoint($this->bike), $this->deviceHeaders())->assertOk();

        $response->assertJsonPath('data.rental', null);
        $response->assertJsonPath('data.bicycle.id', $this->bike->id);
        $response->assertJsonPath('data.bicycle.serialNumber', $this->bike->serialNumber);
        $this->assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control', ''),
            'the device must always receive a fresh rental snapshot'
        );
    }

    public function test_device_poll_includes_the_bicycles_configured_wheel_circumference(): void
    {
        $this->bike->update(['wheelCircumferenceM' => 2.086]);

        $this->getJson($this->endpoint($this->bike), $this->deviceHeaders())
            ->assertOk()
            ->assertJsonPath('data.bicycle.wheelCircumferenceM', 2.086);
    }

    public function test_device_reads_and_acknowledges_its_existing_command_queue(): void
    {
        $command = DeviceCommand::create([
            'bicycleId' => $this->bike->id,
            'command' => 'buzzer',
            'params' => ['duration_ms' => 250],
            'status' => 'pending',
        ]);

        $this->getJson("/api/iot/bicycle/{$this->bike->id}/commands", $this->deviceHeaders())
            ->assertOk()
            ->assertJsonPath('data.commands.0.id', $command->id)
            ->assertJsonPath('data.commands.0.command', 'buzzer');

        $this->postJson("/api/iot/bicycle/{$this->bike->id}/commands/{$command->id}/ack", [
            'status' => 'executed',
            'result' => 'buzzer played',
        ], $this->deviceHeaders())->assertOk();

        $this->assertSame('executed', $command->fresh()->status);
    }

    public function test_device_cannot_acknowledge_another_bicycles_command(): void
    {
        $other = $this->makeBicycle();
        $command = DeviceCommand::create([
            'bicycleId' => $other->id,
            'command' => 'lock',
            'status' => 'pending',
        ]);

        $this->postJson("/api/iot/bicycle/{$this->bike->id}/commands/{$command->id}/ack", [
            'status' => 'executed',
        ], $this->deviceHeaders())->assertNotFound();
    }

    public function test_active_rental_reports_elapsed_time_and_countdown(): void
    {
        $this->makeRental([
            'bicycleId' => $this->bike->id,
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => now()->subMinutes(20),
            'expectedEndTime' => now()->addMinutes(40),
        ]);

        $response = $this->getJson($this->endpoint($this->bike), $this->deviceHeaders())->assertOk();

        $response->assertJsonPath('data.rental.status', Rental::STATUS_ACTIVE);
        // Countdown targets the expected end time until an admin sets a deadline.
        $response->assertJsonPath('data.rental.deadlineBasis', 'expected_end_time');
        $this->assertEqualsWithDelta(1200, $response->json('data.rental.elapsedSeconds'), 5);
        $this->assertEqualsWithDelta(2400, $response->json('data.rental.remainingSeconds'), 5);
        $response->assertJsonPath('data.rental.isOverdue', false);
        $response->assertJsonPath('data.rental.overdueSeconds', 0);
        $this->assertEqualsWithDelta(0.0, $response->json('data.rental.penalty.accrued'), 0.001);
        $unixSeconds = $response->json('data.clock.unix');
        $unixMilliseconds = $response->json('data.clock.unixMilliseconds');
        $this->assertIsInt($unixMilliseconds);
        $this->assertGreaterThanOrEqual($unixSeconds * 1000, $unixMilliseconds);
        $this->assertLessThan(($unixSeconds + 1) * 1000, $unixMilliseconds);
    }

    public function test_device_derives_deadline_for_legacy_rental_without_deadline_columns(): void
    {
        $startTime = now()->subMinutes(20);
        $expectedDeadline = $startTime->copy()->addMinutes(60);
        $this->makeRental([
            'bicycleId' => $this->bike->id,
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => $startTime,
            'endTime' => null,
            'expectedEndTime' => null,
            'durationMinutes' => 60,
        ]);

        $response = $this->getJson($this->endpoint($this->bike), $this->deviceHeaders())->assertOk();

        $response->assertJsonPath('data.rental.deadlineBasis', 'duration_fallback');
        $this->assertSame(
            $expectedDeadline->timestamp,
            $response->json('data.rental.returnDeadlineTimestamp')
        );
        $this->assertEqualsWithDelta(2400, $response->json('data.rental.remainingSeconds'), 5);
    }

    public function test_device_receives_the_server_return_grace_deadline_after_expiry(): void
    {
        $expectedEnd = now()->subSecond();
        $rental = $this->makeRental([
            'bicycleId' => $this->bike->id,
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => $expectedEnd->copy()->subHour(),
            'expectedEndTime' => $expectedEnd,
        ]);
        app(\App\Services\RentalService::class)->expireDueRentals(now());

        $rental->refresh();

        $response = $this->getJson($this->endpoint($this->bike), $this->deviceHeaders())->assertOk();
        $graceSeconds = RentalService::RETURN_GRACE_SECONDS;
        $expectedGraceDeadline = $expectedEnd->copy()->addSeconds($graceSeconds);

        $this->assertSame(
            $expectedGraceDeadline->getTimestamp(),
            $rental->returnGraceDeadline->getTimestamp()
        );
        $this->assertSame(
            $expectedGraceDeadline->getTimestamp(),
            $response->json('data.rental.returnCountdownDeadlineTimestamp')
        );
        $this->assertSame(
            $expectedEnd->getTimestamp(),
            $response->json('data.rental.returnGraceStartedAtTimestamp')
        );
        $this->assertSame(
            $expectedGraceDeadline->copy()->utc()->toIso8601String(),
            $response->json('data.rental.returnCountdownDeadlineUtc')
        );
    }

    public function test_device_starts_grace_clock_even_before_expiry_status_sync_runs(): void
    {
        $expectedEnd = now()->subSecond();
        $this->makeRental([
            'bicycleId' => $this->bike->id,
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => $expectedEnd->copy()->subHour(),
            'expectedEndTime' => $expectedEnd,
        ]);

        $response = $this->getJson($this->endpoint($this->bike), $this->deviceHeaders())->assertOk();
        $graceSeconds = RentalService::RETURN_GRACE_SECONDS;

        $this->assertSame(
            $expectedEnd->copy()->addSeconds($graceSeconds)->getTimestamp(),
            $response->json('data.rental.returnCountdownDeadlineTimestamp')
        );
    }

    public function test_device_receives_the_grace_deadline_before_rental_expires(): void
    {
        $expectedEnd = now()->addMinutes(40);
        $this->makeRental([
            'bicycleId' => $this->bike->id,
            'status' => Rental::STATUS_ACTIVE,
            'startTime' => now()->subMinutes(20),
            'expectedEndTime' => $expectedEnd,
        ]);

        $response = $this->getJson($this->endpoint($this->bike), $this->deviceHeaders())->assertOk();

        $response->assertJsonPath('data.rental.status', Rental::STATUS_ACTIVE);
        $this->assertSame(
            $expectedEnd->copy()->addSeconds(RentalService::RETURN_GRACE_SECONDS)->getTimestamp(),
            $response->json('data.rental.returnCountdownDeadlineTimestamp')
        );
        $this->assertSame(
            $expectedEnd->copy()->addSeconds(RentalService::RETURN_GRACE_SECONDS)->utc()->toIso8601String(),
            $response->json('data.rental.returnGraceDeadlineUtc')
        );
    }

    public function test_settled_rentals_are_never_presented_as_current(): void
    {
        foreach ([
            Rental::STATUS_RETURNED,
            Rental::STATUS_COMPLETED,
            Rental::STATUS_CANCELLED,
            Rental::STATUS_PENDING,
        ] as $status) {
            $bike = $this->makeBicycle();
            $key = $bike->issueDeviceKey();

            $this->makeRental([
                'bicycleId' => $bike->id,
                'status' => $status,
                'startTime' => now()->subHour(),
                'expectedEndTime' => now()->addHour(),
            ]);

            $this->getJson($this->endpoint($bike), $this->deviceHeaders($key))
                ->assertOk()
                ->assertJsonPath('data.rental', null);
        }
    }

    public function test_admin_deadline_drives_countdown_and_fixed_penalty_basis(): void
    {
        $this->makeRental([
            'bicycleId' => $this->bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
            'startTime' => now()->subHours(3),
            'expectedEndTime' => now()->subHours(2),
            'returnDeadline' => now()->addMinutes(15),
            'penaltyType' => Rental::PENALTY_PER_MINUTE,
            'penaltyValue' => 10.00,
        ]);

        $response = $this->getJson($this->endpoint($this->bike), $this->deviceHeaders())->assertOk();

        $response->assertJsonPath('data.rental.status', Rental::STATUS_AWAITING_RETURN);
        $response->assertJsonPath('data.rental.deadlineBasis', 'return_deadline');
        $this->assertEqualsWithDelta(900, $response->json('data.rental.remainingSeconds'), 5);
        $response->assertJsonPath('data.rental.penalty.basis', 'return_deadline');
        $response->assertJsonPath('data.rental.penalty.rule', Rental::PENALTY_PER_5_MINUTES);
        $this->assertEqualsWithDelta(20.0, $response->json('data.rental.penalty.ruleValue'), 0.001);
        $response->assertJsonPath('data.rental.penalty.isDefaultRate', true);
    }

    public function test_overdue_return_reports_accruing_penalty(): void
    {
        $returnDeadline = now()->subMinutes(25);
        $this->makeRental([
            'bicycleId' => $this->bike->id,
            'status' => Rental::STATUS_OVERDUE_RETURN,
            'startTime' => now()->subHours(2),
            'expectedEndTime' => now()->subHour(),
            'returnDeadline' => $returnDeadline,
            'overdueReturnAt' => now()->subMinutes(25),
            'penaltyType' => Rental::PENALTY_PER_MINUTE,
            'penaltyValue' => 10.00,
        ]);

        $response = $this->getJson($this->endpoint($this->bike), $this->deviceHeaders())->assertOk();

        $response->assertJsonPath('data.rental.status', Rental::STATUS_OVERDUE_RETURN);
        $response->assertJsonPath('data.rental.isOverdue', true);
        $this->assertEqualsWithDelta(1500, $response->json('data.rental.overdueSeconds'), 5);
        $this->assertLessThan(0, $response->json('data.rental.remainingSeconds'));
        $this->assertSame(
            $returnDeadline->copy()->addMinutes(30)->timestamp,
            $response->json('data.rental.penalty.nextPenaltyDeadlineTimestamp')
        );
        // 25 minutes past the return deadline = 5 completed blocks x PHP 20.
        $this->assertEqualsWithDelta(100.0, $response->json('data.rental.penalty.accrued'), 5.0);
    }

    public function test_displayed_penalty_matches_what_process_return_charges(): void
    {
        $admin = $this->makeAdmin();

        $rental = $this->makeRental([
            'bicycleId' => $this->bike->id,
            'status' => Rental::STATUS_OVERDUE_RETURN,
            'startTime' => now()->subHours(5),
            'expectedEndTime' => now()->subHours(3),
            'ratePerHour' => 15.00,
            'returnDeadline' => now()->subMinutes(90),
            'penaltyType' => Rental::PENALTY_PER_HOUR,
            'penaltyValue' => 20.00,
        ]);

        $displayed = $this->getJson($this->endpoint($this->bike), $this->deviceHeaders())
            ->assertOk()
            ->json('data.rental.penalty.accrued');

        // 90 minutes past the return deadline = 18 completed blocks x PHP 20.
        $this->assertEqualsWithDelta(360.0, $displayed, 5.0);

        $this->app->make(\App\Services\RentalService::class)->processReturn(
            $rental,
            $admin,
            ['returnTime' => now()->toDateTimeString(), 'condition' => Rental::CONDITION_GOOD]
        );

        // The figure the rider watched climb is the figure that was charged.
        $this->assertEqualsWithDelta($displayed, $rental->fresh()->overdueFee, 0.01);
    }

    public function test_penalty_ignores_legacy_global_setting_and_uses_fixed_rate(): void
    {
        SystemSetting::setValue('lateReturnPenaltyPerHour', 30);

        $this->makeRental([
            'bicycleId' => $this->bike->id,
            'status' => Rental::STATUS_OVERDUE_RETURN,
            'startTime' => now()->subHours(3),
            'expectedEndTime' => now()->subHours(2),
            'returnDeadline' => now()->subMinutes(90),
        ]);

        $response = $this->getJson($this->endpoint($this->bike), $this->deviceHeaders())->assertOk();

        $response->assertJsonPath('data.rental.penalty.rule', Rental::PENALTY_PER_5_MINUTES);
        $response->assertJsonPath('data.rental.penalty.isDefaultRate', true);
        // 90 minutes past the return deadline = 18 completed blocks x PHP 20.
        $this->assertEqualsWithDelta(360.0, $response->json('data.rental.penalty.accrued'), 5.0);
    }

    public function test_legacy_expected_end_time_penalty_uses_return_grace_deadline(): void
    {
        $this->makeRental([
            'bicycleId' => $this->bike->id,
            'status' => Rental::STATUS_EXPIRED,
            'startTime' => now()->subHours(4),
            'expectedEndTime' => now()->subMinutes(90),
            'ratePerHour' => 15.00,
        ]);

        $response = $this->getJson($this->endpoint($this->bike), $this->deviceHeaders())->assertOk();

        $response->assertJsonPath('data.rental.penalty.basis', 'expected_end_plus_return_grace');
        // The five-minute grace leaves 85 minutes = 17 completed blocks.
        $this->assertEqualsWithDelta(340.0, $response->json('data.rental.penalty.accrued'), 5.0);
    }

    public function test_response_carries_the_server_clock_for_device_time_sync(): void
    {
        $response = $this->getJson($this->endpoint($this->bike), $this->deviceHeaders())->assertOk();

        $this->assertEqualsWithDelta(
            now()->getTimestamp(),
            $response->json('data.clock.unix'),
            5
        );
        $this->assertSame(config('app.timezone'), $response->json('data.clock.timezone'));
        $this->assertSame(config('app.timezone') === 'UTC' ? 0 : 8 * 3600, $response->json('data.clock.utcOffsetSeconds'));
        $this->assertNotNull($response->json('data.clock.serverTimeUtc'));
        $this->assertGreaterThan(0, $response->json('data.pollAfterSeconds'));
    }

    public function test_rental_sync_middleware_promotes_an_expired_rental_before_it_is_reported(): void
    {
        // No scheduler run: the device poll itself must observe the transition.
        $this->makeRental([
            'bicycleId' => $this->bike->id,
            'status' => Rental::STATUS_AWAITING_RETURN,
            'startTime' => now()->subHours(2),
            'expectedEndTime' => now()->subHour(),
            'returnDeadline' => now()->subMinutes(5),
        ]);

        $this->getJson($this->endpoint($this->bike), $this->deviceHeaders())
            ->assertOk()
            ->assertJsonPath('data.rental.status', Rental::STATUS_OVERDUE_RETURN);
    }

    public function test_missing_api_key_is_rejected(): void
    {
        $this->getJson($this->endpoint($this->bike))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Missing API key');
    }

    public function test_invalid_api_key_is_rejected(): void
    {
        $this->getJson($this->endpoint($this->bike), $this->deviceHeaders('wrong-key'))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid API key');
    }

    public function test_key_issued_to_another_bicycle_is_rejected(): void
    {
        $other = $this->makeBicycle();
        $this->makeBicycle(['serialNumber' => $this->bike->serialNumber . 'B']);
        $otherKey = $other->issueDeviceKey();

        $this->getJson($this->endpoint($other), $this->deviceHeaders())
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid API key');

        // And the reverse: this bike's key does not open the other bike.
        $this->getJson($this->endpoint($this->bike), $this->deviceHeaders($otherKey))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid API key');
    }

    public function test_bicycle_without_a_provisioned_key_rejects_everyone(): void
    {
        $bare = $this->makeBicycle();

        $this->getJson($this->endpoint($bare), $this->deviceHeaders())
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid API key');
    }

    public function test_unknown_bicycle_returns_not_found(): void
    {
        $this->getJson('/api/iot/bicycle/999999/rental', $this->deviceHeaders())
            ->assertStatus(404);
    }

    public function test_device_key_is_never_serialised(): void
    {
        $response = $this->getJson($this->endpoint($this->bike), $this->deviceHeaders())->assertOk();

        $this->assertStringNotContainsString(
            $this->bike->fresh()->deviceApiKey,
            $response->getContent()
        );
    }

    public function test_fleet_wide_device_key_does_not_grant_display_access(): void
    {
        config(['services.device_api_key' => 'fleet-key']);

        $this->getJson($this->endpoint($this->bike), ['X-API-Key' => 'fleet-key'])
            ->assertStatus(401);
    }
}
