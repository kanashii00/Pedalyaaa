<?php

namespace App\Console\Commands;

use App\Models\Bicycle;
use App\Models\Rental;
use App\Models\User;
use App\Services\RentalService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CreateTestRental extends Command
{
    protected $signature = 'rental:test
                            {--bicycle= : Bicycle id (defaults to the first available one)}
                            {--rider= : User id of a verified rider (defaults to the first one found)}
                            {--seconds=15 : Length of the countdown before the rental expires}';

    protected $description = 'Start a rental with a second-level countdown for local testing of the rider UI, LCD and expiry flow';

    public function handle(RentalService $rentalService): int
    {
        $seconds = max(1, (int) $this->option('seconds'));

        $bicycle = $this->option('bicycle')
            ? Bicycle::find($this->option('bicycle'))
            : Bicycle::where('status', Bicycle::STATUS_AVAILABLE)->orderBy('id')->first();

        if (! $bicycle) {
            $this->error('No bicycle available. Pass --bicycle=<id> and make sure its status is "available".');
            return Command::FAILURE;
        }

        $rider = $this->option('rider')
            ? User::find($this->option('rider'))
            : User::where('verified', true)->where('role', User::ROLE_RIDER)->orderBy('id')->first();

        if (! $rider) {
            $this->error('No verified rider found. Pass --rider=<id> for a user with verified = true.');
            return Command::FAILURE;
        }

        if (! $rider->verified) {
            $this->error("Rider {$rider->email} is not verified, so startRental() would reject the request.");
            return Command::FAILURE;
        }

        $blocking = Rental::where('riderId', $rider->id)
            ->whereIn('status', ['active', 'pending', 'overdue', Rental::STATUS_AWAITING_RETURN])
            ->first();

        if ($blocking) {
            $this->error("Rider {$rider->email} already has rental {$blocking->rentalId} ({$blocking->status}).");
            $this->line('  Close it first, or pass --rider=<id> for a different rider.');
            return Command::FAILURE;
        }

        try {
            // durationMinutes stays at 1 so pricing, chargedHours and the reports
            // all keep their real values. Only the clock below is compressed.
            $rental = $rentalService->startRental($rider, $bicycle->id, 1, 'cash');
        } catch (\Throwable $e) {
            $this->error('startRental failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $deadline = $rental->startTime->copy()->addSeconds($seconds);
        $rental->update(['expectedEndTime' => $deadline]);

        $this->newLine();
        $this->info("Started test rental {$rental->rentalId}");
        $this->table(
            ['Field', 'Value'],
            [
                ['Bicycle', "{$bicycle->serialNumber} - {$bicycle->name} (id {$bicycle->id})"],
                ['Rider', $rider->email],
                ['Status', $rental->status],
                ['Started', (string) $rental->startTime],
                ['Expires at', (string) $deadline],
                ['Countdown', $seconds . 's'],
                ['Duration field', $rental->durationMinutes . ' min (unchanged)'],
                ['Fee', 'PHP ' . number_format((float) $rental->totalFee, 2) . ' (' . $rental->chargedHours . ' charged hour)'],
            ]
        );

        $this->newLine();
        $this->line("  Rider UI : <info>http://localhost:8000/rider/dashboard</info>");
        $this->line("  Device   : <info>GET /api/iot/bicycle/{$bicycle->id}/rental</info> shows remaining seconds");
        $this->line("  Expire   : run <info>php artisan rentals:check-overdue</info> after it counts down to flip status to expired");

        return Command::SUCCESS;
    }
}
