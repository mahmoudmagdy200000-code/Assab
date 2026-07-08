<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Facades\DB;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Exceptions\PurchaseOrderException;
use Modules\Purchase\Models\PurchaseOrder;

/**
 * Purchasing-manager decisions on mobile purchase orders, taken from the ASAB
 * dashboard. This is the single write-path bridge between the dashboard
 * procurement role and the Purchase domain: decisions mutate purchase_orders
 * directly (no parallel Operation copy), reusing the same item-confirmation
 * and status-transition rules the supplier flow uses, so mobile clients see
 * the outcome immediately.
 */
class ProcurementDecisionService
{
    public const DECISION_SOURCE = 'dashboard_procurement';

    /** Statuses a purchasing manager may still decide on. */
    private const DECIDABLE = [OrderStatus::PENDING, OrderStatus::EMERGENCY, OrderStatus::VARIANCE];

    /**
     * Approve the whole order: confirm every pending line at its ordered
     * quantity, then let the order auto-transition to CONFIRMED. Throws (and
     * rolls back) if the order does not actually leave its decidable status —
     * e.g. lines are stuck in a needs_approval negotiation.
     */
    public function approve(PurchaseOrder $order, string $asabUserId): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $asabUserId) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertDecidable($order);

            $order->items()->where('status', OrderItemStatus::PENDING)->get()
                ->each(fn ($item) => $item->confirm());

            $order->checkAndTransitionToConfirmed();
            $this->assertDecisionApplied($order);
            $this->stampDecision($order, $asabUserId);

            return $order->fresh(['items']);
        });
    }

    /**
     * Line-level partial approval. $approvedQuantities maps purchase_order_item
     * id => approved quantity; pending lines mapped to 0 (or omitted) are
     * rejected. Approved quantities are capped at the ordered quantity. Ids
     * not belonging to this order are a caller error, and a request approving
     * zero lines is a full rejection — never a zero-quantity "confirmed" order.
     */
    public function approvePartial(PurchaseOrder $order, array $approvedQuantities, string $asabUserId, ?string $note = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $approvedQuantities, $asabUserId, $note) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertDecidable($order);

            $pending = $order->items()->where('status', OrderItemStatus::PENDING)->get();
            if ($pending->isEmpty()) {
                throw PurchaseOrderException::itemNotFound();
            }

            $unknown = array_diff(array_keys($approvedQuantities), $pending->pluck('id')->all());
            if ($unknown !== []) {
                throw PurchaseOrderException::itemsNotInOrder(array_values($unknown));
            }

            if ($pending->every(fn ($item) => (float) ($approvedQuantities[$item->id] ?? 0) <= 0)) {
                return $this->reject($order, $asabUserId, $note ?? 'rejected_by_purchasing_manager');
            }

            foreach ($pending as $item) {
                $quantity = (float) ($approvedQuantities[$item->id] ?? 0);

                if ($quantity > 0) {
                    $item->confirm(min($quantity, (float) $item->quantity_ordered));

                    continue;
                }

                $approvalData = $item->approval_data ?? [];
                $approvalData['rejection_reason'] = $note ?? 'rejected_by_purchasing_manager';
                $item->update([
                    'status' => OrderItemStatus::REJECTED->value,
                    'quantity_confirmed' => 0,
                    'approval_data' => $approvalData,
                ]);
            }

            $order->checkAndTransitionToConfirmed();
            $this->assertDecisionApplied($order);
            $this->stampDecision($order, $asabUserId);

            return $order->fresh(['items']);
        });
    }

    /** Reject the whole order with a mandatory reason. */
    public function reject(PurchaseOrder $order, string $asabUserId, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $asabUserId, $reason) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertDecidable($order);

            if (! $order->reject($reason)) {
                throw PurchaseOrderException::decisionNotApplied($order->status->value);
            }
            $this->stampDecision($order, $asabUserId);

            return $order->fresh(['items']);
        });
    }

    private function stampDecision(PurchaseOrder $order, string $asabUserId): void
    {
        $order->update([
            'decided_by_asab_user_id' => $asabUserId,
            'decided_at' => now(),
            'decision_source' => self::DECISION_SOURCE,
        ]);
    }

    private function assertDecidable(PurchaseOrder $order): void
    {
        if (! in_array($order->status, self::DECIDABLE, true)) {
            throw PurchaseOrderException::notDecidable($order->status->value);
        }
    }

    /**
     * The decision must have moved the order out of its decidable status;
     * otherwise roll back rather than commit a half-decided order (e.g. lines
     * still in needs_approval, or a disallowed enum transition).
     */
    private function assertDecisionApplied(PurchaseOrder $order): void
    {
        if (in_array($order->refresh()->status, self::DECIDABLE, true)) {
            throw PurchaseOrderException::decisionNotApplied($order->status->value);
        }
    }
}
