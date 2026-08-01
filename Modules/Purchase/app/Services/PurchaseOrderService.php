<?php

namespace Modules\Purchase\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Purchase\Constants\PurchaseConstants;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\QualityLevel;
use Modules\Purchase\Exceptions\PurchaseOrderException;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\OrderTimeline;
use Modules\Purchase\Models\PriceHistory;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;

class PurchaseOrderService implements \Modules\Purchase\Services\Contracts\PurchaseOrderServiceInterface
{
    public function __construct(
        private readonly TimelineService $timelineService,
        private readonly OrderCreationService $orderCreationService,
        private readonly PurchaseOrderDelayService $delayService,
        private readonly PurchaseOrderItemService $itemService,
        private readonly SupplierCatalogService $supplierCatalog
    ) {}

    /**
     * Get branch items with filters
     *
     * Supports:
     * - Search by item name
     * - Filter by category/subcategory
     * - Filter by supplier (from Expense module)
     */
    public function getBranchItems(string $branchId, array $filters = [], ?int $perPage = null): LengthAwarePaginator
    {
        $perPage = $perPage ?? PurchaseConstants::DEFAULT_PER_PAGE;

        // Performance: Eager load only needed item columns
        $query = BranchItem::where('branch_id', $branchId)
            ->with('item:id,name,code,unit,logo,category,subcategory');

        // Search by item name (through Item model)
        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // Filter by category (through Item model)
        if (! empty($filters['category'])) {
            $query->byCategory($filters['category']);
        }

        // Filter by subcategory (through Item model)
        if (! empty($filters['subcategory'])) {
            $query->bySubcategory($filters['subcategory']);
        }

        // Filter by supplier (from Expense module)
        if (! empty($filters['supplier_id'])) {
            $query->bySupplier($filters['supplier_id']);
        }

        // Order by item name through relationship
        $branchItems = $query->join('items', 'branch_item.item_id', '=', 'items.id')
            ->orderBy('items.name', 'asc')
            ->select('branch_item.*', 'items.name as item_name', 'items.code as item_code', 'items.unit as item_unit', 'items.logo as item_logo', 'items.category', 'items.subcategory')
            ->paginate($perPage);

        // Resolve the supplier counts for the whole page in one pass — the
        // resource would otherwise have to ask per row (N+1).
        $counts = $this->supplierCatalog->supplierCountsForItems(
            $branchItems->getCollection()->pluck('item_id')->all(),
            $branchId
        );

        $branchItems->getCollection()->each(
            fn ($branchItem) => $branchItem->setAttribute('suppliers_count', $counts[$branchItem->item_id] ?? 0)
        );

        return $branchItems;
    }

