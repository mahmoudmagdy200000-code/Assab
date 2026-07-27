<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;
use Modules\Purchase\Events\GoodsReceived;

/**
 * Goods receipt → supplier and receiving branch.
 *
 * A receipt with variances is escalated: the supplier gets the variance type at
 * medium priority rather than the routine confirmation.
 */
class GoodsReceivedNotificationListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly NotificationServiceInterface $notificationService
    ) {}

    public function handle(GoodsReceived $event): void
    {
        $receipt = $event->receipt;
        $receipt->loadMissing('purchaseOrder.supplier');

        $order = $receipt->purchaseOrder;

        $payload = [
            'receipt_id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'order_id' => $order?->id,
            'order_number' => $order?->order_number,
            'branch_id' => $receipt->branch_id,
            'has_variances' => $event->hasVariances,
        ];

        $supplier = $order?->supplier;

        if ($supplier !== null) {
            $this->notificationService->send(
                $supplier,
                $event->hasVariances
                    ? NotificationType::ORDER_VARIANCE_DETECTED
                    : NotificationType::GOODS_RECEIVED,
                $payload,
                $event->hasVariances ? NotificationPriority::MEDIUM : null
            );
        }

        if ($receipt->branch_id) {
            $this->notificationService->sendToRole(
                'branch_manager',
                NotificationType::GOODS_RECEIVED,
                $payload,
                null,
                $receipt->branch_id
            );
        }
    }
}
