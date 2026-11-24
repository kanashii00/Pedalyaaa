<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ProductionAccountProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Symfony\Component\Console\Question\Question;

class ProvisionProductionAccounts extends Command
{
    protected $signature = 'accounts:provision-production';

    protected $description = 'Interactively provision the approved production accounts';

    /**
     * @var array<int, array{name: string, email: string, role: string}>
     */
    private array $accounts = [
        [
            'name' => 'Kyra Horie',
            'email' => 'kyra.horie@jmc.edu.ph',
            'role' => User::ROLE_ADMIN,
        ],
        [
            'name' => 'April Domopoy',
            'email' => 'april.domopoy@jmc.edu.ph',
            'role' => User::ROLE_ADMIN,
        ],
        [
            'name' => 'Kent Ingente',
            'email' => 'kent.ingente@jmc.edu.ph',
            'role' => User::ROLE_RIDER,
        ],
    ];

    public function handle(ProductionAccountProvisioner $provisioner): int
    {
        try {
            $identity = $this->databaseIdentity();
        } catch (InvalidArgumentException|PDOException|RuntimeException) {
            $this->error('Could not verify a safe database connection. No accounts were changed.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Connected database: %s; host identifier: %s',
            $identity['database'],
            $identity['host']
        ));

        if ($this->prompt('Type PRODUCTION to confirm this is the intended production database') !== 'PRODUCTION') {
            $this->error('Production confirmation did not match. No accounts were changed.');

            return self::FAILURE;
        }

        try {
            $existingEmails = $provisioner->existingEmails(array_column($this->accounts, 'email'));
        } catch (PDOException) {
            $this->error('Could not check the requested email addresses. No accounts were changed.');

            return self::FAILURE;
        }

        $results = [];
        $usedPasswordFingerprints = [];
        $databaseWriteFailed = false;

        foreach ($this->accounts as $account) {
            if ($databaseWriteFailed) {
                $results[] = [$account['email'], $account['role'], 'NOT ATTEMPTED (prior database error)'];

                continue;
            }

            if (in_array($account['email'], $existingEmails, true)) {
                $results[] = [$account['email'], $account['role'], 'ALREADY EXISTS (unchanged)'];

                continue;
            }

            $password = $this->prompt("Password for {$account['email']}", hidden: true);
            $passwordConfirmation = $this->prompt("Confirm password for {$account['email']}", hidden: true);

            if (! is_string($password) || ! is_string($passwordConfirmation) || ! hash_equals($password, $passwordConfirmation)) {
                $this->error("Password confirmation failed for {$account['email']}.");
                $results[] = [$account['email'], $account['role'], 'NOT CREATED (password confirmation failed)'];
                unset($password, $passwordConfirmation);

                continue;
            }

            $validator = Validator::make(
                ['password' => $password],
                ['password' => [
                    'required',
                    'string',
                    Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
                ]]
            );

            if ($validator->fails()) {
                $this->error("Password for {$account['email']} does not meet the strength requirements.");
                $results[] = [$account['email'], $account['role'], 'NOT CREATED (weak password)'];

                unset($password, $passwordConfirmation);

                continue;
            }

            $passwordFingerprint = hash('sha256', $password);

            if (in_array($passwordFingerprint, $usedPasswordFingerprints, true)) {
                $this->error("Use a different password for {$account['email']}.");
                $results[] = [$account['email'], $account['role'], 'NOT CREATED (password reused)'];
                unset($password, $passwordConfirmation);

                continue;
            }

            try {
                $created = $provisioner->create($account, $password);

                $results[] = [
                    $account['email'],
                    $account['role'],
                    $created ? 'CREATED' : 'ALREADY EXISTS (unchanged)',
                ];
                if ($created) {
                    $usedPasswordFingerprints[] = $passwordFingerprint;
                }
            } catch (PDOException) {
                $results[] = [$account['email'], $account['role'], 'NOT CONFIRMED (database write error; verify before retrying)'];
                $databaseWriteFailed = true;
            } finally {
                unset($password, $passwordConfirmation);
            }
        }

        $this->table(['Email', 'Role', 'Result'], $results);

        foreach ($results as $result) {
            if (! in_array($result[2], ['CREATED', 'ALREADY EXISTS (unchanged)'], true)) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array{database: string, host: string}
     */
    private function databaseIdentity(): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if (app()->environment('testing')) {
            if ($driver !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
                throw new RuntimeException('Tests must use the in-memory SQLite database.');
            }

            $connection->getPdo();

            return [
                'database' => 'testing SQLite in-memory database',
                'host' => 'local test connection',
            ];
        }

        if (! app()->environment('production') || $driver !== 'mysql') {
            throw new RuntimeException('Production provisioning requires APP_ENV=production and MySQL.');
        }

        $connection->getPdo();
        $identity = $connection->selectOne(
            'SELECT DATABASE() AS database_name, @@hostname AS host_identifier'
        );

        $database = $this->safeIdentityPart((string) ($identity->database_name ?? ''));
        $host = $this->safeIdentityPart((string) ($identity->host_identifier ?? ''));

        if ($database === '' || $host === '') {
            throw new RuntimeException('The database identity could not be verified.');
        }

        return ['database' => $database, 'host' => $host];
    }

    private function safeIdentityPart(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $value));
    }

    private function prompt(string $text, bool $hidden = false): ?string
    {
        $question = new Question($text);

        if ($hidden) {
            $question->setHidden(true);
            $question->setHiddenFallback(false);
        }

        $answer = $this->getOutput()->askQuestion($question);

        return is_string($answer) ? $answer : null;
    }
}
