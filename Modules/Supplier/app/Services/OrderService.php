<?php

namespace Modules\Supplier\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Supplier\Models\Supplier;

class OrderService
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {}

    /**
     * Get pending orders for supplier with filters
     * Returns orders with pending statuses (PENDING, PENDING_CONFIRMATION, PENDING_APPROVAL, PARTIAL_CONFIRMATION, DELAYED)
     */
    public function getPendingOrders(Supplier $supplier, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PurchaseOrder::with([
            'items:id,purchase_order_id,item_id,item_name,item_logo,quantity_ordered,unit_of_measurement,unit_price,total_price,quality_ordered,quality_received',
            'branch:id,name,location',
            'requestedBy:id,name,email,phone',
        ])
            ->where('supplier_id', $supplier->id)
            ->where('order_type', 'direct_supplier')
            ->whereIn('status', [
                OrderStatus::PENDING,
                OrderStatus::PENDING_CONFIRMATION,
                OrderStatus::PENDING_APPROVAL,
                OrderStatus::PARTIAL_CONFIRMATION,
                OrderStatus::DELAYED,
            ])
            ->orderBy('created_at', 'desc');

        // Filter by status
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filter by date range
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        // Search by order number
        if (!empty($filters['search'])) {
            $query->where('order_number', 'like', '%' . $filters['search'] . '%');
        }

        return $query->paginate($perPage);
    }

    /**
     * Get orders for supplier with filters
     */
    public function getOrders(Supplier $supplier, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {


        $query = PurchaseOrder::with([
            'items:id,purchase_order_id,item_id,item_name,item_logo,quantity_ordered,unit_of_measurement,unit_price,total_price,quality_ordered,quality_received',
            'branch:id,name,location',
            'requestedBy:id,name,email,phone',
        ])
            ->where('supplier_id', $supplier->id)
            ->where('order_type', 'direct_supplier')
            ->orderBy('created_at', 'desc');

        // dont get the draft orders
        $query->where('status', '!=', OrderStatus::DRAFT);

        // Filter by status
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filter by date range
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        // Search by order number
        if (!empty($filters['search'])) {
            $query->where('order_number', 'like', '%' . $filters['search'] . '%');
        }

        return $query->paginate($perPage);
    }

    /**
     * Get order details
     */
    public function getOrderDetails(string $orderId, Supplier $supplier): ?PurchaseOrder
    {
        return PurchaseOrder::with([
            'items',
            'branch',
            'requestedBy',
            'timelines',
        ])
            ->where('id', $orderId)
            ->where('supplier_id', $supplier->id)
            ->first();
    }

    /**
     * Accept order
     */
    public function acceptOrder(PurchaseOrder $order, Supplier $supplier, array $data = []): PurchaseOrder
    {
        if ($order->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this order');
        }

        if (!in_array($order->status, [OrderStatus::PENDING, OrderStatus::PENDING_CONFIRMATION])) {
            throw new \Exception('Order cannot be accepted in current status');
        }

        return DB::transaction(function () use ($order, $data) {
            $order->update([
                'status' => OrderStatus::CONFIRMED,
                'confirmed_at' => now(),
                'expected_delivery_at' => $data['expected_delivery_at'] ?? null,
                'message' => $data['message'] ?? null,
            ]);

            // Mark all items as confirmed when order is fully accepted
            $order->items()->update([
                'status' => 'confirmed',
                'quantity_confirmed' => DB::raw('quantity_ordered'),
            ]);

            // Recalculate totals for all items
            $order->items->each->calculateTotalPrice();

            // Send notification to branch manager
            $this->notificationService->notifyOrderAccepted($order);

            return $order->fresh();
        });
    }

    /**
     * Reject order
     */
    public function rejectOrder(PurchaseOrder $order, Supplier $supplier, array $data): PurchaseOrder
    {
        if ($order->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this order');
        }

        if (!in_array($order->status, [OrderStatus::PENDING, OrderStatus::PENDING_CONFIRMATION])) {
            throw new \Exception('Order cannot be rejected in current status');
        }

        return DB::transaction(function () use ($order, $data) {
            $order->update([
                'status' => OrderStatus::REJECTED,
                'rejected_at' => now(),
                'rejection_reason' => $data['reason'],
                'message' => $data['explanation'] ?? null,
            ]);

            // Mark all items as rejected when order is rejected
            $order->items()->update([
                'status' => 'rejected',
                'quantity_confirmed' => 0,
            ]);

            // Send notification to branch manager
            $this->notificationService->notifyOrderRejected($order);

            return $order->fresh();
        });
    }

    /**
     * Request order modification
     */
    public function requestModification(PurchaseOrder $order, Supplier $supplier, array $data): PurchaseOrder
    {
        if ($order->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this order');
        }

        return DB::transaction(function () use ($order, $data) {
            // Create modification request (could be stored in a separate table or as a message)
            // For now, we'll update the order with modification request
            $order->update([
                'message' => $data['modification_request'],
                'status' => OrderStatus::PENDING_CONFIRMATION, // Awaiting branch approval
            ]);

            // Send notification to branch manager
            $this->notificationService->notifyOrderModificationRequested($order, $data);

            return $order->fresh();
        });
    }

    /**
     * Get order dashboard statistics
     */
    public function getDashboardStats(Supplier $supplier): array
    {
        $orders = PurchaseOrder::where('supplier_id', $supplier->id)
            ->where('order_type', 'direct_supplier');

        return [
            'new_orders' => (clone $orders)->where('status', OrderStatus::PENDING)->count(),
            'in_progress' => (clone $orders)->whereIn('status', [
                OrderStatus::CONFIRMED,
                OrderStatus::PREPARING,
                OrderStatus::ON_THE_WAY,
            ])->count(),
            'completed' => (clone $orders)->where('status', OrderStatus::DELIVERED)->count(),
            'total_this_week' => (clone $orders)->whereBetween('created_at', [
                now()->startOfWeek(),
                now()->endOfWeek(),
            ])->count(),
            'total_this_month' => (clone $orders)->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
        ];
    }
}
