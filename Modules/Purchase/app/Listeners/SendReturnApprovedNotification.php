<?php

namespace Modules\Purchase\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\Purchase\Events\ReturnOrderApproved;

class SendReturnApprovedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * When supplier approves a return, notify branch / requestor.
     */
    public function handle(ReturnOrderApproved $event): void
    {
        $returnOrder = $event->returnOrder;

        $returnOrder->loadMissing(['branch', 'purchaseOrder', 'supplier']);

        Log::info('Return order approved by supplier', [
            'return_id' => $returnOrder->id,
            'return_number' => $returnOrder->return_number,
            'branch_id' => $returnOrder->branch_id,
            'supplier_id' => $returnOrder->supplier_id,
        ]);

        // Integrate with your notification system (in-app, email, etc.)
        $this->sendInAppNotification($returnOrder);
    }

    private function sendInAppNotification($returnOrder): void
    {
        // TODO: Integrate with in-app notification (e.g. notify branch manager)
        Log::info("Sending in-app notification: Return {$returnOrder->return_number} approved by supplier");
    }
}
