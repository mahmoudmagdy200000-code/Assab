<?php

namespace Modules\RecurringOrder\Console;

use Illuminate\Console\Command;
use Modules\RecurringOrder\Jobs\ProcessRecurringOrdersJob;

class ProcessRecurringOrdersCommand extends Command
{
    protected $signature = 'recurring-orders:process';

    protected $description = 'Process due recurring orders (dispatch ProcessRecurringOrdersJob once). Use for testing or manual runs.';

    public function handle(): int
    {
        $this->info('Dispatching ProcessRecurringOrdersJob...');
        ProcessRecurringOrdersJob::dispatch();
        $this->info('Job dispatched. It will run asynchronously via the queue.');

        return self::SUCCESS;
    }
}
