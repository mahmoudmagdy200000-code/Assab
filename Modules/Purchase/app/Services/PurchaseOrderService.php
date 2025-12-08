<?php

namespace Modules\Purchase\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\TimelineEventType;
use Modules\Purchase\Models\OrderTimeline;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;

class PurchaseOrderService
{
    public function __construct(
        private readonly TimelineService $timelineService,
        private readonly CalculationService $calculationService
    ) {}

    /**
     * Get purchase history with filters
     * 
     * Filters:
     * - Search: by item name
     * - Perspective: submitted (Close, Canceled) or received (Confirmed, Partial Confirmation)
     * - Type: All, Direct Supplier Order, Via Purchasing Officer, Internal Transfer
     * - Date: Last 24h, Last 7d, Last 30d, or Custom date range
     */
    public function getHistory(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = PurchaseOrder::with(['items', 'supplier', 'branch', 'requestedBy', 'fromBranch'])
            ->history()
            ->orderBy('created_at', 'desc');

        // Search by item name
        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // Filter by perspective (submitted/received) - takes precedence over status
        // Submitted: Close, Canceled
        // Received: Confirmed, Partial Confirmation
        if (!empty($filters['perspective'])) {
            if ($filters['perspective'] === 'submitted') {
                $query->whereIn('status', [OrderStatus::CLOSED, OrderStatus::CANCELED]);
            } elseif ($filters['perspective'] === 'received') {
                $query->whereIn('status', [OrderStatus::CONFIRMED, OrderStatus::PARTIAL_CONFIRMATION]);
            }
        }

        // Filter by type: All, Direct Supplier Order, Via Purchasing Officer, Internal Transfer
        if (!empty($filters['type']) && $filters['type'] !== 'all') {
            try {
                $orderType = OrderType::from($filters['type']);
                $query->byType($orderType);
            } catch (\ValueError $e) {
                // Invalid type, skip filter
                Log::warning('Invalid order type filter', ['type' => $filters['type']]);
            }
        }

        // Date filters
        // If date_range is 'custom', use date_from and date_to
        // Otherwise, use the predefined ranges
        if (!empty($filters['date_range'])) {
            if ($filters['date_range'] === 'custom') {
                // Custom date range
                if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
                    $query->byDateRange($filters['date_from'] ?? null, $filters['date_to'] ?? null);
                }
            } else {
                // Predefined ranges
                match($filters['date_range']) {
                    'last_24h' => $query->last24Hours(),
                    'last_7d' => $query->last7Days(),
                    'last_30d' => $query->last30Days(),
                    default => null,
                };
            }
        } elseif (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            // If date_range is not set but date_from/date_to are provided, use them
            $query->byDateRange($filters['date_from'] ?? null, $filters['date_to'] ?? null);
        }

