<?php

namespace Modules\Cashier\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\Cashier\Models\Cashier;

class CleanupInactiveCashiersCommand extends Command
{
    protected $signature = 'cashiers:cleanup-inactive {--days=90}';

    protected $description = 'Clean up cashiers that have been deactivated for a specified period';

    public function handle(): int
    {
        $days = $this->option('days');
        $this->info("Cleaning up cashiers deactivated for more than {$days} days...");

        $cutoffDate = Carbon::now()->subDays($days);

        $cashiers = Cashier::where('status', 'deactivated')
            ->where('deactivated_at', '<=', $cutoffDate)
            ->whereDoesntHave('shifts', function ($query) {
                $query->where('status', 'in_progress');
            })
            ->get();

        if ($cashiers->isEmpty()) {
            $this->info('No inactive cashiers found for cleanup.');

            return Command::SUCCESS;
        }

        $count = 0;
        foreach ($cashiers as $cashier) {
            try {
                $this->info("Deleting cashier: {$cashier->name} ({$cashier->email})");
                $cashier->delete();
                $count++;
            } catch (\Exception $e) {
                $this->error("Failed to delete cashier {$cashier->id}: {$e->getMessage()}");
            }
        }

        $this->info("Successfully cleaned up {$count} inactive cashiers.");

        return Command::SUCCESS;
    }
}
