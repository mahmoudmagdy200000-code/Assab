<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Facades\DB;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Exceptions\PurchaseOrderException;
use Modules\Purchase\Models\PurchaseOrder;

/**
 * PurchaseOrderDelayService
 *
 * Handles all delay-related operations on purchase orders:
 * approving, confirming, and rejecting delay requests.
 * Extracted from PurchaseOrderService to reduce class size.
 */
class PurchaseOrderDelayService
{
    public function __construct(
        private readonly TimelineService $timelineService
    ) {}

    /**
     * Approve delay report for the entire order.
     */
    public function approveOrderDelay(PurchaseOrder $order): bool
    {
        $this->guardDelayedStatus($order);

        return DB::transaction(function () use ($order) {
            $delayedItems = $this->getDelayedItems($order);

            foreach ($delayedItems as $item) {
                $item->status = OrderItemStatus::DELAYED_APPROVED;
                $item->save();
                $this->timelineService->logItemDelayApproved($order, $item);
            }

            $order->transitionTo(OrderStatus::DELAYED_APPROVED);

            return true;
        });
    }

    /**
     * Approve delay request (Branch Manager accepts) → status: delayed_confirmed.
     */
    public function approveDelayRequest(PurchaseOrder $order): bool
    {
        $this->guardDelayedStatus($order);

        return DB::transaction(function () use ($order) {
            $delayedItems = $this->getDelayedItems($order);

            foreach ($delayedItems as $item) {
                $item->status = OrderItemStatus::DELAYED_CONFIRMED;
                $item->save();
                $this->timelineService->logItemDelayApproved($order, $item);
            }

            $order->transitionTo(OrderStatus::DELAYED_CONFIRMED);

            return true;
        });
    }

    /**
     * Reject delay request (Branch Manager rejects) → status: delayed_canceled.
     */
    public function rejectDelayRequest(PurchaseOrder $order, ?string $reason = null): bool
    {
        $this->guardDelayedStatus($order);

        return DB::transaction(function () use ($order, $reason) {
            $order->cancellation_reason = $reason;
            $order->save();

            $order->load('items');
            $delayedItems = $order->items()->whereIn('status', [
                OrderItemStatus::DELAYED_SUPPLIER,
                OrderItemStatus::DELAYED_BRANCH,
                OrderItemStatus::DELAYED,
            ])->get();

            if (! $delayedItems->isEmpty()) {
                foreach ($delayedItems as $item) {
                    $item->status = OrderItemStatus::DELAYED_CANCELED;
                    $approvalData = $item->approval_data ?? [];
                    $approvalData['cancellation_reason'] = $reason;
                    $item->approval_data = $approvalData;
                    $item->save();
                    $this->timelineService->logItemDelayRejected($order, $item, $reason ?? 'Delay request rejected by branch manager');
                }
            }

            $order->transitionTo(OrderStatus::DELAYED_CANCELED);

            return true;
        });
    }

    /**
     * Reject delay report for the entire order.
     */
    public function rejectOrderDelay(PurchaseOrder $order, ?string $reason = null): bool
    {
        $this->guardDelayedStatus($order);

        return DB::transaction(function () use ($order, $reason) {
            $order->load('items');

            $delayedItems = $order->items()->whereIn('status', [
                OrderItemStatus::DELAYED_SUPPLIER,
                OrderItemStatus::DELAYED_BRANCH,
                OrderItemStatus::DELAYED,
            ])->get();

            if ($delayedItems->isEmpty()) {
                throw PurchaseOrderException::noDelayedItemsFound();
            }

            foreach ($delayedItems as $item) {
                $item->status = OrderItemStatus::DELAYED_CANCELED;
                $approvalData = $item->approval_data ?? [];
                $approvalData['cancellation_reason'] = $reason;
                $item->approval_data = $approvalData;
                $item->save();
                $this->timelineService->logItemDelayRejected($order, $item, $reason ?? 'Delay request rejected by branch manager');
            }

            $order->transitionTo(OrderStatus::DELAYED_CANCELED);

            return true;
        });
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function guardDelayedStatus(PurchaseOrder $order): void
    {
        if ($order->status !== OrderStatus::DELAYED) {
            throw PurchaseOrderException::orderNotInDelayedStatus();
        }
    }

    private function getDelayedItems(PurchaseOrder $order): \Illuminate\Database\Eloquent\Collection
    {
        $order->load('items');

        $items = $order->items()->whereIn('status', [
            OrderItemStatus::DELAYED_SUPPLIER,
            OrderItemStatus::DELAYED_BRANCH,
            OrderItemStatus::DELAYED,
        ])->get();

        if ($items->isEmpty()) {
            throw PurchaseOrderException::noDelayedItemsFound();
        }

        return $items;
    }
}
