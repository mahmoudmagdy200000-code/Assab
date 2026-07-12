<?php

namespace Modules\Admin\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;

/**
 * Fired after an operation is rejected. A shifts operation reopens its shift to
 * `active` in response (T08.6).
 */
class OperationRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(public Operation $operation, public AsabUser $actor, public ?string $reason = null) {}
}
