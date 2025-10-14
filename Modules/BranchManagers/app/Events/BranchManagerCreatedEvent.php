<?php

namespace Modules\BranchManagers\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\BranchManagers\Models\BranchManager;

class BranchManagerCreatedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public BranchManager $manager,
        public string $defaultPassword
    ) {}
}
