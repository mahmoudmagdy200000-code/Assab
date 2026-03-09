<?php

namespace Modules\RecurringOrder\Console;

use Illuminate\Console\Command;
use Modules\RecurringOrder\Jobs\ProcessRecurringOrdersJob;

class ProcessRecurringOrdersCommand extends Command
{
    protected $signature = 'recurring-orders:process
                            {--sync : Run the job immediately in this process (no queue worker needed). Use for testing or when worker is not running.}';

    protected $description = 'Process due recurring orders (dispatch ProcessRecurringOrdersJob once). Use for testing or manual runs.';

    public function handle(): int
    {
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
}
