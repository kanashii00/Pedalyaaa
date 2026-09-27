<?php

namespace App\Console\Commands;

use App\Models\Bicycle;
use Illuminate\Console\Command;

/**
 * Issues the per-bicycle device credential used by the on-board ESP32 display.
 *
 * The plaintext key is printed exactly once and only its SHA-256 digest is
 * stored, so a lost key must be rotated rather than recovered. Rotating
 * immediately locks the old unit out of the rental endpoint.
 */
class ProvisionBicycleDeviceKey extends Command
{
    protected $signature = 'devices:provision-key
                            {bicycle : Bicycle id or serial number}
                            {--rotate : Replace an existing key, locking out the current unit}';

    protected $description = 'Issue (or rotate) the per-bicycle API key for the on-board ESP32 display';

    public function handle(): int
    {
        $bicycle = $this->resolveBicycle((string) $this->argument('bicycle'));

        if (!$bicycle) {
            return Command::FAILURE;
        }

        if ($bicycle->hasDeviceKey() && !$this->option('rotate')) {
            $this->error("Bicycle #{$bicycle->id} ({$bicycle->serialNumber}) already has a device key.");
            $this->line('Re-run with --rotate to replace it. The current unit will be locked out.');

            return Command::FAILURE;
        }

        $plain = $bicycle->issueDeviceKey();

        $this->info("Device key issued for bicycle #{$bicycle->id} ({$bicycle->serialNumber}).");
        $this->newLine();
        $this->line("  X-API-Key: {$plain}");
        $this->newLine();
        $this->comment('Store this now. Only a SHA-256 digest is kept, so it cannot be shown again.');
        $this->comment("Endpoint: GET /api/iot/bicycle/{$bicycle->id}/rental");

        return Command::SUCCESS;
    }

    private function resolveBicycle(string $identifier): ?Bicycle
    {
        $bicycle = ctype_digit($identifier)
            ? Bicycle::find((int) $identifier)
            : Bicycle::where('serialNumber', $identifier)->first();

        if (!$bicycle) {
            $this->error("No bicycle found for '{$identifier}'.");

            return null;
        }

        return $bicycle;
    }
}
