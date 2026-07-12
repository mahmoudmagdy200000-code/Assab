<?php

namespace Modules\Admin\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Admin\Events\OperationFinalApproved;
use Modules\Admin\Services\ErpBatchService;

/**
 * SRS §14.3 ERP-1 — a final-approved operation becomes "ready for ERP". This
 * seeds/updates the (day × module) ready batch so the head/admin export screens
 * can list what is waiting before anyone posts. Fail-safe: an ERP-batch hiccup
 * must never fail the final-approval it hangs off (that already committed).
 */
class SyncErpReadyBatch
{
    public function __construct(private readonly ErpBatchService $erp) {}

    public function handle(OperationFinalApproved $event): void
    {
        try {
            $this->erp->addToReadyBatch($event->operation, $event->actor);
        } catch (\Throwable $e) {
            Log::warning('SyncErpReadyBatch failed', ['op' => $event->operation->id, 'error' => $e->getMessage()]);
        }
    }
}
