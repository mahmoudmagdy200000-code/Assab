<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Enums\NotificationType;
use Modules\Purchase\Events\ReturnOrderApproved;
use Modules\Purchase\Events\ReturnOrderSubmitted;

/**
 * Return orders → the counterparty.
 *
 * Submitted goes to the supplier (they owe a response); approved goes back to
 * the branch manager who raised it.
 */
class ReturnOrderNotificationListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly NotificationServiceInterface $notificationService
    ) {}

    public function handle(ReturnOrderSubmitted|ReturnOrderApproved $event): void
    {
        $returnOrder = $event->returnOrder;
        $returnOrder->loadMissing(['supplier', 'createdBy']);

        $payload = [
            'return_order_id' => $returnOrder->id,
            'return_number' => $returnOrder->return_number,
            'order_id' => $returnOrder->purchase_order_id,
            'branch_id' => $returnOrder->branch_id,
        ];

        if ($event instanceof ReturnOrderSubmitted) {
            if ($returnOrder->supplier !== null) {
                $this->notificationService->send(
                    $returnOrder->supplier,
                    NotificationType::RETURN_ORDER_SUBMITTED,
                    $payload
                );
            }

            return;
        }

        if ($returnOrder->createdBy !== null) {
            $this->notificationService->send(
                $returnOrder->createdBy,
                NotificationType::RETURN_ORDER_APPROVED,
                $payload
            );
        }
    }
}
