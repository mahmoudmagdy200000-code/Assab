<?php

namespace Modules\Admin\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;

/**
 * Fired when the head of accounts sends an approved operation back to the
 * accountant's queue (approved → pending) — «إرجاع للمراجعة», which is also
 * what a head REJECTION of an approved record does (meeting 2026-08-14: «ولو
 * رفضها ترجع pending تاني علشان المحاسب يراجعها»).
 */
class OperationReturnedForReview
{
    use Dispatchable, SerializesModels;

    public function __construct(public Operation $operation, public AsabUser $actor, public ?string $note = null) {}
}
