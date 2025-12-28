<?php

namespace Modules\Supplier\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
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
                OrderStatus::CONFIRMED,
                OrderStatus::DELAYED,
                // Deprecated (for backward compatibility)
                OrderStatus::PENDING_CONFIRMATION,
                OrderStatus::PENDING_APPROVAL,
                OrderStatus::PARTIAL_CONFIRMATION,
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

        if ($order->status !== OrderStatus::PENDING) {
            throw new \Exception('Order cannot be accepted in current status');
        }

        return DB::transaction(function () use ($order, $data) {
            // Mark all items as confirmed when order is fully accepted
            $order->items()
                ->where('status', OrderItemStatus::PENDING)
                ->get()
                ->each(function ($item) {
                    $item->confirm();
                });

            // Update order with expected delivery if provided
            if (isset($data['expected_delivery_at'])) {
                $order->expected_delivery_at = $data['expected_delivery_at'];
            }
            if (isset($data['message'])) {
                $order->message = $data['message'];
            }
            $order->save();

            // Check and transition to CONFIRMED (auto-check)
            $order->checkAndTransitionToConfirmed();

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

        if ($order->status !== OrderStatus::PENDING) {
            throw new \Exception('Order cannot be rejected in current status');
        }

        return DB::transaction(function () use ($order, $data) {
            // Mark all items as rejected when order is rejected
            $order->items()
                ->where('status', OrderItemStatus::PENDING)
                ->get()
                ->each(function ($item) {
                    $item->status = OrderItemStatus::REJECTED;
                    $item->quantity_confirmed = 0;
                    $item->save();
                });

            $order->update([
                'status' => OrderStatus::REJECTED,
                'rejected_at' => now(),
                'rejection_reason' => $data['reason'],
                'message' => $data['explanation'] ?? null,
            ]);

            // Send notification to branch manager
            $this->notificationService->notifyOrderRejected($order);

            return $order->fresh();
        });
    }

    /**
     * Request order modification (deprecated - use item-level requests instead)
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
            ]);

            // Send notification to branch manager
            $this->notificationService->notifyOrderModificationRequested($order, $data);

            return $order->fresh();
        });
    }

    /**
     * Request partial approval for specific items
     */
    public function requestPartialApproval(PurchaseOrder $order, Supplier $supplier, array $itemRequests): PurchaseOrder
    {
        if ($order->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this order');
        }

        if ($order->status !== OrderStatus::PENDING) {
            throw new \Exception('Order must be in pending status to request partial approval');
        }

        return DB::transaction(function () use ($order, $itemRequests) {
            foreach ($itemRequests as $request) {
                $item = $order->items()->where('item_id', $request['item_id'])->first();
                
                if (!$item) {
                    continue;
                }

                if ($item->status !== OrderItemStatus::PENDING) {
                    continue;
                }

                $item->requestPartialApproval(
                    $request['quantity'],
                    $request['note'] ?? null
                );
            }

            // Send notification to branch manager
            $this->notificationService->notifyOrderModificationRequested($order, [
                'type' => 'partial_approval',
                'items' => $itemRequests,
            ]);

            return $order->fresh();
        });
    }

    /**
     * Request delivery time change
     */
    public function requestTimeChange(PurchaseOrder $order, Supplier $supplier, string $itemId, string $newDeliveryTime, string $reason, ?string $note = null): PurchaseOrder
    {
        if ($order->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this order');
        }

        if ($order->status !== OrderStatus::PENDING) {
            throw new \Exception('Order must be in pending status to request time change');
        }

        return DB::transaction(function () use ($order, $itemId, $newDeliveryTime, $reason, $note) {
            $item = $order->items()->where('item_id', $itemId)->first();

            if (!$item) {
                throw new \Exception('Item not found in order');
            }

            if ($item->status !== OrderItemStatus::PENDING) {
                throw new \Exception('Item must be in pending status to request time change');
            }

            $item->requestTimeChange($newDeliveryTime, $reason, $note);

            // Send notification to branch manager
            $this->notificationService->notifyOrderModificationRequested($order, [
                'type' => 'time_change',
                'item_id' => $itemId,
                'new_delivery_time' => $newDeliveryTime,
                'reason' => $reason,
            ]);

            return $order->fresh();
        });
    }

    /**
     * Request alternative product
     */
    public function requestAlternative(PurchaseOrder $order, Supplier $supplier, string $itemId, string $alternativeItemId, string $alternativeItemName, ?float $price = null, string $reason, ?string $note = null): PurchaseOrder
    {
        if ($order->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this order');
        }

        if ($order->status !== OrderStatus::PENDING) {
            throw new \Exception('Order must be in pending status to request alternative');
        }

        return DB::transaction(function () use ($order, $itemId, $alternativeItemId, $alternativeItemName, $price, $reason, $note) {
            $item = $order->items()->where('item_id', $itemId)->first();

            if (!$item) {
                throw new \Exception('Item not found in order');
            }

            if ($item->status !== OrderItemStatus::PENDING) {
                throw new \Exception('Item must be in pending status to request alternative');
            }

            $item->requestAlternative(
                $alternativeItemId,
                $alternativeItemName,
                $price ?? $item->unit_price,
                $reason,
                $note
            );

            // Send notification to branch manager
            $this->notificationService->notifyOrderModificationRequested($order, [
                'type' => 'alternative',
                'item_id' => $itemId,
                'alternative_item_id' => $alternativeItemId,
                'alternative_item_name' => $alternativeItemName,
                'reason' => $reason,
            ]);

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
