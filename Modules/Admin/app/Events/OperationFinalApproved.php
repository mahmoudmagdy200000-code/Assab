<?php

namespace Modules\Admin\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;

/**
 * Fired after an operation is final-approved (HEAD-2.5). Module-specific
 * post-processing (e.g. shift cash-gap ledger posting) hangs off this rather
 * than coupling OperationService to every module's service.
 */
class OperationFinalApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(public Operation $operation, public AsabUser $actor) {}
}