    /**
     * Get purchase history with filters.
     *
     * Filters:
     * - Search: by item name
     * - Perspective: submitted (Close, Canceled) or received (Confirmed, Partial Confirmation)
     * - Type: All, Direct Supplier Order, Via Purchasing Officer, Internal Transfer
     * - Date: Last 24h, Last 7d, Last 30d, or Custom date range
     */
    public function getHistory(array $filters, ?int $perPage = null): LengthAwarePaginator
    {
        $perPage = $perPage ?? PurchaseConstants::DEFAULT_PER_PAGE;

        $query = PurchaseOrder::with([
            'items:id,purchase_order_id,item_id,item_name,quantity_ordered,unit_price,total_price',
            'supplier:id,name,phone,email',
            'branch:id,name,lat,lng,opening_hours,closing_hours,image',
            'requestedBy:id,name,email',
            'fromBranch:id,name,lat,lng',
        ])
            ->history()
            ->orderBy('created_at', 'desc');

        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        $this->applyPerspectiveFilter($query, $filters['perspective'] ?? null);
        $this->applyHistoryTypeFilter($query, $filters['type'] ?? null);
        $this->applyHistoryDateFilters($query, $filters);

        if (! empty($filters['branch_id'])) {
            $query->forBranchHistory($filters['branch_id']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Get orders with filters (all orders for the branch)
     *
     * Returns orders created BY this branch (orders initiated by this branch).
     * For internal_transfer: only include orders where this branch is the destination (to_branch_id = this branch)
     * and this branch created the order (branch_id = this branch).
     * Excludes orders requested FROM this branch by others (those appear in requested_orders).
     */
    public function getOrders(array $filters, ?int $perPage = null): LengthAwarePaginator
    {
        $perPage = $perPage ?? PurchaseConstants::DEFAULT_PER_PAGE;

        // Performance: Eager load only needed columns
        $query = PurchaseOrder::with([
            'items:id,purchase_order_id,item_id,item_name,quantity_ordered,quantity_confirmed,unit_price,total_price,status,approval_type,approval_data',
            'items.item:id,name,code,logo,unit',
            'supplier:id,name,phone,email',
            'fromBranch:id,name,location',
        ])
            ->orderBy('created_at', 'desc');

        // Filter by branch: orders created BY this branch (branch_id = requester branch)
        // Includes: direct_supplier, via_purchasing_officer, and internal_transfer where this branch is the requester (destination)
        // Excludes: orders requested FROM this branch by others (those appear in requested_orders via getPendingOrders)
        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        // Search by item name or order number
        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // Filter by order type
        if (! empty($filters['order_type'])) {
            try {
                $orderType = OrderType::from($filters['order_type']);
                $query->byType($orderType);
            } catch (\ValueError $e) {
                Log::warning('Invalid order type filter', ['type' => $filters['order_type']]);
            }
        }

        // Filter by status
        if (! empty($filters['status'])) {
            try {
                $status = OrderStatus::from($filters['status']);
                $query->byStatus($status);
            } catch (\ValueError $e) {
                Log::warning('Invalid status filter', ['status' => $filters['status']]);
            }
        }

        // Date filters
        $this->applyDateFilters($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * Get pending orders with filters
     *
     * Returns orders requested FROM this branch by other branches
     * For internal_transfer: orders where to_branch_id = this branch AND from_branch_id != this branch
     * For other types: no orders are requested from other branches (only from suppliers/officers)
     */
    public function getPendingOrders(array $filters, ?int $perPage = null): LengthAwarePaginator
    {
        $perPage = $perPage ?? PurchaseConstants::DEFAULT_PER_PAGE;

        // Performance: Eager load only needed columns to prevent N+1 queries
        $query = PurchaseOrder::with([
            'items:id,purchase_order_id,item_id,item_name,quantity_ordered,quantity_confirmed,unit_price,total_price,status,approval_type,approval_data',
            'items.item:id,name,code,logo,unit',
            'supplier:id,name,phone,email',
            'branch:id,name,lat,lng,opening_hours,closing_hours,image',
            'requestedBy:id,name,email',
            'fromBranch:id,name,lat,lng',
        ])
            ->pending()
            ->orderBy('created_at', 'desc');

        // Filter by branch - get orders requested FROM this branch
        if (! empty($filters['branch_id'])) {
            $branchId = $filters['branch_id'];

            // For internal_transfer: get orders requested FROM this branch by other branches
            // Condition: from_branch_id = this branch (this branch is the source/sender)
            // This means: another branch requested items from this branch
            $query->where(function ($q) use ($branchId) {
                $q->where(function ($subQuery) use ($branchId) {
                    // Internal transfers requested from this branch by other branches
                    $subQuery->where('order_type', OrderType::INTERNAL_TRANSFER)
                        ->where('from_branch_id', $branchId); // This branch is the source/sender
                });
                // Note: direct_supplier and via_purchasing_officer orders are not "requested from" a branch
                // They are requested by a branch from suppliers/officers, so they don't appear here
            });
        }

        // Apply filters
        if (! empty($filters['type'])) {
            $query->byType(OrderType::from($filters['type']));
        }

        if (! empty($filters['status'])) {
            $query->byStatus(OrderStatus::from($filters['status']));
        }

        // Date filters
        $this->applyDateFilters($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * Get orders for receiving grouped by expected delivery date.
     * Returns only orders with DELIVERED status (or receivable statuses for internal transfers).
     */
    public function getOrdersForReceiving(array $filters, ?int $perPage = null): LengthAwarePaginator
    {
        $perPage = $perPage ?? PurchaseConstants::DEFAULT_PER_PAGE;
        $currentPage = request()->get('page', 1);

        $cancelledStatuses = OrderItemStatus::cancelledStatusValues();
        $orderType = ! empty($filters['type']) ? OrderType::from($filters['type']) : null;

        $query = PurchaseOrder::withCount([
            'items as items_count' => fn ($q) => $q->whereNotIn('status', $cancelledStatuses),
        ]);

        $this->applyReceivingStatusFilter($query, $orderType);

        if ($orderType) {
            $query->byType($orderType);
        }

        if (! empty($filters['branch_id'])) {
            $this->applyReceivingBranchFilter($query, $filters['branch_id'], $orderType);
        }

        $this->applyReceivingSortOrder($query, $orderType);

        $query->whereHas('items', fn ($q) => $q->whereNotIn('status', $cancelledStatuses));

        $paginator = $query->paginate($perPage, ['id', 'order_type', 'status', 'created_at', 'expected_delivery_at'], 'page', $currentPage);
        $paginator->setPath(request()->url());

        $paginator->setCollection(
            $paginator->getCollection()->map(fn ($order) => $this->transformReceivingOrder($order))->values()
        );

        return $paginator;
    }

    /**
     * Create a new purchase order.
     */
    public function createOrder(array $data): PurchaseOrder
    {
        $order = DB::transaction(function () use ($data) {
            $orderType = is_string($data['order_type'])
                ? OrderType::from($data['order_type'])
                : $data['order_type'];

            [$sourceableType, $sourceableId] = $this->resolveSourceable($data, $orderType);

            $order = PurchaseOrder::create([
                'order_type' => $data['order_type'],
                'status' => $data['status'] ?? OrderStatus::PENDING,
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
                'tax_rate' => $data['tax_rate'] ?? PurchaseConstants::DEFAULT_TAX_RATE,
                'recurring_order_id' => $data['recurring_order_id'] ?? null,
                'recurring_metadata' => $data['recurring_metadata'] ?? null,
            ]);

            // Create order items
            if (! empty($data['items'])) {
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

        // AFTER commit so the ASAB bridge never reads an uncommitted order —
        // this is what lands the order in the accountant's inbox (PUR- op).
        if ($order->status !== OrderStatus::DRAFT) {
            \Modules\Purchase\Events\OrderCreated::dispatch($order);
        }

        return $order;
    }

    /**
     * Create multiple orders from different sources
     *
     * Supports:
     * - branches[]: Internal transfers from multiple branches
     * - direct_supplier[]: Direct supplier orders
     * - purchase_officer[]: Purchasing officer orders
     *
     * @param  array  $data  Request data containing branches, direct_supplier, and/or purchase_officer arrays
     * @param  string  $branchId  The branch ID for the orders
     * @param  string  $requestedBy  The user ID who requested the orders
     * @param  bool  $isDraft  Whether to create orders as draft (true) or pending (false)
     * @return Collection Collection of created PurchaseOrder models
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function createMultipleOrders(array $data, string $branchId, string $requestedBy, bool $isDraft = false, bool $isEmergency = false): Collection
    {
        $this->validateCreateMultipleOrdersInputs($branchId, $requestedBy);

        return DB::transaction(function () use ($data, $branchId, $requestedBy, $isDraft, $isEmergency) {
            $orders = collect();

            // Process internal transfers
            $orders = $orders->merge($this->createInternalTransferOrders($data, $branchId, $requestedBy, $isDraft, $isEmergency));

            // Process direct supplier orders
            $orders = $orders->merge($this->createDirectSupplierOrders($data, $branchId, $requestedBy, $isDraft, $isEmergency));

            // Process purchasing officer orders
            $orders = $orders->merge($this->createPurchasingOfficerOrders($data, $branchId, $requestedBy, $isDraft, $isEmergency));

            if ($orders->isEmpty()) {
                throw new \InvalidArgumentException('No orders were created. Please provide at least one valid order (branches, direct_supplier, or purchase_officer).');
            }

            return $orders;
        });
    }

    /**
     * Resolve the sourceable type and ID for a new order based on order type.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveSourceable(array $data, OrderType $orderType): array
    {
        if (! empty($data['sourceable_type']) && ! empty($data['sourceable_id'])) {
            return [$data['sourceable_type'], $data['sourceable_id']];
        }

        return $this->resolveSourceableByOrderType($data, $orderType);
    }

    /**
     * Resolve sourceable class and ID based on a typed order type.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveSourceableByOrderType(array $data, OrderType $orderType): array
    {
        $definitions = [
            OrderType::DIRECT_SUPPLIER->value => [
                'class' => \Modules\Supplier\Models\Supplier::class,
                'id' => $data['supplier_id'] ?? null,
                'exception' => fn () => PurchaseOrderException::supplierIdRequired(),
            ],
            OrderType::VIA_PURCHASING_OFFICER->value => [
                'class' => \Modules\BranchManagers\Models\BranchManager::class,
                'id' => $data['sourceable_id'] ?? $data['requested_by'] ?? null,
                'exception' => fn () => PurchaseOrderException::requestedByRequired(),
            ],
            OrderType::INTERNAL_TRANSFER->value => [
                'class' => \Modules\Branch\Models\Branch::class,
                'id' => $data['from_branch_id'] ?? null,
                'exception' => fn () => PurchaseOrderException::fromBranchIdRequired(),
            ],
        ];

        if (! isset($definitions[$orderType->value])) {
            return [null, null];
        }

        ['class' => $class, 'id' => $id, 'exception' => $mkException] = $definitions[$orderType->value];
        if (! $id) {
            throw $mkException();
        }

        return [$class, $id];
    }

    /**
     * Validate inputs for createMultipleOrders
     */
    private function validateCreateMultipleOrdersInputs(string $branchId, string $requestedBy): void
    {
        if (empty($branchId)) {
            throw new \InvalidArgumentException('Branch ID is required');
        }

        if (empty($requestedBy)) {
            throw new \InvalidArgumentException('Requested by (user ID) is required');
        }
    }

    /**
     * Create internal transfer orders
     */
    private function createInternalTransferOrders(array $data, string $branchId, string $requestedBy, bool $isDraft, bool $isEmergency = false): Collection
    {
        $orders = collect();

        if (empty($data['branches']) || ! is_array($data['branches'])) {
            return $orders;
        }

        foreach ($data['branches'] as $index => $branchData) {
            try {
                $orderData = $this->orderCreationService->prepareInternalTransferOrderData(
                    $branchData,
                    $branchId,
                    $requestedBy,
                    $isDraft,
                    $index,
                    $isEmergency
                );
                $order = $this->createOrder($orderData);
                $orders->push($order);
            } catch (\Exception $e) {
                Log::error("Error creating internal transfer order at index {$index}", [
                    'error' => $e->getMessage(),
                    'branch_data' => $branchData,
                ]);
                throw PurchaseOrderException::failedToCreateOrder($index, $e->getMessage());
            }
        }

        return $orders;
    }

    /**
     * Create direct supplier orders
     */
    private function createDirectSupplierOrders(array $data, string $branchId, string $requestedBy, bool $isDraft, bool $isEmergency = false): Collection
    {
        $orders = collect();

        if (empty($data['direct_supplier']) || ! is_array($data['direct_supplier'])) {
            return $orders;
        }

        foreach ($data['direct_supplier'] as $index => $supplierData) {
            try {
                $orderData = $this->orderCreationService->prepareDirectSupplierOrderData(
                    $supplierData,
                    $branchId,
                    $requestedBy,
                    $isDraft,
                    $index,
                    $isEmergency
                );
                $order = $this->createOrder($orderData);
                $orders->push($order);
            } catch (\Exception $e) {
                Log::error("Error creating direct supplier order at index {$index}", [
                    'error' => $e->getMessage(),
                    'supplier_data' => $supplierData,
                ]);
                throw PurchaseOrderException::failedToCreateOrder($index, $e->getMessage());
            }
        }

        return $orders;
    }

    /**
     * Create purchasing officer orders
     */
    private function createPurchasingOfficerOrders(array $data, string $branchId, string $requestedBy, bool $isDraft, bool $isEmergency = false): Collection
    {
        $orders = collect();

        if (empty($data['purchase_officer']) || ! is_array($data['purchase_officer'])) {
            return $orders;
        }

        foreach ($data['purchase_officer'] as $index => $officerData) {
            try {
                $orderData = $this->orderCreationService->preparePurchasingOfficerOrderData(
                    $officerData,
                    $branchId,
                    $requestedBy,
                    $isDraft,
                    $index,
                    $isEmergency
                );
                $order = $this->createOrder($orderData);
                $orders->push($order);
            } catch (\Exception $e) {
                Log::error("Error creating purchasing officer order at index {$index}", [
                    'error' => $e->getMessage(),
                    'officer_data' => $officerData,
                ]);
                throw PurchaseOrderException::failedToCreateOrder($index, $e->getMessage());
            }
        }

        return $orders;
    }

    /**
     * Add item to order. Delegates to PurchaseOrderItemService.
     */
    public function addItem(PurchaseOrder $order, array $data): PurchaseOrderItem
    {
        return $this->itemService->addItem($order, $data);
    }

    /**
     * Update order items. Delegates to PurchaseOrderItemService.
     */
    public function updateItems(PurchaseOrder $order, array $items): void
    {
        $this->itemService->updateItems($order, $items);
    }

    /**
     * Submit order
     */
    public function submitOrder(PurchaseOrder $order): bool
    {
        if (! $order->submit()) {
            return false;
        }

        $this->timelineService->logOrderSubmitted($order);

        return true;
    }

    /**
     * Delete draft order (soft delete). Only allowed when order status is DRAFT.
     */
    public function deleteDraftOrder(PurchaseOrder $order): bool
    {
        if ($order->status !== OrderStatus::DRAFT) {
            return false;
        }

        $order->delete();

        return true;
    }

    /**
     * Confirm order
     */
    public function confirmOrder(PurchaseOrder $order, ?array $itemConfirmations = null, ?string $readyTime = null): bool
    {
        return DB::transaction(function () use ($order, $itemConfirmations, $readyTime) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $order->status->isDecisionPhase() || $order->status === OrderStatus::CONFIRMED) {
                throw new \InvalidArgumentException(
                    "Cannot approve order. Current status: {$order->status?->value}. Order must be in pending status."
                );
            }

            $this->applyItemConfirmations($order, $itemConfirmations);

            if ($readyTime !== null) {
                $order->update(['ready_time' => $readyTime]);
            }

            $order->checkAndTransitionToConfirmed();

            if ($order->fresh()->status === OrderStatus::CONFIRMED) {
                $this->timelineService->logOrderConfirmed($order);
                $this->recordOrderPrices($order);
            }

            return true;
        });
    }

    /**
     * Confirm individual items or all pending items depending on whether confirmations are provided.
     */
    private function applyItemConfirmations(PurchaseOrder $order, ?array $itemConfirmations): void
    {
        if (! $itemConfirmations) {
            $order->items()->where('status', OrderItemStatus::PENDING)->get()->each->confirm();

            return;
        }

        foreach ($itemConfirmations as $confirmation) {
            $item = $order->items()->find($confirmation['item_id']);
            if ($item) {
                $item->confirm($confirmation['quantity'] ?? $item->quantity_ordered);
            }
        }
    }

    /**
     * Partially confirm order (confirm specific items only)
     */
    public function partialConfirmOrder(PurchaseOrder $order, array $itemConfirmations, ?string $readyTime = null): bool
    {
        return DB::transaction(function () use ($order, $itemConfirmations, $readyTime) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            // Check if order is in decision phase
            if (! $order->status->isDecisionPhase() || $order->status === OrderStatus::CONFIRMED) {
                throw new \InvalidArgumentException(
                    "Cannot partially approve order. Current status: {$order->status?->value}. Order must be in pending status."
                );
            }

            // Confirm specified items
            foreach ($itemConfirmations as $confirmation) {
                $item = $order->items()->find($confirmation['item_id']);
                if ($item) {
                    $quantity = $confirmation['quantity'] ?? $item->quantity_ordered;
                    $item->confirm($quantity);
                }
            }

            // Reject items not in the confirmation list
            $confirmedItemIds = collect($itemConfirmations)->pluck('item_id')->toArray();
            $order->items()
                ->whereNotIn('id', $confirmedItemIds)
                ->where('status', OrderItemStatus::PENDING)
                ->get()
                ->each(function ($item) {
                    $item->status = OrderItemStatus::REJECTED;
                    $item->quantity_confirmed = 0;
                    $item->save();
                });

            // Update ready_time if provided
            if ($readyTime !== null) {
                $order->update(['ready_time' => $readyTime]);
            }

            // Check and transition to CONFIRMED if all items are decided
            $order->checkAndTransitionToConfirmed();

            // If order is now confirmed, log and record prices
            if ($order->fresh()->status === OrderStatus::CONFIRMED) {
                $this->timelineService->logOrderConfirmed($order);
                $this->recordOrderPrices($order, $itemConfirmations);
            }

            return true;
        });
    }

    /**
     * Reject order
     */
    public function rejectOrder(PurchaseOrder $order, string $reason): bool
    {
        if (! $order->reject($reason)) {
            return false;
        }

        $this->timelineService->logOrderRejected($order, $reason);

        return true;
    }

    /**
     * Cancel order
     */
    public function cancelOrder(PurchaseOrder $order, ?string $reason = null, bool $byBranch = false, bool $bySupplier = false): bool
    {
        if (! $order->cancel($reason, $byBranch, $bySupplier)) {
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
        if (! $order->markAsPreparing()) {
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

        if (! $order->markAsOnTheWay()) {
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
        return DB::transaction(function () use ($order, $reason, $newDeliveryDate) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($newDeliveryDate) {
                $order->expected_delivery_at = $newDeliveryDate;
            }

            if (! $order->reportDelay($reason)) {
                return false;
            }

            // Update all items status to DELAYED (same as order status)
            $order->load('items');
            $order->items()
                ->whereNotIn('status', [
                    OrderItemStatus::CANCELLED,
                    OrderItemStatus::CANCELLED_BY_BRANCH,
                    OrderItemStatus::CANCELLED_BY_SUPPLIER,
                    OrderItemStatus::CANCELED_MODIFICATION,
                    OrderItemStatus::REJECTED,
                ])
                ->update(['status' => OrderItemStatus::DELAYED]);

            $this->timelineService->logDeliveryDelayed($order, $reason);

            return true;
        });
    }

    /**
     * Handle modification request
     */
    public function handleModification(PurchaseOrder $order, array $modifications): void
    {
        DB::transaction(function () use ($order, $modifications) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            foreach ($modifications as $mod) {
                $item = PurchaseOrderItem::whereKey($mod['item_id'])->lockForUpdate()->first();
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
        if (! $order->transitionTo(OrderStatus::CONFIRMED)) {
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
        if (! $order->cancel($reason)) {
            return false;
        }

        $this->timelineService->logModificationsRejected($order, $reason);

        return true;
    }

    /**
     * Approve item request (branch manager approves supplier's request for specific item).
     * Delegates to PurchaseOrderItemService.
     */
    public function approveItemRequest(PurchaseOrder $order, string $itemId, ?array $additionalData = null): bool
    {
        return $this->itemService->approveItemRequest($order, $itemId, $additionalData);
    }

    /**
     * Reject item request (branch manager rejects supplier's request for specific item).
     * Delegates to PurchaseOrderItemService.
     */
    public function rejectItemRequest(PurchaseOrder $order, string $itemId, ?string $reason = null): bool
    {
        return $this->itemService->rejectItemRequest($order, $itemId, $reason);
    }

    /**
     * Cancel item (branch manager cancels specific item).
     * Delegates to PurchaseOrderItemService.
     */
    public function cancelItem(PurchaseOrder $order, string $itemId, ?string $reason = null): bool
    {
        return $this->itemService->cancelItem($order, $itemId, $reason);
    }

    /**
     * Approve delay report for the entire order.
     * Delegates to PurchaseOrderDelayService.
     */
    public function approveOrderDelay(PurchaseOrder $order): bool
    {
        return $this->delayService->approveOrderDelay($order);
    }

    /**
     * Approve delay request (Branch Manager accepts) → status: delayed_confirmed.
     * Delegates to PurchaseOrderDelayService.
     */
    public function approveDelayRequest(PurchaseOrder $order): bool
    {
        return $this->delayService->approveDelayRequest($order);
    }

    /**
     * Reject delay request (Branch Manager rejects) → status: delayed_canceled.
     * Delegates to PurchaseOrderDelayService.
     */
    public function rejectDelayRequest(PurchaseOrder $order, ?string $reason = null): bool
    {
        return $this->delayService->rejectDelayRequest($order, $reason);
    }

    /**
     * Reject delay report for the entire order.
     * Delegates to PurchaseOrderDelayService.
     */
    public function rejectOrderDelay(PurchaseOrder $order, ?string $reason = null): bool
    {
        return $this->delayService->rejectOrderDelay($order, $reason);
    }

    /**
     * Close order
     */
    public function closeOrder(PurchaseOrder $order): bool
    {
        if (! $order->close()) {
            return false;
        }

        $this->timelineService->logOrderClosed($order);

        return true;
    }

    /**
     * Get order details
     *
     * Security: Optionally filter by branch_id to ensure user has access
     * For internal transfers, also checks from_branch_id for authorization
     *
     * @param  string|null  $branchId  Optional branch ID for authorization check
     */
    public function getOrderDetails(string $orderId, ?string $branchId = null): ?PurchaseOrder
    {
        // First, try to find the order by ID
        $order = PurchaseOrder::with([
            'items.goodsReceiptItems',
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

        if (! $order) {
            return null;
        }

        // Security: Check authorization if branch_id is provided
        if ($branchId !== null) {
            // Regular orders: user must belong to the order's branch
            $hasAccess = $order->branch_id === $branchId;

            // Internal transfers: user can also access if they belong to from_branch_id (sending branch)
            if (! $hasAccess && $order->order_type === OrderType::INTERNAL_TRANSFER) {
                $hasAccess = $order->from_branch_id === $branchId;
            }

            if (! $hasAccess) {
                return null;
            }
        }

        return $order;
    }

    /**
     * Get order timeline
     */
    public function getOrderTimeline(string $orderId): \Illuminate\Database\Eloquent\Collection
    {
        return OrderTimeline::where('timelineable_type', PurchaseOrder::class)
            ->where('timelineable_id', $orderId)
            ->orderBy('occurred_at', 'asc')
            ->get();
    }

    /**
     * Apply receivable-status constraints to the receiving query.
     */
    private function applyReceivingStatusFilter($query, ?OrderType $orderType): void
    {
        $internalStatuses = [
            OrderStatus::FULLY_APPROVED,
            OrderStatus::PARTIAL_APPROVED,
            OrderStatus::CONFIRMED,
            OrderStatus::PARTIAL_CONFIRMATION,
        ];

        if ($orderType === OrderType::INTERNAL_TRANSFER) {
            $query->whereIn('status', $internalStatuses);

            return;
        }

        if ($orderType !== null) {
            $query->byStatus(OrderStatus::DELIVERED)->whereNotNull('expected_delivery_at');

            return;
        }

        // No type filter: include both internal transfers and delivered orders
        $query->where(function ($q) use ($internalStatuses) {
            $q->where(function ($sub) use ($internalStatuses) {
                $sub->where('order_type', OrderType::INTERNAL_TRANSFER)
                    ->whereIn('status', $internalStatuses);
            })->orWhere(function ($sub) {
                $sub->where('order_type', '!=', OrderType::INTERNAL_TRANSFER)
                    ->where('status', OrderStatus::DELIVERED)
                    ->whereNotNull('expected_delivery_at');
            });
        });
    }

    /**
     * Apply branch filter for the receiving query (internal transfers use to_branch_id).
     */
    private function applyReceivingBranchFilter($query, string $branchId, ?OrderType $orderType): void
    {
        if ($orderType === OrderType::INTERNAL_TRANSFER) {
            $query->where('to_branch_id', $branchId);

            return;
        }

        if ($orderType !== null) {
            $query->byBranch($branchId);

            return;
        }

        $query->where(function ($q) use ($branchId) {
            $q->where(function ($sub) use ($branchId) {
                $sub->where('order_type', OrderType::INTERNAL_TRANSFER)
                    ->where('to_branch_id', $branchId);
            })->orWhere(function ($sub) use ($branchId) {
                $sub->where('order_type', '!=', OrderType::INTERNAL_TRANSFER)
                    ->where('branch_id', $branchId);
            });
        });
    }

    /**
     * Apply sort order for the receiving query.
     */
    private function applyReceivingSortOrder($query, ?OrderType $orderType): void
    {
        if ($orderType !== null && $orderType !== OrderType::INTERNAL_TRANSFER) {
            $query->orderBy('expected_delivery_at', 'asc');
        }
        $query->orderBy('created_at', 'desc');
    }

    /**
     * Transform a single order model into the receiving-list API shape.
     */
    private function transformReceivingOrder($order): array
    {
        $orderTypeValue = $order->order_type;
        $orderTypeStr = $orderTypeValue instanceof \BackedEnum ? $orderTypeValue->value : null;
        $orderType = $orderTypeStr ?? (is_string($orderTypeValue) ? $orderTypeValue : null);

        $statusValue = $order->status;
        $statusStr = $statusValue instanceof \BackedEnum ? $statusValue->value : null;
        $status = $statusStr ?? (is_string($statusValue) ? $statusValue : null);

        return [
            'id' => $order->id,
            'items_count' => (int) ($order->items_count ?? 0),
            'type' => $orderType,
            'status' => $status ?? 'draft',
            'date' => $order->created_at?->format('Y-m-d H:i:s') ?? null,
        ];
    }

    /**
     * Filter history query by perspective (submitted / received).
     */
    private function applyPerspectiveFilter($query, ?string $perspective): void
    {
        if ($perspective === 'submitted') {
            $query->whereIn('status', [OrderStatus::CLOSED, OrderStatus::CANCELED]);
        } elseif ($perspective === 'received') {
            $query->whereIn('status', [OrderStatus::CONFIRMED, OrderStatus::PARTIAL_CONFIRMATION]);
        }
    }

    /**
     * Resolve order type from a label or enum value string, returning null on failure.
     */
    private function resolveOrderType(string $type): ?OrderType
    {
        $orderType = OrderType::fromLabel($type);
        if ($orderType !== null) {
            return $orderType;
        }

        try {
            return OrderType::from($type);
        } catch (\ValueError $e) {
            Log::warning('Invalid order type filter', ['type' => $type]);

            return null;
        }
    }

    /**
     * Filter history query by order type label / enum value.
     */
    private function applyHistoryTypeFilter($query, ?string $type): void
    {
        if (empty($type) || $type === 'all') {
            return;
        }

        $orderType = $this->resolveOrderType($type);
        if ($orderType !== null) {
            $query->byType($orderType);
        }
    }

    /**
     * Apply date filters specific to the history endpoint (supports 'custom' keyword).
     */
    private function applyHistoryDateFilters($query, array $filters): void
    {
        $range = $filters['date_range'] ?? null;

        if ($range === 'custom' || empty($range)) {
            if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
                $query->byDateRange($filters['date_from'] ?? null, $filters['date_to'] ?? null);
            }

            return;
        }

        match ($range) {
            'last_24h' => $query->last24Hours(),
            'last_7d' => $query->last7Days(),
            'last_30d' => $query->last30Days(),
            default => null,
        };
    }

    /**
     * Apply date filters to query
     */
    private function applyDateFilters($query, array $filters): void
    {
        if (! empty($filters['date_range'])) {
            match ($filters['date_range']) {
                'last_24h' => $query->last24Hours(),
                'last_7d' => $query->last7Days(),
                'last_30d' => $query->last30Days(),
                default => null,
            };
        }

        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            $query->byDateRange($filters['date_from'] ?? null, $filters['date_to'] ?? null);
        }
    }

    /**
     * Record order prices in price_histories table.
     * Called when order is confirmed or partially confirmed.
     */
    private function recordOrderPrices(PurchaseOrder $order, ?array $itemConfirmations = null): void
    {
        try {
            $order->load(['items', 'supplier', 'fromBranch']);

            $sourceId = match ($order->order_type) {
                OrderType::DIRECT_SUPPLIER => $order->supplier_id,
                OrderType::INTERNAL_TRANSFER => $order->from_branch_id,
                default => null,
            };
            $sourceName = match ($order->order_type) {
                OrderType::DIRECT_SUPPLIER => $order->supplier?->name,
                OrderType::VIA_PURCHASING_OFFICER => 'Purchasing Officer',
                OrderType::INTERNAL_TRANSFER => $order->fromBranch?->name,
                default => null,
            };

            $deliveryDays = ($order->created_at && $order->confirmed_at)
                ? (int) $order->created_at->diffInDays($order->confirmed_at)
                : null;

            $rating = $order->supplier?->rating ?? null;
            $confirmedItemIds = $itemConfirmations !== null
                ? collect($itemConfirmations)->pluck('item_id')->all()
                : null;

            foreach ($order->items as $item) {
                $this->recordSingleItemPrice($item, $order, $sourceId, $sourceName, $deliveryDays, $rating, $confirmedItemIds);
            }
        } catch (\Exception $e) {
            Log::error('Error recording order prices in price_histories', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Record the price for a single order item, skipping unconfirmed or unlisted items.
     */
    private function recordSingleItemPrice($item, PurchaseOrder $order, $sourceId, ?string $sourceName, ?int $deliveryDays, $rating, ?array $confirmedItemIds): void
    {
        if ($confirmedItemIds !== null && ! in_array($item->id, $confirmedItemIds, true)) {
            return;
        }

        if (! $item->item_id) {
            return;
        }

        $qualityLevel = null;
        if ($item->quality_ordered) {
            try {
                $qualityLevel = is_string($item->quality_ordered)
                    ? QualityLevel::from($item->quality_ordered)
                    : $item->quality_ordered;
            } catch (\ValueError $e) {
                $qualityLevel = null;
            }
        }

        PriceHistory::recordPrice(
            $item->item_id,
            $item->item_name,
            $order->order_type,
            $sourceId,
            $sourceName,
            (float) $item->unit_price,
            $qualityLevel,
            $item->unit_of_measurement ?? 'kg',
            $deliveryDays,
            $rating
        );
    }
}
