<?php

namespace Modules\Supplier\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
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
     *
     * Supported status filters:
     * - pending: OrderStatus::PENDING
     * - partial_confirmed: OrderStatus::PARTIAL_CONFIRMED
     * - confirmed: OrderStatus::CONFIRMED
     * - delayed: OrderStatus::DELAYED
     * - alternative_product: Orders with items that have is_alternative = true
     * - rejected: OrderStatus::REJECTED
     */
    public function getPendingOrders(Supplier $supplier, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PurchaseOrder::with([
            'items:id,purchase_order_id,item_id,item_name,item_logo,quantity_ordered,quantity_confirmed,unit_of_measurement,unit_price,total_price,quality_ordered,quality_received,status,approval_type,approval_data,is_alternative',
            'branch:id,name,location',
            'requestedBy:id,name,email,phone',
        ])
            ->where('supplier_id', $supplier->id)
            ->where('order_type', 'direct_supplier')
            ->whereIn('status', [
                OrderStatus::PENDING,
                OrderStatus::CONFIRMED,
                OrderStatus::DELAYED,
                OrderStatus::REJECTED,
                // Deprecated (for backward compatibility)
                OrderStatus::PENDING_CONFIRMATION,
                OrderStatus::PENDING_APPROVAL,
                OrderStatus::PARTIAL_CONFIRMATION,
                OrderStatus::PARTIAL_CONFIRMED,
            ])
            ->orderBy('created_at', 'desc');

        // Filter by status
        if (!empty($filters['status'])) {
            $statusFilter = $this->mapStatusFilter($filters['status']);

            if ($statusFilter === 'alternative_product') {
                // Filter orders that have items with is_alternative = true
                $query->whereHas('items', function ($q) {
                    $q->where('is_alternative', true);
                });
            } else {
                // Filter by order status
                $query->where('status', $statusFilter);
            }
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
     * Map status filter string to OrderStatus enum value
     *
     * @param string $status
     * @return string|OrderStatus
     */
    private function mapStatusFilter(string $status): string|OrderStatus
    {
        return match (strtolower($status)) {
            'pending' => OrderStatus::PENDING,
            'partial_confirmed' => OrderStatus::PARTIAL_CONFIRMED,
            'confirmed' => OrderStatus::CONFIRMED,
            'delayed' => OrderStatus::DELAYED,
            'alternative_product' => 'alternative_product', // Special case - handled separately
            'rejected' => OrderStatus::REJECTED,
            default => $status, // Return as-is if not recognized (for backward compatibility)
        };
    }

    /**
     * Get orders for supplier with filters
     */
    public function getOrders(Supplier $supplier, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {


        $query = PurchaseOrder::with([
            'items:id,purchase_order_id,item_id,item_name,item_logo,quantity_ordered,quantity_confirmed,unit_of_measurement,unit_price,total_price,quality_ordered,quality_received,status,approval_type,approval_data',
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
                'rejection_reason' => $data['reason'] ?? null,
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
                    $request['note'] ?? null,
                    true // isSupplierRequest = true (supplier is requesting)
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

        return DB::transaction(function () use ($order, $itemId, $newDeliveryTime, $reason, $note) {
            // Refresh order to get latest status (in case it changed after item cancellations)
            $order->refresh();

            // Get the item first to check its status
            $item = $order->items()->where('item_id', $itemId)->first();

            if (!$item) {
                throw new \Exception('Item not found in order');
            }

            // Item must be in pending status to request time change
            // Cannot request time change for cancelled or already confirmed items
            if ($item->status !== OrderItemStatus::PENDING) {
                throw new \Exception('Item must be in pending status to request time change');
            }

            // Allow time change request if:
            // 1. Order is in pending status, OR
            // 2. Order is in partial confirmation status, OR
            // 3. Order status is cancelled but there are still pending items (edge case)
            // The key check is that the item itself is pending, which we already validated above
            $allowedStatuses = [
                OrderStatus::PENDING,
                OrderStatus::PARTIAL_CONFIRMATION,
                OrderStatus::CANCELLED_BY_BRANCH, // Allow if item is still pending
                OrderStatus::CANCELLED_BY_SUPPLIER, // Allow if item is still pending
                OrderStatus::CANCELED, // Allow if item is still pending
            ];

            if (!in_array($order->status, $allowedStatuses)) {
                throw new \Exception('Order must be in pending or partial confirmation status to request time change');
            }

            $item->requestTimeChange($newDeliveryTime, $reason, $note, true); // isSupplierRequest = true (supplier is requesting)

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
    public function requestAlternative(PurchaseOrder $order, Supplier $supplier, string $itemId, string $alternativeItemId, string $reason, ?string $note = null): PurchaseOrder
    {
        if ($order->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this order');
        }

        if ($order->status !== OrderStatus::PENDING) {
            throw new \Exception('Order must be in pending status to request alternative');
        }

        return DB::transaction(function () use ($order, $itemId, $alternativeItemId, $reason, $note) {
            $item = $order->items()->where('item_id', $itemId)->first();

            if (!$item) {
                throw new \Exception('Item not found in order');
            }

            if ($item->status !== OrderItemStatus::PENDING) {
                throw new \Exception('Item must be in pending status to request alternative');
            }

            // Get alternative item details
            $alternativeItem = Item::find($alternativeItemId);
            if (!$alternativeItem) {
                throw new \Exception('Alternative item not found');
            }

            // Get price from BranchItem or use original item price as default
            $branchItem = BranchItem::where('branch_id', $order->branch_id)
                ->where('item_id', $alternativeItemId)
                ->first();

            $alternativePrice = $branchItem?->price ?? $item->unit_price;

            $item->requestAlternative(
                $alternativeItemId,
                $alternativeItem->name,
                $alternativePrice ? (float) $alternativePrice : null,
                $reason,
                $note,
                true // isSupplierRequest = true (supplier is requesting)
            );

            // Send notification to branch manager
            $this->notificationService->notifyOrderModificationRequested($order, [
                'type' => 'alternative',
                'item_id' => $itemId,
                'alternative_item_id' => $alternativeItemId,
                'reason' => $reason,
            ]);

            return $order->fresh();
        });
    }

    /**
     * Confirm specific item in order
     */
    public function confirmItem(PurchaseOrder $order, Supplier $supplier, string $itemId, ?float $quantity = null): PurchaseOrder
    {
        if ($order->supplier_id !== $supplier->id) {
            throw new \InvalidArgumentException('Unauthorized access to this order');
        }

        if (!in_array($order->status, [OrderStatus::PENDING, OrderStatus::PARTIAL_CONFIRMATION])) {
            throw new \InvalidArgumentException('Order must be in pending or partial confirmation status to confirm item');
        }

        return DB::transaction(function () use ($order, $itemId, $quantity) {
            $item = $order->items()->where('item_id', $itemId)->first();

            if (!$item) {
                throw new \InvalidArgumentException('Item not found in order');
            }

            if ($item->status !== OrderItemStatus::PENDING) {
                throw new \InvalidArgumentException('Item must be in pending status to confirm');
            }

            // Validate quantity if provided
            if ($quantity !== null) {
                if ($quantity <= 0) {
                    throw new \InvalidArgumentException('Quantity must be greater than zero');
                }
                if ($quantity > $item->quantity_ordered) {
                    throw new \InvalidArgumentException('Confirmed quantity cannot exceed ordered quantity');
                }
            }

            // Confirm the item
            $item->confirm($quantity);

            // Don't change order status - keep it pending until all items are decided
            // The order status will be updated when all items are confirmed/rejected

            return $order->fresh(['items']);
        });
    }

    /**
     * Reject specific item in order
     */
    public function rejectItem(PurchaseOrder $order, Supplier $supplier, string $itemId, string $reason, ?string $explanation = null): PurchaseOrder
    {
        if ($order->supplier_id !== $supplier->id) {
            throw new \InvalidArgumentException('Unauthorized access to this order');
        }

        if (!in_array($order->status, [OrderStatus::PENDING, OrderStatus::PARTIAL_CONFIRMATION])) {
            throw new \InvalidArgumentException('Order must be in pending or partial confirmation status to reject item');
        }

        return DB::transaction(function () use ($order, $itemId, $reason, $explanation) {
            $item = $order->items()->where('item_id', $itemId)->first();

            if (!$item) {
                throw new \InvalidArgumentException('Item not found in order');
            }

            if ($item->status !== OrderItemStatus::PENDING) {
                throw new \InvalidArgumentException('Item must be in pending status to reject');
            }

            // Reject the item
            $item->status = OrderItemStatus::REJECTED;
            $item->quantity_confirmed = 0;

            // Store rejection reason in approval_data for history
            $item->approval_data = [
                'rejection_reason' => $reason,
                'explanation' => $explanation,
                'rejected_at' => now()->toDateTimeString(),
            ];

            $item->save();

            // Check and transition order status if all items are decided or cancelled
            $order->checkAndTransitionToConfirmed();

            // Send notification to branch manager
            $this->notificationService->notifyOrderModificationRequested($order, [
                'type' => 'item_rejected',
                'item_id' => $itemId,
                'item_name' => $item->item_name,
                'reason' => $reason,
                'explanation' => $explanation,
            ]);

            return $order->fresh(['items']);
        });
    }

    /**
     * Cancel specific item in order (by supplier)
     */
    public function cancelItem(PurchaseOrder $order, Supplier $supplier, string $itemId, string $reason): PurchaseOrder
    {
        if ($order->supplier_id !== $supplier->id) {
            throw new \InvalidArgumentException('Unauthorized access to this order');
        }

        if (!in_array($order->status, [OrderStatus::PENDING, OrderStatus::PARTIAL_CONFIRMATION])) {
            throw new \InvalidArgumentException('Order must be in pending or partial confirmation status to cancel item');
        }

        return DB::transaction(function () use ($order, $itemId, $reason) {
            $item = $order->items()->where('item_id', $itemId)->first();

            if (!$item) {
                throw new \InvalidArgumentException('Item not found in order');
            }

            if ($item->status->isCancelled()) {
                throw new \InvalidArgumentException('Item is already cancelled');
            }

            // Cancel item by supplier
            $item->cancelBySupplier($reason);

            // Check and transition order status if all items are decided
            $order->checkAndTransitionToConfirmed();

            // Send notification to branch manager
            $this->notificationService->notifyOrderModificationRequested($order, [
                'type' => 'item_cancelled',
                'item_id' => $itemId,
                'item_name' => $item->item_name,
                'reason' => $reason,
                'cancelled_by' => 'supplier',
            ]);

            return $order->fresh(['items']);
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
