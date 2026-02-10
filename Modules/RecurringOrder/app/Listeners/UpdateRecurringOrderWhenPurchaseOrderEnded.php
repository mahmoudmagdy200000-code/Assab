<?php

namespace Modules\RecurringOrder\Listeners;

use Modules\Purchase\Enums\OrderStatus as PurchaseOrderStatus;
use Modules\Purchase\Events\OrderStatusChanged;
use Modules\RecurringOrder\Enums\RecurringOrderStatus;
use Modules\RecurringOrder\Services\RecurringOrderService;

class UpdateRecurringOrderWhenPurchaseOrderEnded
{
    public function __construct(
        private readonly RecurringOrderService $recurringOrderService
    ) {}

    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;
        if (!$order->recurring_order_id) {
            return;
        }

        $recurring = \Modules\RecurringOrder\Models\RecurringOrder::find($order->recurring_order_id);
        if (!$recurring) {
            return;
        }

        if ($event->newStatus === PurchaseOrderStatus::CONFIRMED) {
            if ($recurring->status === RecurringOrderStatus::GENERATED) {
                $recurring->update(['status' => RecurringOrderStatus::IN_PROGRESS]);
            }
            return;
        }

        $terminalStatuses = [
            PurchaseOrderStatus::CLOSED,
            PurchaseOrderStatus::CANCELED,
            PurchaseOrderStatus::CANCELLED_BY_BRANCH,
            PurchaseOrderStatus::CANCELLED_BY_SUPPLIER,
            PurchaseOrderStatus::REJECTED,
        ];

        if (!in_array($event->newStatus, $terminalStatuses)) {
            return;
        }

        if (!in_array($recurring->status, [RecurringOrderStatus::GENERATED, RecurringOrderStatus::IN_PROGRESS])) {
            return;
        }

        $nextRun = $this->recurringOrderService->computeNextRunAtFromModel($recurring);
        $recurring->update([
            'status' => RecurringOrderStatus::PENDING,
            'next_run_at' => $nextRun,
        ]);
    }
}
