<?php

namespace Modules\Admin\Listeners;

use Modules\Admin\Services\PurchaseOrderBridgeService;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Events\OrderCreated;
use Modules\Purchase\Events\OrderStatusChanged;

/**
 * Forward leg of the purchase bridge (meeting 2026-07-30): a mobile purchase
 * order surfaces in the accountant's inbox as a PUR- operation, and later
 * status changes (delivered/closed) re-sync so rcvQty and the 3-way match
 * reflect the goods actually received.
 */
class BridgeLegacyPurchaseOrder
{
    /** Statuses whose transition warrants a (re-)sync into the ASAB world. */
    private const SYNCABLE = [
        OrderStatus::PENDING,
        OrderStatus::EMERGENCY,
        OrderStatus::CONFIRMED,
        OrderStatus::DELIVERED,
        OrderStatus::CLOSED,
    ];

    public function __construct(private readonly PurchaseOrderBridgeService $bridge) {}

    public function handleCreated(OrderCreated $event): void
    {
        if ($event->order->status !== OrderStatus::DRAFT) {
            $this->bridge->sync($event->order);
        }
    }

    public function handleStatusChanged(OrderStatusChanged $event): void
    {
        if (in_array($event->newStatus, self::SYNCABLE, true)) {
            $this->bridge->sync($event->order->fresh(['items']));
        }
    }
}
