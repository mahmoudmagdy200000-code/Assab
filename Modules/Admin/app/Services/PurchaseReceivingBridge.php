<?php

namespace Modules\Admin\Services;

use Modules\Admin\Models\Operation;
use Modules\Admin\Support\TenantContext;

/**
 * T06.3 — hydrate the received-quantity leg of the 3-way match from the legacy
 * mobile world. Real `quantity_received` lives only in `purchase_order_items`
 * (fallback `goods_receipt_items`); ASAB operations never captured it.
 *
 * A purchases op links a legacy PO by `source_module='purchase'` + `source_id`,
 * or by `payload.legacyOrderId`. The link is tenant-guarded: the legacy PO's
 * branch must be inside the caller's legacy branch scope, so one company can
 * never read another's receipts. Ops with no legacy link keep `rcvQty=null`
 * (the match then reads `pending`, computed from ordered qty alone).
 */
class PurchaseReceivingBridge
{
    public function __construct(private readonly TenantBranchResolver $branches) {}

    /**
     * rowId → received quantity for a purchases op, or null when it has no
     * (in-scope) legacy link.
     *
     * @return array<string, float>|null
     */
    public function receivedByRow(Operation $op): ?array
    {
        $legacyId = $op->source_module === 'purchase' ? $op->source_id : ($op->payload['legacyOrderId'] ?? null);
        if ($legacyId === null || ! class_exists(\Modules\Purchase\Models\PurchaseOrder::class)) {
            return null;
        }

        $order = \Modules\Purchase\Models\PurchaseOrder::query()
            ->with('items:id,purchase_order_id,item_id,quantity_received')
            ->find($legacyId, ['id', 'branch_id']);
        if ($order === null || ! $this->branchInScope($order->branch_id)) {
            return null;
        }

        $received = [];
        foreach ($order->items as $item) {
            if ($item->quantity_received === null) {
                continue;
            }
            // The canonical payload's rowId IS the legacy order-item id, so key
            // by both: matching on item_id alone left every bridged line
            // «بانتظار الاستلام» because no rowId ever equalled an item_id.
            $received[(string) $item->id] = (float) $item->quantity_received;
            if ($item->item_id !== null) {
                $received[(string) $item->item_id] = (float) $item->quantity_received;
            }
        }

        return $received;
    }

    /**
     * Batch counterpart for list surfaces: operationId → (rowId → received qty).
     * Two queries for the whole page instead of two per operation.
     *
     * @param  Operation[]  $ops
     * @return array<string, array<string, float>>
     */
    public function receivedByRowForMany(array $ops): array
    {
        if ($ops === [] || ! class_exists(\Modules\Purchase\Models\PurchaseOrder::class)) {
            return [];
        }

        $legacyByOp = [];
        foreach ($ops as $op) {
            $legacyId = $op->source_module === 'purchase' ? $op->source_id : ($op->payload['legacyOrderId'] ?? null);
            if ($legacyId !== null) {
                $legacyByOp[$op->id] = (string) $legacyId;
            }
        }
        if ($legacyByOp === []) {
            return [];
        }

        $orders = \Modules\Purchase\Models\PurchaseOrder::query()
            ->with('items:id,purchase_order_id,item_id,quantity_received')
            ->whereIn('id', array_values(array_unique($legacyByOp)))
            ->get(['id', 'branch_id'])
            ->keyBy('id');

        $out = [];
        foreach ($legacyByOp as $opId => $legacyId) {
            $order = $orders->get($legacyId);
            if ($order === null || ! $this->branchInScope($order->branch_id)) {
                continue;
            }

            $received = [];
            foreach ($order->items as $item) {
                if ($item->quantity_received === null) {
                    continue;
                }
                // Keyed BOTH ways: the canonical payload's rowId is the legacy
                // order-item id, older payloads key their rows by item_id.
                $received[(string) $item->id] = (float) $item->quantity_received;
                if ($item->item_id !== null) {
                    $received[(string) $item->item_id] = (float) $item->quantity_received;
                }
            }
            if ($received !== []) {
                $out[$opId] = $received;
            }
        }

        return $out;
    }

    /** The legacy PO's branch must be inside the caller's legacy branch scope. */
    private function branchInScope(?string $branchId): bool
    {
        $allowed = $this->branches->legacyBranchIds(app(TenantContext::class));

        // null = platform admin (unrestricted). A scoped caller with a null
        // branch on the legacy order fails closed.
        if ($allowed === null) {
            return true;
        }

        return $branchId !== null && in_array($branchId, $allowed, true);
    }
}
