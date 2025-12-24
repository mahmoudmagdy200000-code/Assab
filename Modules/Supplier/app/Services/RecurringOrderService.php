<?php

namespace Modules\Supplier\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Supplier\Models\Supplier;

class RecurringOrderService
{
    /**
     * Get recurring orders
     */
    public function getRecurringOrders(Supplier $supplier, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        // Recurring orders are identified by a flag or pattern in order_number
        // This is a simplified implementation - in production, you'd have a recurring_orders table
        $query = PurchaseOrder::where('supplier_id', $supplier->id)
            ->where('order_type', 'direct_supplier')
            ->where('order_number', 'like', 'REC-%')
            ->orderBy('created_at', 'desc');

        return $query->paginate($perPage);
    }

    /**
     * Modify recurring order schedule
     */
    public function modifySchedule(Supplier $supplier, string $orderId, array $data): PurchaseOrder
    {
        $order = PurchaseOrder::where('id', $orderId)
            ->where('supplier_id', $supplier->id)
            ->firstOrFail();

        // Update schedule information (stored in order metadata or separate table)
        $order->update([
            'message' => json_encode([
                'recurring_schedule' => $data['schedule'] ?? null,
                'recurring_frequency' => $data['frequency'] ?? null,
            ]),
        ]);

        return $order->fresh();
    }
}

