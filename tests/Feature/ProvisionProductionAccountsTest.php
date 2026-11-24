<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProductionAccountProvisioner;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PDO;
use PDOException;
use Tests\TestCase;

class ProvisionProductionAccountsTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIRMATION_PROMPT = 'Type PRODUCTION to confirm this is the intended production database';

    private const ACCOUNTS = [
        ['name' => 'Kyra Horie', 'email' => 'kyra.horie@jmc.edu.ph', 'role' => User::ROLE_ADMIN],
        ['name' => 'April Domopoy', 'email' => 'april.domopoy@jmc.edu.ph', 'role' => User::ROLE_ADMIN],
        ['name' => 'Kent Ingente', 'email' => 'kent.ingente@jmc.edu.ph', 'role' => User::ROLE_RIDER],
    ];

    public function test_it_creates_requested_roles_with_hashed_passwords(): void
    {
        $passwords = [
            'kyra.horie@jmc.edu.ph' => 'Kyra!Secure2026',
            'april.domopoy@jmc.edu.ph' => 'April!Secure2026',
            'kent.ingente@jmc.edu.ph' => 'Kent!Secure2026',
        ];
        $provisioner = app(ProductionAccountProvisioner::class);

        foreach (self::ACCOUNTS as $account) {
            $this->assertTrue($provisioner->create($account, $passwords[$account['email']]));

            $user = User::where('email', $account['email'])->firstOrFail();
            $this->assertSame($account['role'], $user->role);
            $this->assertSame(User::STATUS_ACTIVE, $user->status);
            $this->assertTrue(Hash::check($passwords[$account['email']], $user->password));
            $this->assertNotSame($passwords[$account['email']], $user->password);
        }
    }

    public function test_it_leaves_existing_and_soft_deleted_accounts_unchanged(): void
    {
        foreach (self::ACCOUNTS as $account) {
            User::create([
                'name' => $account['name'],
                'email' => $account['email'],
                'password' => 'Existing!Account2026',
                'role' => $account['role'],
                'status' => User::STATUS_SUSPENDED,
            ]);
        }

        User::where('email', self::ACCOUNTS[0]['email'])->delete();

        $provisioner = app(ProductionAccountProvisioner::class);
        $expectedEmails = array_column(self::ACCOUNTS, 'email');
        sort($expectedEmails);
        $existingEmails = $provisioner->existingEmails(array_column(self::ACCOUNTS, 'email'));
        sort($existingEmails);
        $this->assertSame($expectedEmails, $existingEmails);

        $before = User::withTrashed()
            ->whereIn('email', array_column(self::ACCOUNTS, 'email'))
            ->get()
            ->mapWithKeys(fn (User $user): array => [(string) $user->id => $user->getAttributes()])
            ->all();

        foreach (self::ACCOUNTS as $account) {
            $this->assertFalse($provisioner->create($account, 'Ignored!Password2026'));
        }

        $after = User::withTrashed()
            ->whereIn('email', array_column(self::ACCOUNTS, 'email'))
            ->get()
            ->mapWithKeys(fn (User $user): array => [(string) $user->id => $user->getAttributes()])
            ->all();

        $this->assertSame($before, $after);
        $this->assertSame(3, User::withTrashed()->count());
    }

    public function test_command_rejects_weak_passwords_without_passing_them_to_the_provisioner(): void
    {
        $provisioner = \Mockery::mock(ProductionAccountProvisioner::class);
        $provisioner->shouldReceive('existingEmails')->once()->andReturn([]);
        $provisioner->shouldReceive('create')
            ->twice()
            ->withArgs(fn (array $account, string $password): bool => $password !== 'weak-password')
            ->andReturn(true);
        $this->app->instance(ProductionAccountProvisioner::class, $provisioner);

        $passwords = [
            'kyra.horie@jmc.edu.ph' => 'weak-password',
            'april.domopoy@jmc.edu.ph' => 'April!Secure2026',
            'kent.ingente@jmc.edu.ph' => 'Kent!Secure2026',
        ];

        $command = $this->artisan('accounts:provision-production');
        $command->expectsQuestion(self::CONFIRMATION_PROMPT, 'PRODUCTION');

        foreach (self::ACCOUNTS as $account) {
            $password = $passwords[$account['email']];
            $command->expectsQuestion("Password for {$account['email']}", $password);
            $command->expectsQuestion("Confirm password for {$account['email']}", $password);
            $command->doesntExpectOutputToContain($password);
        }

        $command->expectsOutputToContain('NOT CREATED (weak password)');
        $command->assertExitCode(Command::FAILURE);
    }

    public function test_command_requires_the_exact_production_confirmation_before_checking_accounts(): void
    {
        $provisioner = \Mockery::mock(ProductionAccountProvisioner::class);
        $provisioner->shouldNotReceive('existingEmails');
        $provisioner->shouldNotReceive('create');
        $this->app->instance(ProductionAccountProvisioner::class, $provisioner);

        $command = $this->artisan('accounts:provision-production');
        $command->expectsQuestion(self::CONFIRMATION_PROMPT, 'production');
        $command->expectsOutputToContain('Production confirmation did not match. No accounts were changed.');
        $command->assertExitCode(Command::FAILURE);
    }

    public function test_production_identity_is_displayed_before_the_confirmation_prompt(): void
    {
        $this->app['env'] = 'production';

        $connection = \Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $connection->shouldReceive('getPdo')->once()->andReturn(new PDO('sqlite::memory:'));
        $connection->shouldReceive('selectOne')
            ->once()
            ->with('SELECT DATABASE() AS database_name, @@hostname AS host_identifier')
            ->andReturn((object) [
                'database_name' => 'pedalya_production',
                'host_identifier' => 'mysql-production-node',
            ]);
        DB::shouldReceive('connection')->andReturn($connection);

        $provisioner = \Mockery::mock(ProductionAccountProvisioner::class);
        $provisioner->shouldNotReceive('existingEmails');
        $this->app->instance(ProductionAccountProvisioner::class, $provisioner);

        $command = $this->artisan('accounts:provision-production');
        $command->expectsOutputToContain('Connected database: pedalya_production; host identifier: mysql-production-node');
        $command->expectsQuestion(self::CONFIRMATION_PROMPT, 'not-production');
        $command->assertExitCode(Command::FAILURE);
    }

    public function test_command_rejects_reusing_a_password_for_another_account(): void
    {
        $provisioner = \Mockery::mock(ProductionAccountProvisioner::class);
        $provisioner->shouldReceive('existingEmails')->once()->andReturn([]);
        $provisioner->shouldReceive('create')
            ->twice()
            ->andReturn(true);
        $this->app->instance(ProductionAccountProvisioner::class, $provisioner);

        $passwords = [
            'kyra.horie@jmc.edu.ph' => 'Kyra!Secure2026',
            'april.domopoy@jmc.edu.ph' => 'Kyra!Secure2026',
            'kent.ingente@jmc.edu.ph' => 'Kent!Secure2026',
        ];

        $command = $this->artisan('accounts:provision-production');
        $command->expectsQuestion(self::CONFIRMATION_PROMPT, 'PRODUCTION');

        foreach (self::ACCOUNTS as $account) {
            $password = $passwords[$account['email']];
            $command->expectsQuestion("Password for {$account['email']}", $password);
            $command->expectsQuestion("Confirm password for {$account['email']}", $password);
            $command->doesntExpectOutputToContain($password);
        }

        $command->expectsOutputToContain('NOT CREATED (password reused)');
        $command->assertExitCode(Command::FAILURE);
    }

    public function test_write_failure_stops_later_password_prompts_and_reports_uncertain_results(): void
    {
        $provisioner = \Mockery::mock(ProductionAccountProvisioner::class);
        $provisioner->shouldReceive('existingEmails')->once()->andReturn([]);
        $provisioner->shouldReceive('create')->once()->andThrow(new PDOException('private write details'));
        $this->app->instance(ProductionAccountProvisioner::class, $provisioner);

        $password = 'Kyra!Secure2026';
        $command = $this->artisan('accounts:provision-production');
        $command->expectsQuestion(self::CONFIRMATION_PROMPT, 'PRODUCTION');
        $command->expectsQuestion('Password for kyra.horie@jmc.edu.ph', $password);
        $command->expectsQuestion('Confirm password for kyra.horie@jmc.edu.ph', $password);
        $command->doesntExpectOutputToContain($password);
        $command->doesntExpectOutputToContain('private write details');
        $command->expectsOutputToContain('NOT CONFIRMED (database write error; verify before retrying)');
        $command->expectsOutputToContain('NOT ATTEMPTED (prior database error)');
        $command->assertExitCode(Command::FAILURE);
    }

    public function test_database_connection_failure_stops_before_confirmation_or_password_prompts(): void
    {
        $this->app['env'] = 'production';

        $connection = \Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getPdo')->once()->andThrow(new PDOException('private connection details'));
        $connection->shouldNotReceive('selectOne');

        DB::shouldReceive('connection')->andReturn($connection);

        $command = $this->artisan('accounts:provision-production');
        $command->expectsOutputToContain('Could not verify a safe database connection. No accounts were changed.');
        $command->doesntExpectOutputToContain('private connection details');
        $command->assertExitCode(Command::FAILURE);

        $this->assertSame(0, User::count());
    }
}
