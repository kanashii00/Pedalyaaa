<?php

namespace App\Console\Commands;

use App\Services\RentalService;
use Illuminate\Console\Command;

class CheckOverdueReturnDeadlines extends Command
{
    protected $signature = 'rentals:check-return-deadlines';

    protected $description = 'Mark awaiting-return rentals as overdue_return when their return deadline has passed';

    public function handle(RentalService $rentalService): int
    {
        $overdueCount = $rentalService->markOverdueReturnDeadlines();

        if ($overdueCount === 0) {
            $this->info('No return deadlines have passed.');
            return Command::SUCCESS;
        }

        $this->info("Processed {$overdueCount} overdue return(s).");

        return Command::SUCCESS;
    }
}