<?php

namespace Modules\Purchase\Services;

use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\ReturnOrder;
use Modules\Supplier\Models\Supplier;

class SupplierInfoService
{
    /**
     * Get supplier for a purchase order. Verifies order belongs to branch.
     * Returns null if order not found, branch mismatch, or no supplier.
     */
    public function getSupplierForOrder(string $orderId, string $branchId): ?Supplier
    {
        $order = PurchaseOrder::with('supplier')
            ->where('branch_id', $branchId)
            ->find($orderId);

        if (!$order || !$order->supplier_id) {
            return null;
        }

        return $order->supplier;
    }

    /**
     * Get supplier for a return order. Verifies return belongs to branch.
     * Returns null if return not found, branch mismatch, or no supplier.
     */
    public function getSupplierForReturn(string $returnId, string $branchId): ?Supplier
    {
        $return = ReturnOrder::with('supplier')
            ->where('branch_id', $branchId)
            ->find($returnId);

        if (!$return || !$return->supplier_id) {
            return null;
        }

        return $return->supplier;
    }
}
