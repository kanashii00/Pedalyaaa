<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProductionAccountProvisioner
{
    /**
     * @param  array<int, string>  $emails
     * @return array<int, string>
     */
    public function existingEmails(array $emails): array
    {
        $existingEmails = [];

        foreach ($emails as $email) {
            $existingEmail = User::withTrashed()
                ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                ->value('email');

            if (is_string($existingEmail)) {
                $existingEmails[] = strtolower($existingEmail);
            }
        }

        return $existingEmails;
    }

    /**
     * @param  array{name: string, email: string, role: string}  $account
     */
    public function create(array $account, string $password): bool
    {
        return DB::transaction(function () use ($account, $password): bool {
            if (User::withTrashed()
                ->whereRaw('LOWER(email) = ?', [strtolower($account['email'])])
                ->exists()) {
                return false;
            }

            User::create([
                'name' => $account['name'],
                'email' => $account['email'],
                'password' => $password,
                'role' => $account['role'],
                'status' => User::STATUS_ACTIVE,
            ]);

            return true;
        });
    }
}