        // Filter by branch
        if (!empty($filters['branch_id'])) {
            $query->byBranch($filters['branch_id']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Get pending orders with filters
     */
    public function getPendingOrders(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = PurchaseOrder::with(['items', 'supplier', 'branch', 'requestedBy', 'fromBranch'])
            ->pending()
            ->orderBy('created_at', 'desc');

        // Apply filters
        if (!empty($filters['type'])) {
            $query->byType(OrderType::from($filters['type']));
        }

        if (!empty($filters['status'])) {
            $query->byStatus(OrderStatus::from($filters['status']));
        }

        if (!empty($filters['branch_id'])) {
            $query->byBranch($filters['branch_id']);
        }

        // Date filters
        $this->applyDateFilters($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * Get orders for receiving
     */
    public function getOrdersForReceiving(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = PurchaseOrder::with(['items', 'supplier', 'branch', 'latestGoodsReceipt'])
            ->forReceiving()
            ->orderBy('created_at', 'desc');

        if (!empty($filters['type'])) {
            $query->byType(OrderType::from($filters['type']));
        }

        if (!empty($filters['branch_id'])) {
            $query->byBranch($filters['branch_id']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Create a new purchase order
     */
    public function createOrder(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {
            // Set sourceable_type and sourceable_id based on order type
            $sourceableType = null;
            $sourceableId = null;
            
            $orderType = is_string($data['order_type']) 
                ? OrderType::from($data['order_type']) 
                : $data['order_type'];
            
            if ($orderType === OrderType::DIRECT_SUPPLIER) {
                $sourceableType = \Modules\Purchase\Models\PurchaseSupplier::class;
                $sourceableId = $data['supplier_id'] ?? null;
                if (!$sourceableId) {
                    throw new \InvalidArgumentException('Supplier ID is required for direct supplier orders');
                }
            } elseif ($orderType === OrderType::VIA_PURCHASING_OFFICER) {
                // For purchasing officer orders, use the branch manager as source
                $sourceableType = \Modules\BranchManagers\Models\BranchManager::class;
                $sourceableId = $data['requested_by'] ?? null;
                if (!$sourceableId) {
                    throw new \InvalidArgumentException('Requested by (Branch Manager ID) is required');
                }
            } elseif ($orderType === OrderType::INTERNAL_TRANSFER) {
                $sourceableType = \Modules\Branch\Models\Branch::class;
                $sourceableId = $data['from_branch_id'] ?? null;
                if (!$sourceableId) {
                    throw new \InvalidArgumentException('From branch ID is required for internal transfer orders');
                }
            }
            
            $order = PurchaseOrder::create([
                'order_type' => $data['order_type'],
                'status' => $data['status'] ?? OrderStatus::DRAFT,
                'branch_id' => $data['branch_id'],
                'requested_by' => $data['requested_by'],
                'sourceable_type' => $sourceableType,
                'sourceable_id' => $sourceableId,
                'supplier_id' => $data['supplier_id'] ?? null,
                'from_branch_id' => $data['from_branch_id'] ?? null,
                'to_branch_id' => $data['to_branch_id'] ?? null,
                'quality_level' => $data['quality_level'] ?? null,
                'processing_time' => $data['processing_time'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'preferred_delivery_date' => $data['preferred_delivery_date'] ?? null,
                'latest_delivery_date' => $data['latest_delivery_date'] ?? null,
                'notification_channels' => $data['notification_channels'] ?? null,
                'message' => $data['message'] ?? null,
                'special_instructions' => $data['special_instructions'] ?? null,
                'tax_rate' => $data['tax_rate'] ?? 15.00,
            ]);

            // Create order items
            if (!empty($data['items'])) {
                foreach ($data['items'] as $item) {
                    $this->addItem($order, $item);
                }
            }

            // Calculate totals
            $order->calculateTotals();

            // Log timeline
            $this->timelineService->logOrderCreated($order);

            return $order->fresh(['items', 'supplier', 'branch']);
        });
    }

    /**
     * Add item to order
     */
    public function addItem(PurchaseOrder $order, array $data): PurchaseOrderItem
    {
        // For internal transfers, unit_price is optional (defaults to 0 - free transfer)
        // For other order types, unit_price should be provided
        $unitPrice = $data['unit_price'] ?? 0;
        
        $totalPrice = ($data['quantity'] * $unitPrice) - ($data['discount'] ?? 0);

        return PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'item_id' => $data['item_id'] ?? null,
            'item_name' => $data['item_name'],
            'item_logo' => $data['item_logo'] ?? null,
            'item_sku' => $data['item_sku'] ?? null,
            'category' => $data['category'] ?? null,
            'subcategory' => $data['subcategory'] ?? null,
            'quantity_ordered' => $data['quantity'],
            'unit_of_measurement' => $data['unit'] ?? 'kg',
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice,
            'discount' => $data['discount'] ?? 0,
            'quality_ordered' => $data['quality'] ?? null,
            'available_in_source' => $data['available_in_source'] ?? null,
            'daily_consumption' => $data['daily_consumption'] ?? null,
            'weekend_forecast' => $data['weekend_forecast'] ?? null,
            'next_supply_date' => $data['next_supply_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'cooling_status' => $data['cooling_status'] ?? null,
        ]);
    }

    /**
     * Update order items
     */
    public function updateItems(PurchaseOrder $order, array $items): void
    {
        DB::transaction(function () use ($order, $items) {
            foreach ($items as $itemData) {
                if (isset($itemData['id'])) {
                    $item = PurchaseOrderItem::find($itemData['id']);
                    if ($item) {
                        $item->update([
                            'quantity_ordered' => $itemData['quantity'] ?? $item->quantity_ordered,
                            'unit_price' => $itemData['unit_price'] ?? $item->unit_price,
                            'quality_ordered' => $itemData['quality'] ?? $item->quality_ordered,
                        ]);
                        $item->calculateTotalPrice();
                    }
                }
            }

            $order->calculateTotals();
        });
    }

    /**
     * Submit order
     */
    public function submitOrder(PurchaseOrder $order): bool
    {
        if (!$order->submit()) {
            return false;
        }

        $this->timelineService->logOrderSubmitted($order);
        
        return true;
    }

    /**
     * Confirm order
     */
    public function confirmOrder(PurchaseOrder $order, ?array $itemConfirmations = null): bool
    {
        return DB::transaction(function () use ($order, $itemConfirmations) {
            if ($itemConfirmations) {
                foreach ($itemConfirmations as $confirmation) {
                    $item = PurchaseOrderItem::find($confirmation['item_id']);
                    if ($item) {
                        $item->confirm($confirmation['quantity'] ?? null);
                    }
                }
            }

            $order->transitionTo(OrderStatus::CONFIRMED);
            $this->timelineService->logOrderConfirmed($order);
            
            return true;
        });
    }

    /**
     * Partially confirm order
     */
    public function partialConfirmOrder(PurchaseOrder $order, array $itemConfirmations): bool
    {
        return DB::transaction(function () use ($order, $itemConfirmations) {
            foreach ($itemConfirmations as $confirmation) {
                $item = PurchaseOrderItem::find($confirmation['item_id']);
                if ($item) {
                    $item->confirm($confirmation['quantity']);
                }
            }

            $order->transitionTo(OrderStatus::PARTIAL_CONFIRMATION);
            $this->timelineService->logPartialConfirmation($order);
            
            return true;
        });
    }

    /**
     * Reject order
     */
    public function rejectOrder(PurchaseOrder $order, string $reason): bool
    {
        if (!$order->reject($reason)) {
            return false;
        }

        $this->timelineService->logOrderRejected($order, $reason);
        
        return true;
    }

    /**
     * Cancel order
     */
    public function cancelOrder(PurchaseOrder $order, ?string $reason = null): bool
    {
        if (!$order->cancel($reason)) {
            return false;
        }

        $this->timelineService->logOrderCanceled($order, $reason);
        
        return true;
    }

    /**
     * Update order status to preparing
     */
    public function markAsPreparing(PurchaseOrder $order): bool
    {
        if (!$order->markAsPreparing()) {
            return false;
        }

        $this->timelineService->logPreparationStarted($order);
        
        return true;
    }

    /**
     * Update order status to on the way
     */
    public function markAsOnTheWay(PurchaseOrder $order, array $deliveryDetails = []): bool
    {
        $order->fill($deliveryDetails);
        
        if (!$order->markAsOnTheWay()) {
            return false;
        }

        $this->timelineService->logOutForDelivery($order);
        
        return true;
    }

    /**
     * Report delay
     */
    public function reportDelay(PurchaseOrder $order, string $reason, ?string $newDeliveryDate = null): bool
    {
        if ($newDeliveryDate) {
            $order->expected_delivery_at = $newDeliveryDate;
        }

        if (!$order->reportDelay($reason)) {
            return false;
        }

        $this->timelineService->logDeliveryDelayed($order, $reason);
        
        return true;
    }

    /**
     * Handle modification request
     */
    public function handleModification(PurchaseOrder $order, array $modifications): void
    {
        DB::transaction(function () use ($order, $modifications) {
            foreach ($modifications as $mod) {
                $item = PurchaseOrderItem::find($mod['item_id']);
                if ($item) {
                    $item->updateQuantity($mod['new_quantity'], $mod['note'] ?? null);
                }
            }

            $order->transitionTo(OrderStatus::PENDING_APPROVAL);
            $order->calculateTotals();

            $this->timelineService->logOrderModified($order, $modifications);
        });
    }

    /**
     * Approve modifications
     */
    public function approveModifications(PurchaseOrder $order): bool
    {
        if (!$order->transitionTo(OrderStatus::CONFIRMED)) {
            return false;
        }

        $this->timelineService->logModificationsApproved($order);
        
        return true;
    }

    /**
     * Reject modifications
     */
    public function rejectModifications(PurchaseOrder $order, string $reason): bool
    {
        if (!$order->cancel($reason)) {
            return false;
        }

        $this->timelineService->logModificationsRejected($order, $reason);
        
        return true;
    }

    /**
     * Close order
     */
    public function closeOrder(PurchaseOrder $order): bool
    {
        if (!$order->close()) {
            return false;
        }

        $this->timelineService->logOrderClosed($order);
        
        return true;
    }

    /**
     * Save order as draft
     */
    public function saveDraft(array $data): PurchaseOrder
    {
        $data['status'] = OrderStatus::DRAFT;
        return $this->createOrder($data);
    }

    /**
     * Get order details
     */
    public function getOrderDetails(string $orderId): ?PurchaseOrder
    {
        return PurchaseOrder::with([
            'items',
            'supplier',
            'branch',
            'requestedBy',
            'fromBranch',
            'goodsReceipts.items',
            'timelines',
            'documents',
            'variances',
            'returnOrders.items',
        ])->find($orderId);
    }

    /**
     * Get order timeline
     */
    public function getOrderTimeline(string $orderId): \Illuminate\Database\Eloquent\Collection
    {
        return OrderTimeline::where('timelineable_type', PurchaseOrder::class)
            ->where('timelineable_id', $orderId)
            ->orderBy('occurred_at', 'desc')
            ->get();
    }

    /**
     * Apply date filters to query
     */
    private function applyDateFilters($query, array $filters): void
    {
        if (!empty($filters['date_range'])) {
            match($filters['date_range']) {
                'last_24h' => $query->last24Hours(),
                'last_7d' => $query->last7Days(),
                'last_30d' => $query->last30Days(),
                default => null,
            };
        }

        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $query->byDateRange($filters['date_from'] ?? null, $filters['date_to'] ?? null);
        }
    }
}

