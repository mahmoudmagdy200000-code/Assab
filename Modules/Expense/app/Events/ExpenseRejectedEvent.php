<?php

namespace Modules\Expense\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Expense\Models\Expense;

class ExpenseRejectedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Expense $expense,
        public string $rejectedBy,
        public string $reason
    ) {}
}
