<?php

namespace Modules\Admin\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;

/**
 * Fired after the accountant approves an operation (pending → approved), i.e.
 * it is now waiting on the head of accounts. Intermediate, not terminal: the
 * expense bridge uses it to stamp «موافق عليه من المحاسب» on the mobile record
 * while leaving its status «pending» (meeting 2026-08-14).
 */
class OperationApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(public Operation $operation, public AsabUser $actor) {}
}
