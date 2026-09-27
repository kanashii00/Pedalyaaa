<?php

namespace App\Console\Commands;

use App\Services\RentalService;
use Illuminate\Console\Command;

class CheckOverdueRentals extends Command
{
    protected $signature = 'rentals:check-overdue';

    protected $description = 'Expire active rentals when their expected end time passes, and notify administrators';

    public function handle(RentalService $rentalService): int
    {
        $expiredCount = $rentalService->expireDueRentals();

        if ($expiredCount === 0) {
            $this->info('No rentals to expire.');
            return Command::SUCCESS;
        }

        $this->info("Processed {$expiredCount} expired rental(s).");

        return Command::SUCCESS;
    }
}