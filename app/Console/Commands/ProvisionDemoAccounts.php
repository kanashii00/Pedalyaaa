<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Provisions the shared demo logins used on the public demo host.
 *
 * The dev seeders build the same roster around a hardcoded password, which is
 * fine locally and unacceptable on a public deployment. This command takes the
 * password as input so it can come from the environment instead of the
 * repository, and it is safe to re-run: accounts are matched on their email and
 * restored if they were ever soft deleted, so provisioning twice will not trip
 * the unique index on users.email.
 */
class ProvisionDemoAccounts extends Command
{
    protected $signature = 'demo:provision-accounts
                            {--password= : Shared demo password; defaults to the DEMO_ACCOUNT_PASSWORD environment variable}
                            {--keep-existing-passwords : Leave the password of accounts that already exist untouched}';

    protected $description = 'Create or refresh the shared admin and rider demo logins';

    public function handle(): int
    {
        $password = (string) ($this->option('password') ?: config('services.demo_account_password'));

        if ($password === '') {
            $this->error('No demo password was supplied.');
            $this->line('Pass --password="..." or set DEMO_ACCOUNT_PASSWORD in the environment.');

            return Command::FAILURE;
        }

        $keepPasswords = (bool) $this->option('keep-existing-passwords');
        $rows = [];
        $created = 0;

        foreach ($this->roster() as $account) {
            $existing = User::withTrashed()->where('email', $account['email'])->first();
            $isNew = $existing === null;

            $attributes = [
                'name' => $account['name'],
                'role' => $account['role'],
                'status' => User::STATUS_ACTIVE,
                'verified' => true,
                'email_verified_at' => $existing?->email_verified_at ?? now(),
                'phoneNumber' => $account['phone'],
                'address' => $account['address'],
                'blacklistReason' => null,
            ];

            if ($isNew || ! $keepPasswords) {
                $attributes['password'] = $password;
            }

            $user = User::withTrashed()->updateOrCreate(
                ['email' => $account['email']],
                $attributes
            );

            if ($user->trashed()) {
                $user->restore();
            }

            if ($isNew) {
                $created++;
            }

            $rows[] = [
                $user->email,
                $user->role,
                $isNew ? 'created' : ($keepPasswords ? 'unchanged' : 'password reset'),
            ];
        }

        $this->table(['Email', 'Role', 'Result'], $rows);
        $this->newLine();
        $this->info("{$created} account(s) created, ".count($rows)." total in the roster.");
        $this->newLine();
        $this->line("  Password: {$password}");

        if (! $keepPasswords) {
            $this->newLine();
            $this->comment('These logins are shared and public. Rotate the password before any real rider data lands here.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array{name: string, email: string, role: string, phone: string, address: string}>
     */
    private function roster(): array
    {
        return [
            [
                'name' => 'System Administrator',
                'email' => 'admin@pedalya.com',
                'role' => User::ROLE_ADMIN,
                'phone' => '+63 917 000 0001',
                'address' => 'Pedalya HQ, Manila, Philippines',
            ],
            [
                'name' => 'Juan Dela Cruz',
                'email' => 'juan.delacruz@jmc.edu.ph',
                'role' => User::ROLE_RIDER,
                'phone' => '+63 917 123 4567',
                'address' => 'Quezon City, Metro Manila',
            ],
            [
                'name' => 'Maria Santos',
                'email' => 'maria.santos@jmc.edu.ph',
                'role' => User::ROLE_RIDER,
                'phone' => '+63 918 234 5678',
                'address' => 'Makati City, Metro Manila',
            ],
            [
                'name' => 'Jose Reyes',
                'email' => 'jose.reyes@jmc.edu.ph',
                'role' => User::ROLE_RIDER,
                'phone' => '+63 919 345 6789',
                'address' => 'Pasig City, Metro Manila',
            ],
            [
                'name' => 'Ana Garcia',
                'email' => 'ana.garcia@jmc.edu.ph',
                'role' => User::ROLE_RIDER,
                'phone' => '+63 920 456 7890',
                'address' => 'Taguig City, Metro Manila',
            ],
            [
                'name' => 'Pedro Mendoza',
                'email' => 'pedro.mendoza@jmc.edu.ph',
                'role' => User::ROLE_RIDER,
                'phone' => '+63 921 567 8901',
                'address' => 'Mandaluyong City, Metro Manila',
            ],
        ];
    }
}
