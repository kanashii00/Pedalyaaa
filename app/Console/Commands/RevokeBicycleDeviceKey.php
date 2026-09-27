<?php

namespace App\Console\Commands;

use App\Models\Bicycle;
use Illuminate\Console\Command;

/**
 * Removes a bicycle's device credential. Use when a unit is lost, stolen or
 * being redeployed, so the old key stops working immediately.
 */
class RevokeBicycleDeviceKey extends Command
{
    protected $signature = 'devices:revoke-key
                            {bicycle : Bicycle id or serial number}';

    protected $description = 'Revoke the per-bicycle API key for the on-board ESP32 display';

    public function handle(): int
    {
        $identifier = (string) $this->argument('bicycle');

        $bicycle = ctype_digit($identifier)
            ? Bicycle::find((int) $identifier)
            : Bicycle::where('serialNumber', $identifier)->first();

        if (!$bicycle) {
            $this->error("No bicycle found for '{$identifier}'.");

            return Command::FAILURE;
        }

        if (!$bicycle->hasDeviceKey()) {
            $this->warn("Bicycle #{$bicycle->id} ({$bicycle->serialNumber}) has no device key to revoke.");

            return Command::SUCCESS;
        }

        $bicycle->revokeDeviceKey();

        $this->info("Device key revoked for bicycle #{$bicycle->id} ({$bicycle->serialNumber}).");
        $this->line('Issue a new one with: php artisan devices:provision-key ' . $bicycle->id . ' --rotate');

        return Command::SUCCESS;
    }
}
