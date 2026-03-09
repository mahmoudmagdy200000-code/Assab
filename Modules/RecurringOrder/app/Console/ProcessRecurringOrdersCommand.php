<?php

namespace Modules\RecurringOrder\Console;

use Illuminate\Console\Command;
use Modules\RecurringOrder\Enums\RecurringOrderStatus;
use Modules\RecurringOrder\Jobs\ProcessRecurringOrdersJob;
use Modules\RecurringOrder\Models\RecurringOrder;

class ProcessRecurringOrdersCommand extends Command
{
    protected $signature = 'recurring-orders:process
                            {--sync : Run the job immediately in this process (no queue worker needed). Use for testing or when worker is not running.}
                            {--debug : Show counts of in-progress and due orders per branch (then exit without processing).}';

    protected $description = 'Process due recurring orders (dispatch ProcessRecurringOrdersJob once). Use for testing or manual runs.';

    public function handle(): int
    {
        if ($this->option('debug')) {
            return $this->runDebug();
        }

        if ($this->option('sync')) {
            $this->info('Running ProcessRecurringOrdersJob synchronously...');
            ProcessRecurringOrdersJob::dispatchSync();
            $this->info('Done. Due recurring orders have been processed.');

            return self::SUCCESS;
        }

        $this->info('Dispatching ProcessRecurringOrdersJob...');
        ProcessRecurringOrdersJob::dispatch();
        $this->info('Job dispatched. It will run when a queue worker processes it.');

        return self::SUCCESS;
    }

    /**
     * Show in-progress and due counts per branch to debug empty "in progress" API list.
     */
    private function runDebug(): int
    {
        $now = now();
        $this->info('Server time (app timezone): ' . $now->format('Y-m-d H:i:s T'));
        $this->info('App timezone: ' . config('app.timezone'));
        $this->newLine();

        $inProgress = RecurringOrder::whereIn('status', [
            RecurringOrderStatus::GENERATED->value,
            RecurringOrderStatus::IN_PROGRESS->value,
        ])->get(['id', 'branch_id', 'status', 'order_name', 'next_run_at']);

        $dueNow = RecurringOrder::where('status', RecurringOrderStatus::PENDING->value)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->whereNull('paused_at')
            ->get(['id', 'branch_id', 'order_name', 'next_run_at']);

        $this->table(
            ['Branch ID', 'In progress (generated/in_progress)', 'Due now (pending, next_run_at <= now)'],
            [
                [
                    '(all branches)',
                    (string) $inProgress->count(),
                    (string) $dueNow->count(),
                ],
            ]
        );

        if ($inProgress->count() > 0) {
            $this->info('In-progress orders (these should appear in API for their branch):');
            foreach ($inProgress->groupBy('branch_id') as $branchId => $orders) {
                $this->line("  Branch {$branchId}: " . $orders->count() . ' order(s)');
            }
        }

        if ($dueNow->count() > 0) {
            $this->info('Orders due now (would be processed by job):');
            foreach ($dueNow->groupBy('branch_id') as $branchId => $orders) {
                $this->line("  Branch {$branchId}: " . $orders->count() . ' order(s)');
            }
        }

        $this->newLine();
        $this->comment('The in-progress API list is filtered by the logged-in user branch_id. If you see in-progress orders above, ensure you are calling the API as a branch manager whose branch_id matches one of those branches.');

        return self::SUCCESS;
    }
}
