<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * A successful rental confirms with a toast, not a banner.
 *
 * The "Rental started successfully" flash must reach the dashboard as an
 * auto-dismissing toast trigger (window.PedalyaNotifications.toast) instead
 * of the old full-width green alert, so the message never pushes or resizes
 * page content. Error and validation banners keep rendering in place.
 */
class RiderFlashToastTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function test_successful_rental_confirms_with_a_toast_not_a_banner(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE, 'serialNumber' => 'PDY-001']);

        $html = $this->actingAs($rider)->followingRedirects()->post(route('rider.rent.store'), [
            'bicycleId' => $bicycle->id,
            'durationMinutes' => 60,
            'paymentMethod' => 'cash',
        ])->getContent();

        $this->assertStringContainsString(
            'Rental started successfully. Pay at the station upon return.',
            $html,
            'the flash message must reach the dashboard'
        );
        $this->assertStringContainsString(
            'PedalyaNotifications.toast',
            $html,
            'the flash must be wired to the toast runtime'
        );
        $this->assertStringNotContainsString(
            'alert-pedalya alert-success',
            $html,
            'the full-width success banner must be gone'
        );
    }

    public function test_error_and_validation_banners_still_render_in_place(): void
    {
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE, 'serialNumber' => 'PDY-001']);

        // Submit from the rent page so back() returns there with the flashed
        // validation errors, exactly as a real failed submission behaves.
        $html = $this->actingAs($rider)
            ->from(route('rider.rentals.create'))
            ->followingRedirects()
            ->post(route('rider.rent.store'), [
                'bicycleId' => $bicycle->id,
                'paymentMethod' => 'cash',
            ])
            ->getContent();

        $this->assertStringContainsString(
            'Please correct the following errors:',
            $html,
            'validation failures must keep their in-place banner'
        );
    }
}
