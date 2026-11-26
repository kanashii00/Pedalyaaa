<?php

namespace Tests\Feature;

use App\Exceptions\RentalException;
use App\Models\Bicycle;
use App\Models\Notification;
use App\Models\Rental;
use App\Models\User;
use App\Services\RentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class RiderAppTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'broadcasting.default' => 'log',
            'pedalya.single_bike_mode' => ['enabled' => false],
        ]);
    }

    private function riderAuth(): User
    {
        $rider = $this->makeRider();
        $this->actingAs($rider);
        return $rider;
    }

    // ---- AuthenticatedSessionController ----

    public function test_login_page_renders(): void
    {
        $this->get(route('login'))->assertOk();
    }

    public function test_login_password_toggle_is_labeled_and_keyboard_reachable(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<button\b(?=[^>]*id="togglePassword")(?=[^>]*aria-label="Show password")(?=[^>]*aria-pressed="false")[^>]*>/',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<button\b(?=[^>]*id="togglePassword")[^>]*tabindex="-1"/',
            $html,
        );
    }

    public function test_home_navigation_toggle_has_accessible_name_and_state(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<button\b(?=[^>]*class="navbar-toggler")(?=[^>]*aria-controls="landingNavbar")(?=[^>]*aria-expanded="false")(?=[^>]*aria-label="Toggle navigation")[^>]*>/',
            $html,
        );
    }

    public function test_public_page_skips_unneeded_map_and_disabled_broadcast_assets(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('maplibre-gl@4.7.1', false)
            ->assertDontSee('laravel-echo@1.16.1', false)
            ->assertDontSee('pusher-js@8.4.0', false);
    }

    public function test_map_page_loads_maplibre_and_enabled_broadcast_assets(): void
    {
        $this->riderAuth();

        $this->get(route('rider.dashboard'))
            ->assertOk()
            ->assertSee('maplibre-gl@4.7.1', false)
            ->assertDontSee('laravel-echo@1.16.1', false);

        config(['broadcasting.default' => 'reverb']);

        $this->get(route('rider.dashboard'))
            ->assertOk()
            ->assertSee('maplibre-gl@4.7.1', false)
            ->assertSee('laravel-echo@1.16.1', false)
            ->assertSee('pusher-js@8.4.0', false);
    }

    public function test_login_page_uses_https_assets_behind_a_proxy(): void
    {
        $response = $this->withServerVariables([
            'HTTP_HOST' => 'pedalya-demo.onrender.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('/login')
            ->assertOk();

        $this->assertMatchesRegularExpression(
            '~href="https://[^"]+/css/style\.css"~',
            $response->getContent(),
        );
    }

    public function test_login_page_never_shows_demo_accounts_even_when_a_demo_password_is_configured(): void
    {
        config(['services.demo_account_password' => 'public-demo-pass']);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Demo accounts')
            ->assertDontSee('demo-accounts-title')
            ->assertDontSee('admin@pedalya.com')
            ->assertDontSee('juan.delacruz@jmc.edu.ph')
            ->assertDontSee('pedro.mendoza@jmc.edu.ph')
            ->assertDontSee('public-demo-pass')
            ->assertDontSee('shared public demo accounts');
    }

    public function test_login_page_hides_demo_accounts_when_no_demo_password_is_configured(): void
    {
        config(['services.demo_account_password' => null]);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Demo accounts')
            ->assertDontSee('admin@pedalya.com');
    }

    public function test_login_page_still_renders_the_sign_in_form(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Sign In', false)
            ->assertSee('Email Address', false)
            ->assertSee('Password', false)
            ->assertSee('Remember me', false);
    }

    public function test_login_rider_succeeds(): void
    {
        $rider = $this->makeRider();
        $this->post(route('login'), ['email' => $rider->email, 'password' => 'password'])
            ->assertRedirect(route('rider.dashboard'));
    }

    public function test_login_admin_redirects_to_admin_dashboard(): void
    {
        $admin = $this->makeAdmin();
        $this->post(route('login'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_login_wrong_credentials(): void
    {
        $this->post(route('login'), ['email' => 'nobody@pedalya.test', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
    }

    public function test_login_inactive_user_rejected(): void
    {
        $user = $this->makeRider(['status' => User::STATUS_INACTIVE]);
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }

    public function test_logout(): void
    {
        $this->riderAuth();
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ---- Rider Dashboard ----

    public function test_rider_dashboard_renders(): void
    {
        $rider = $this->riderAuth();
        $bike = $this->makeBicycle();
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_PENDING]);

        $this->get(route('rider.dashboard'))->assertOk();
    }

    // ---- Rent ----

    public function test_rent_index_lists_available_bicycles(): void
    {
        $this->riderAuth();
        $this->makeBicycle();
        $this->get(route('rider.rentals.create'))->assertOk();
    }

    public function test_rent_store_cash(): void
    {
        $rider = $this->riderAuth();
        $bike = $this->makeBicycle();
        $this->mock(RentalService::class, function ($mock) {
            $mock->shouldReceive('startRental')->once();
        });

        $this->post(route('rider.rent.store'), [
            'bicycleId' => $bike->id,
            'paymentMethod' => 'cash',
            'durationMinutes' => 120,
        ])->assertRedirect(route('rider.dashboard'));
    }

    public function test_rent_store_rejects_non_cash_payment_methods(): void
    {
        $this->riderAuth();
        $bike = $this->makeBicycle();

        $this->post(route('rider.rent.store'), [
            'bicycleId' => $bike->id,
            'paymentMethod' => 'gcash',
            'durationMinutes' => 120,
            'paymentReference' => 'REF-123',
        ])->assertSessionHasErrors('paymentMethod');
    }

    public function test_rent_store_rental_exception(): void
    {
        $this->riderAuth();
        $bike = $this->makeBicycle();
        $this->mock(RentalService::class, function ($mock) {
            $mock->shouldReceive('startRental')->once()->andThrow(new RentalException('Not available'));
        });

        $this->post(route('rider.rent.store'), [
            'bicycleId' => $bike->id,
            'paymentMethod' => 'cash',
            'durationMinutes' => 120,
        ])->assertSessionHasErrors('bicycleId');
    }

    public function test_rent_store_generic_exception(): void
    {
        $this->riderAuth();
        $bike = $this->makeBicycle();
        $this->mock(RentalService::class, function ($mock) {
            $mock->shouldReceive('startRental')->once()->andThrow(new \RuntimeException('boom'));
        });

        $this->post(route('rider.rent.store'), [
            'bicycleId' => $bike->id,
            'paymentMethod' => 'cash',
            'durationMinutes' => 120,
        ])->assertSessionHasErrors('bicycleId');
    }

    public function test_rent_history_renders(): void
    {
        $rider = $this->riderAuth();
        $bike = $this->makeBicycle();
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_COMPLETED, 'durationMinutes' => 90]);

        $this->get(route('rider.rentals.index'))->assertOk();
    }

    public function test_rent_return_rental(): void
    {
        $rider = $this->riderAuth();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);

        $this->mock(RentalService::class, function ($mock) {
            $mock->shouldReceive('synchronizeLifecycle')->andReturn(['expired' => 0, 'overdue_return' => 0]);
            $mock->shouldReceive('returnRental')->once();
        });

        $this->post(route('rider.rentals.return', $rental->id), [
            'end_lat' => 14.6, 'end_lng' => 120.98, 'payment_method' => 'cash',
        ])->assertRedirect(route('rider.dashboard'));
    }

    public function test_rent_return_ownership_restricted(): void
    {
        $other = $this->makeRider();
        $this->riderAuth();
        $bike = $this->makeBicycle();
        $rental = $this->makeRental(['riderId' => $other->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);

        $this->post(route('rider.rentals.return', $rental->id))->assertNotFound();
    }

    // ---- Profile ----

    public function test_profile_show_renders(): void
    {
        $this->riderAuth();
        $this->get(route('rider.profile.index'))->assertOk();
    }

    public function test_profile_update(): void
    {
        $rider = $this->riderAuth();
        $this->put(route('rider.profile.update'), [
            'displayName' => 'New Name',
            'phoneNumber' => '0917',
            'address' => 'Davao',
        ])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $rider->id, 'name' => 'New Name', 'phoneNumber' => '0917']);
    }

    public function test_profile_update_password_wrong_current(): void
    {
        $this->riderAuth();
        $this->put(route('rider.profile.update-password'), [
            'current_password' => 'wrong',
            'password' => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ])->assertSessionHasErrors('current_password');
    }

    public function test_profile_update_password_ok(): void
    {
        $rider = $this->riderAuth();
        $this->put(route('rider.profile.update-password'), [
            'current_password' => 'password',
            'password' => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('newpassword1', $rider->fresh()->password));
    }

    public function test_profile_upload_id(): void
    {
        $rider = $this->riderAuth();
        Storage::fake('id_verifications');
        $pdf = '%PDF-1.4 fake pdf document for id verification';

        $this->post(route('rider.profile.upload-id'), [
            'id_image_base64' => 'data:application/pdf;base64,' . base64_encode($pdf),
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $rider->id, 'idUploaded' => true]);
    }

    public function test_profile_accepts_uploaded_pdf_id_documents(): void
    {
        $rider = $this->riderAuth();
        Storage::fake('id_verifications');
        $path = tempnam(sys_get_temp_dir(), 'id-pdf');
        file_put_contents($path, "%PDF-1.4\nID verification test document\n");

        try {
            $this->post(route('rider.profile.upload-id'), [
                'id_image' => new UploadedFile($path, 'identity.pdf', 'application/pdf', null, true),
            ])->assertRedirect()->assertSessionHasNoErrors();
        } finally {
            @unlink($path);
        }

        $rider->refresh();
        $this->assertTrue($rider->idUploaded);
        $this->assertSame('pdf', pathinfo($rider->idVerification['id_path'], PATHINFO_EXTENSION));
        Storage::disk('id_verifications')->assertExists($rider->idVerification['id_path']);
    }

    public function test_profile_delete_with_active_rental_blocked(): void
    {
        $rider = $this->riderAuth();
        $bike = $this->makeBicycle();
        $this->makeRental(['riderId' => $rider->id, 'bicycleId' => $bike->id, 'status' => Rental::STATUS_ACTIVE]);

        $this->delete(route('rider.profile.delete'))->assertSessionHasErrors('account');
    }

    public function test_profile_delete(): void
    {
        $rider = $this->riderAuth();
        $this->delete(route('rider.profile.delete'))->assertRedirect(route('home'));
        $this->assertNull(User::find($rider->id));
    }

    // ---- Rider Notifications ----

    public function test_rider_notifications_index_renders(): void
    {
        $rider = $this->riderAuth();
        Notification::create(['userId' => $rider->id, 'title' => 'A', 'message' => 'm']);
        $this->get(route('rider.notifications.index'))->assertOk();
    }

    public function test_rider_notification_mark_read(): void
    {
        $rider = $this->riderAuth();
        $n = Notification::create(['userId' => $rider->id, 'title' => 'A', 'message' => 'm', 'read' => false]);

        $this->post(route('rider.notifications.mark-read', $n->id))->assertRedirect();
        $this->assertDatabaseHas('notifications', ['id' => $n->id, 'read' => true]);
    }

    public function test_rider_notification_mark_all_read(): void
    {
        $rider = $this->riderAuth();
        Notification::create(['userId' => $rider->id, 'title' => 'A', 'message' => 'm', 'read' => false]);
        Notification::create(['userId' => $rider->id, 'title' => 'B', 'message' => 'm', 'read' => false]);

        $this->post(route('rider.notifications.mark-all-read'))->assertRedirect();
        $this->assertSame(0, Notification::where('userId', $rider->id)->where('read', false)->count());
    }
}
