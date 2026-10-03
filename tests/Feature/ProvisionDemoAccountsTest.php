<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProvisionDemoAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_one_admin_and_five_rider_demo_accounts(): void
    {
        $this->artisan('demo:provision-accounts', ['--password' => 'demo-pass-123'])
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(6, User::count());
        $this->assertSame(1, User::where('role', User::ROLE_ADMIN)->count());
        $this->assertSame(5, User::where('role', User::ROLE_RIDER)->count());
    }

    public function test_the_admin_is_active_verified_and_can_authenticate(): void
    {
        $this->artisan('demo:provision-accounts', ['--password' => 'demo-pass-123']);

        $admin = User::where('email', 'admin@pedalya.com')->first();

        $this->assertNotNull($admin);
        $this->assertSame(User::ROLE_ADMIN, $admin->role);
        $this->assertSame(User::STATUS_ACTIVE, $admin->status);
        $this->assertTrue($admin->verified);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check('demo-pass-123', $admin->password));
    }

    public function test_riders_can_authenticate_too(): void
    {
        $this->artisan('demo:provision-accounts', ['--password' => 'demo-pass-123']);

        $rider = User::where('email', 'juan.delacruz@jmc.edu.ph')->first();

        $this->assertSame(User::ROLE_RIDER, $rider->role);
        $this->assertTrue(Hash::check('demo-pass-123', $rider->password));
    }

    public function test_the_password_is_hashed_and_never_stored_raw(): void
    {
        $this->artisan('demo:provision-accounts', ['--password' => 'demo-pass-123']);

        $stored = User::where('email', 'admin@pedalya.com')->value('password');

        $this->assertNotSame('demo-pass-123', $stored);
        $this->assertStringStartsWith('$2y$', $stored);
    }

    public function test_it_refuses_to_run_without_a_password(): void
    {
        config(['services.demo_account_password' => null]);

        $this->artisan('demo:provision-accounts')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, User::count());
    }

    public function test_it_falls_back_to_the_configured_demo_password(): void
    {
        config(['services.demo_account_password' => 'from-config-456']);

        $this->artisan('demo:provision-accounts')->assertExitCode(Command::SUCCESS);

        $this->assertTrue(Hash::check('from-config-456', User::where('email', 'admin@pedalya.com')->value('password')));
    }

    public function test_re_running_resets_the_password_without_duplicating_accounts(): void
    {
        $this->artisan('demo:provision-accounts', ['--password' => 'first-pass']);
        $this->artisan('demo:provision-accounts', ['--password' => 'second-pass']);

        $this->assertSame(6, User::count());
        $this->assertTrue(Hash::check('second-pass', User::where('email', 'admin@pedalya.com')->value('password')));
    }

    public function test_re_running_can_preserve_the_existing_password(): void
    {
        $this->artisan('demo:provision-accounts', ['--password' => 'first-pass']);
        $this->artisan('demo:provision-accounts', [
            '--password' => 'second-pass',
            '--keep-existing-passwords' => true,
        ]);

        $this->assertTrue(Hash::check('first-pass', User::where('email', 'admin@pedalya.com')->value('password')));
    }

    public function test_it_revives_a_soft_deleted_demo_account(): void
    {
        $this->artisan('demo:provision-accounts', ['--password' => 'first-pass']);
        User::where('email', 'admin@pedalya.com')->delete();

        $this->artisan('demo:provision-accounts', ['--password' => 'second-pass']);

        $this->assertNotNull(User::where('email', 'admin@pedalya.com')->first());
        $this->assertSame(6, User::count());
    }

    public function test_it_clears_a_blocklist_on_the_shared_admin(): void
    {
        $admin = User::create([
            'name' => 'System Administrator',
            'email' => 'admin@pedalya.com',
            'password' => 'whatever',
            'role' => User::ROLE_ADMIN,
            'status' => 'suspended',
            'blacklistReason' => 'stale demo state',
        ]);

        $this->artisan('demo:provision-accounts', ['--password' => 'demo-pass-123']);

        $admin->refresh();

        $this->assertSame(User::STATUS_ACTIVE, $admin->status);
        $this->assertNull($admin->blacklistReason);
    }
}
