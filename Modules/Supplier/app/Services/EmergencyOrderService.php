<?php

namespace Modules\Supplier\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Purchase\Enums\Priority;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Supplier\Models\Supplier;

class EmergencyOrderService
{
    /**
     * Get emergency orders (high priority orders)
     */
    public function getEmergencyOrders(Supplier $supplier, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PurchaseOrder::where('supplier_id', $supplier->id)
            ->where('order_type', 'direct_supplier')
            ->where('priority', Priority::URGENT)
            ->orderBy('created_at', 'desc');

        return $query->paginate($perPage);
    }

    /**
     * Respond to emergency order
     */
    public function respondToEmergency(Supplier $supplier, string $orderId, array $data): PurchaseOrder
    {
        $order = PurchaseOrder::where('id', $orderId)
            ->where('supplier_id', $supplier->id)
            ->where('priority', Priority::URGENT)
            ->firstOrFail();

        // Fast-track response - immediately confirm if possible
        if ($data['can_fulfill'] ?? false) {
            $order->update([
                'status' => \Modules\Purchase\Enums\OrderStatus::CONFIRMED,
                'confirmed_at' => now(),
                'message' => $data['response_message'] ?? 'Emergency order confirmed - fast-track processing',
            ]);
        }

        return $order->fresh();
    }
}
