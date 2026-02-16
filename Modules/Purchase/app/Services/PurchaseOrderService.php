<?php

namespace Modules\Purchase\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Modules\Purchase\Constants\PurchaseConstants;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\QualityLevel;
use Modules\Purchase\Enums\TimelineEventType;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\OrderTimeline;
use Modules\Purchase\Models\PriceHistory;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Illuminate\Support\Collection;

class PurchaseOrderService implements \Modules\Purchase\Services\Contracts\PurchaseOrderServiceInterface
{
    public function __construct(
        private readonly TimelineService $timelineService,
        private readonly CalculationService $calculationService,
        private readonly OrderCreationService $orderCreationService
    ) {}

    /**
     * Get branch items with filters
     *
     * Supports:
     * - Search by item name
     * - Filter by category/subcategory
     * - Filter by supplier (from Expense module)
     */
    public function getBranchItems(string $branchId, array $filters = [], int $perPage = null): LengthAwarePaginator
    {
        $perPage = $perPage ?? PurchaseConstants::DEFAULT_PER_PAGE;

        // Performance: Eager load only needed item columns
        $query = BranchItem::where('branch_id', $branchId)
            ->with('item:id,name,code,unit,logo,category,subcategory');

        // Search by item name (through Item model)
        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // Filter by category (through Item model)
        if (!empty($filters['category'])) {
            $query->byCategory($filters['category']);
        }

        // Filter by subcategory (through Item model)
        if (!empty($filters['subcategory'])) {
            $query->bySubcategory($filters['subcategory']);
        }

        // Filter by supplier (from Expense module)
        if (!empty($filters['supplier_id'])) {
            $query->bySupplier($filters['supplier_id']);
        }

        // Order by item name through relationship
        return $query->join('items', 'branch_item.item_id', '=', 'items.id')
            ->orderBy('items.name', 'asc')
            ->select('branch_item.*', 'items.name as item_name', 'items.code as item_code', 'items.unit as item_unit', 'items.logo as item_logo', 'items.category', 'items.subcategory')
            ->paginate($perPage);
    }

    /**
     * Get purchase history with filters
     *
     * Filters:
     * - Search: by item name
     * - Perspective: submitted (Close, Canceled) or received (Confirmed, Partial Confirmation)
     * - Type: All, Direct Supplier Order, Via Purchasing Officer, Internal Transfer
     * - Date: Last 24h, Last 7d, Last 30d, or Custom date range
     */
    public function getHistory(array $filters, int $perPage = null): LengthAwarePaginator
    {
        $perPage = $perPage ?? PurchaseConstants::DEFAULT_PER_PAGE;

        // Performance: Eager load only needed columns
        $query = PurchaseOrder::with([
            'items:id,purchase_order_id,item_id,item_name,quantity_ordered,unit_price,total_price',
            'supplier:id,name,phone,email',
            'branch:id,name,lat,lng,opening_hours,closing_hours,image',
            'requestedBy:id,name,email',
            'fromBranch:id,name,lat,lng'
        ])
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
            // Try to get OrderType from label first, then from enum value
            $orderType = OrderType::fromLabel($filters['type']);

            if ($orderType === null) {
                // Fallback to direct enum value conversion
                try {
                    $orderType = OrderType::from($filters['type']);
                } catch (\ValueError $e) {
                    // Invalid type, skip filter
                    Log::warning('Invalid order type filter', ['type' => $filters['type']]);
                    $orderType = null;
                }
            }

            // Apply filter if we have a valid order type
            if ($orderType !== null) {
                $query->byType($orderType);
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
                match ($filters['date_range']) {
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
     * Get orders with filters (all orders for the branch)
     *
     * Returns orders created BY this branch (orders initiated by this branch).
     * For internal_transfer: only include orders where this branch is the destination (to_branch_id = this branch)
     * and this branch created the order (branch_id = this branch).
     * Excludes orders requested FROM this branch by others (those appear in requested_orders).
     */
    public function getOrders(array $filters, int $perPage = null): LengthAwarePaginator
    {
        $perPage = $perPage ?? PurchaseConstants::DEFAULT_PER_PAGE;

        // Performance: Eager load only needed columns
        $query = PurchaseOrder::with([
            'items:id,purchase_order_id,item_id,item_name,quantity_ordered,quantity_confirmed,unit_price,total_price,status,approval_type,approval_data',
            'items.item:id,name,code,logo,unit',
            'supplier:id,name,phone,email',
            'fromBranch:id,name,location'
        ])
            ->orderBy('created_at', 'desc');

        // Filter by branch
        if (!empty($filters['branch_id'])) {
            $branchId = $filters['branch_id'];

            // Get orders created BY this branch (orders where this branch is the requester)
            // For internal_transfer: this branch requested from another branch
            // Condition: to_branch_id = this branch (this branch is the destination - will receive items)
            // AND from_branch_id != this branch (source is a different branch)
            // AND requested_by user belongs to this branch (this branch created the order)
            $query->where('branch_id', $branchId)
                ->where(function ($q) use ($branchId) {
                    // Include all non-internal_transfer orders
                    $q->where('order_type', '!=', OrderType::INTERNAL_TRANSFER)
                        // OR internal_transfer orders where this branch is the destination (will receive items)
                        // AND this branch created the order (requested_by user belongs to this branch)
                        ->orWhere(function ($internalTransferQuery) use ($branchId) {
                            $internalTransferQuery->where('order_type', OrderType::INTERNAL_TRANSFER)
                                ->where('to_branch_id', $branchId) // This branch will receive the items
                                ->where('from_branch_id', '!=', $branchId) // Source is a different branch
                                ->whereHas('requestedBy', function ($userQuery) use ($branchId) {
                                    $userQuery->where('branch_id', $branchId); // This branch created the order
                                });
                        });
                });
        }

        // Search by item name or order number
        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // Filter by order type
        if (!empty($filters['order_type'])) {
            try {
                $orderType = OrderType::from($filters['order_type']);
                $query->byType($orderType);
            } catch (\ValueError $e) {
                Log::warning('Invalid order type filter', ['type' => $filters['order_type']]);
            }
        }

        // Filter by status
        if (!empty($filters['status'])) {
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
    public function getPendingOrders(array $filters, int $perPage = null): LengthAwarePaginator
    {
        $perPage = $perPage ?? PurchaseConstants::DEFAULT_PER_PAGE;

        // Performance: Eager load only needed columns to prevent N+1 queries
        $query = PurchaseOrder::with([
            'items:id,purchase_order_id,item_id,item_name,quantity_ordered,quantity_confirmed,unit_price,total_price,status,approval_type,approval_data',
            'items.item:id,name,code,logo,unit',
            'supplier:id,name,phone,email',
            'branch:id,name,lat,lng,opening_hours,closing_hours,image',
            'requestedBy:id,name,email',
            'fromBranch:id,name,lat,lng'
        ])
            ->pending()
            ->orderBy('created_at', 'desc');

        // Filter by branch - get orders requested FROM this branch
        if (!empty($filters['branch_id'])) {
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
        if (!empty($filters['type'])) {
            $query->byType(OrderType::from($filters['type']));
        }

        if (!empty($filters['status'])) {
            $query->byStatus(OrderStatus::from($filters['status']));
        }

        // Date filters
        $this->applyDateFilters($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * Get orders for receiving grouped by expected delivery date
     * Returns only orders with DELIVERED status
     */
    public function getOrdersForReceiving(array $filters, int $perPage = null): LengthAwarePaginator
    {
        $perPage = $perPage ?? PurchaseConstants::DEFAULT_PER_PAGE;
        $currentPage = request()->get('page', 1);

        $query = PurchaseOrder::withCount('items as items_count');

        // Filter by order type to determine which statuses to include
        $orderType = !empty($filters['type']) ? OrderType::from($filters['type']) : null;

        if ($orderType === OrderType::INTERNAL_TRANSFER) {
            // For Internal Transfer: include orders with statuses that can be received
            // fully_approved, partial_approved, confirmed, partial_confirmation
            $query->whereIn('status', [
                OrderStatus::FULLY_APPROVED,
                OrderStatus::PARTIAL_APPROVED,
                OrderStatus::CONFIRMED,
                OrderStatus::PARTIAL_CONFIRMATION,
            ]);
        } elseif ($orderType !== null) {
            // For other specific order types: only DELIVERED status
            $query->byStatus(OrderStatus::DELIVERED)
                ->whereNotNull('expected_delivery_at');
        } else {
            // If no type filter: include both internal_transfer and other orders
            $query->where(function ($q) {
                // Internal Transfer orders with receivable statuses
                $q->where(function ($internalTransferQuery) {
                    $internalTransferQuery->where('order_type', OrderType::INTERNAL_TRANSFER)
                        ->whereIn('status', [
                            OrderStatus::FULLY_APPROVED,
                            OrderStatus::PARTIAL_APPROVED,
                            OrderStatus::CONFIRMED,
                            OrderStatus::PARTIAL_CONFIRMATION,
                        ]);
                })
                    // Other order types with DELIVERED status
                    ->orWhere(function ($otherOrdersQuery) {
                        $otherOrdersQuery->where('order_type', '!=', OrderType::INTERNAL_TRANSFER)
                            ->where('status', OrderStatus::DELIVERED)
                            ->whereNotNull('expected_delivery_at');
                    });
            });
        }

        // Apply order type filter if provided
        if ($orderType) {
            $query->byType($orderType);
        }

        if (!empty($filters['branch_id'])) {
            $branchId = $filters['branch_id'];

            if ($orderType === OrderType::INTERNAL_TRANSFER) {
                // For Internal Transfer: filter by to_branch_id (the receiving branch)
                // This is the branch that will receive the order
                $query->where('to_branch_id', $branchId);
            } elseif ($orderType === null) {
                // If no type filter: check both internal_transfer (to_branch_id) and others (branch_id)
                $query->where(function ($q) use ($branchId) {
                    $q->where(function ($internalTransferQuery) use ($branchId) {
                        $internalTransferQuery->where('order_type', OrderType::INTERNAL_TRANSFER)
                            ->where('to_branch_id', $branchId);
                    })
                        ->orWhere(function ($otherOrdersQuery) use ($branchId) {
                            $otherOrdersQuery->where('order_type', '!=', OrderType::INTERNAL_TRANSFER)
                                ->where('branch_id', $branchId);
                        });
                });
            } else {
                // For other order types: filter by branch_id (the requesting branch)
                $query->byBranch($branchId);
            }
        }

        // Order by expected_delivery_at for non-internal-transfer, or created_at for internal-transfer
        if ($orderType === OrderType::INTERNAL_TRANSFER) {
            $query->orderBy('created_at', 'desc');
        } elseif ($orderType === null) {
            // If no type filter: order internal_transfer by created_at, others by expected_delivery_at
            // This is handled in the grouping logic, so just order by created_at for all
            $query->orderBy('created_at', 'desc');
        } else {
            $query->orderBy('expected_delivery_at', 'asc')
                ->orderBy('created_at', 'desc');
        }

        $orders = $query->get();

        // Transform orders to the required format (flattened, not grouped)
        $transformedOrders = $orders->map(function ($order) {
            $orderType = null;
            try {
                if ($order->order_type) {
                    $orderTypeValue = $order->order_type;
                    if ($orderTypeValue instanceof \BackedEnum) {
                        $orderType = $orderTypeValue->value;
                    } elseif (is_string($orderTypeValue)) {
                        $orderType = $orderTypeValue;
                    }
                }
            } catch (\Exception $e) {
                $orderType = null;
            }

            $status = null;
            try {
                if ($order->status) {
                    $statusValue = $order->status;
                    if ($statusValue instanceof \BackedEnum) {
                        $status = $statusValue->value;
                    } elseif (is_string($statusValue)) {
                        $status = $statusValue;
                    }
                }
            } catch (\Exception $e) {
                $status = null;
            }

            return [
                'id' => $order->id,
                'items_count' => (int) ($order->items_count ?? 0),
                'type' => $orderType,
                'status' => $status ?? 'draft',
                'date' => $order->created_at?->format('Y-m-d H:i:s') ?? null,
            ];
        })->values();

        // Create paginator for the flattened orders
        $total = $transformedOrders->count();
        $items = $transformedOrders->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $currentPage,
            [
                'path' => request()->url(),
                'pageName' => 'page',
            ]
        );
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

            // Allow explicit sourceable (e.g. from recurring order where officer != requested_by)
            if (!empty($data['sourceable_type']) && !empty($data['sourceable_id'])) {
                $sourceableType = $data['sourceable_type'];
                $sourceableId = $data['sourceable_id'];
            } elseif ($orderType === OrderType::DIRECT_SUPPLIER) {
                $sourceableType = \Modules\Supplier\Models\Supplier::class;
                $sourceableId = $data['supplier_id'] ?? null;
                if (!$sourceableId) {
                    throw new \InvalidArgumentException('Supplier ID is required for direct supplier orders');
                }
            } elseif ($orderType === OrderType::VIA_PURCHASING_OFFICER) {
                // For purchasing officer orders, use the branch manager as source (or explicit sourceable from recurring)
                $sourceableType = \Modules\BranchManagers\Models\BranchManager::class;
                $sourceableId = $data['sourceable_id'] ?? $data['requested_by'] ?? null;
                if (!$sourceableId) {
                    throw new \InvalidArgumentException('Requested by (Branch Manager ID) or sourceable is required');
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
     * Create multiple orders from different sources
     *
     * Supports:
     * - branches[]: Internal transfers from multiple branches
     * - direct_supplier[]: Direct supplier orders
     * - purchase_officer[]: Purchasing officer orders
     *
     * @param array $data Request data containing branches, direct_supplier, and/or purchase_officer arrays
     * @param string $branchId The branch ID for the orders
     * @param string $requestedBy The user ID who requested the orders
     * @param bool $isDraft Whether to create orders as draft (true) or pending (false)
     * @return Collection Collection of created PurchaseOrder models
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

        if (empty($data['branches']) || !is_array($data['branches'])) {
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
                throw new \Exception("Failed to create internal transfer order at index {$index}: " . $e->getMessage(), 0, $e);
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

        if (empty($data['direct_supplier']) || !is_array($data['direct_supplier'])) {
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
                throw new \Exception("Failed to create direct supplier order at index {$index}: " . $e->getMessage(), 0, $e);
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

        if (empty($data['purchase_officer']) || !is_array($data['purchase_officer'])) {
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
                throw new \Exception("Failed to create purchasing officer order at index {$index}: " . $e->getMessage(), 0, $e);
            }
        }

        return $orders;
    }

    /**
     * Add item to order
     *
     * Gets item details from Item model (new structure) using item_id
     *
     * @param PurchaseOrder $order
     * @param array $data
     * @return PurchaseOrderItem
     * @throws \InvalidArgumentException
     */
    public function addItem(PurchaseOrder $order, array $data): PurchaseOrderItem
    {
        // Validate required fields
        if (empty($data['item_id'])) {
            throw new \InvalidArgumentException('Item ID is required');
        }

        if (!isset($data['quantity']) || $data['quantity'] <= 0) {
            throw new \InvalidArgumentException('Quantity is required and must be greater than 0');
        }

        // Get item details from Item model (new structure)
        $item = Item::find($data['item_id']);
        if (!$item) {
            throw new \InvalidArgumentException("Item with ID {$data['item_id']} not found");
        }

        // Get BranchItem for current branch to get price and additional data (if exists)
        $branchItem = BranchItem::where('branch_id', $order->branch_id)
            ->where('item_id', $item->id)
            ->with('item') // Eager load item relationship
            ->first();

        // For internal transfers, unit_price is optional (defaults to 0 - free transfer)
        // For other order types, unit_price should be provided or use price from BranchItem
        $unitPrice = $data['unit_price'] ?? ($branchItem?->price ?? 0);

        // Ensure unit_price is numeric
        $unitPrice = is_numeric($unitPrice) ? (float) $unitPrice : 0;

        // Ensure quantity is numeric
        $quantity = is_numeric($data['quantity']) ? (float) $data['quantity'] : 0;

        // Calculate total price
        $discount = isset($data['discount']) && is_numeric($data['discount']) ? (float) $data['discount'] : 0;
        $totalPrice = ($quantity * $unitPrice) - $discount;

        // Handle item_logo - can be array or string
        $itemLogo = $item->logo;
        if (is_array($itemLogo)) {
            $itemLogo = $itemLogo[0] ?? null;
        }

        // Validate and normalize quality value
        $quality = $this->normalizeQualityLevel($data['quality'] ?? null);

        // Validate unit_of_measurement value
        // Try to get unit from: data -> Item -> default 'kg'
        $allowedUnits = ['kg', 'pk', 'unit', 'box', 'liter', 'piece'];
        $unit = null;
        if (!empty($data['unit']) && in_array($data['unit'], $allowedUnits)) {
            $unit = $data['unit'];
        } elseif (!empty($item->unit) && in_array($item->unit, $allowedUnits)) {
            $unit = $item->unit;
        } else {
            $unit = 'kg'; // Default to 'kg'
        }

        // Get category and subcategory from Item (with fallback)
        $category = $item->category
            ?? ($branchItem && $branchItem->item ? $branchItem->item->category : null);
        $subcategory = $item->subcategory
            ?? ($branchItem && $branchItem->item ? $branchItem->item->subcategory : null);

        try {
            return PurchaseOrderItem::create([
                'purchase_order_id' => $order->id,
                'item_id' => $item->id, // Item.id (new structure)
                'item_name' => $item->name ?? 'Unknown Item',
                'item_logo' => $itemLogo,
                'item_sku' => $item->code ?? null,
                'category' => $category,
                'subcategory' => $subcategory,
                'quantity_ordered' => $quantity,
                'original_quantity' => $quantity, // Set original_quantity to quantity_ordered
                'new_quantity' => $quantity, // Set new_quantity to quantity_ordered initially
                'unit_of_measurement' => $unit ?: 'kg', // Ensure unit is always set (never null)
                'unit_price' => $unitPrice,
                'total_price' => max(0, $totalPrice), // Ensure total_price is not negative
                'discount' => $discount,
                'quality_ordered' => $quality,
                'available_in_source' => $data['available_in_source'] ?? null,
                'daily_consumption' => $data['daily_consumption'] ?? null,
                'weekend_forecast' => $data['weekend_forecast'] ?? null,
                'next_supply_date' => $data['next_supply_date'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
                'cooling_status' => $data['cooling_status'] ?? null,
                'is_alternative' => $data['is_alternative'] ?? false,
                'is_gift' => $data['is_gift'] ?? false,
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating purchase order item', [
                'error' => $e->getMessage(),
                'order_id' => $order->id,
                'item_id' => $data['item_id'],
                'item_data' => $data,
            ]);
            throw new \Exception("Failed to create order item: " . $e->getMessage(), 0, $e);
        }
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
    public function confirmOrder(PurchaseOrder $order, ?array $itemConfirmations = null, ?string $readyTime = null): bool
    {
        return DB::transaction(function () use ($order, $itemConfirmations, $readyTime) {
            // Check if order is in decision phase
            if (!$order->status->isDecisionPhase() || $order->status === OrderStatus::CONFIRMED) {
                throw new \InvalidArgumentException(
                    "Cannot approve order. Current status: {$order->status?->value}. Order must be in pending status."
                );
            }

            // If itemConfirmations provided, confirm specific items
            if ($itemConfirmations) {
                foreach ($itemConfirmations as $confirmation) {
                    $item = $order->items()->find($confirmation['item_id']);
                    if ($item) {
                        $quantity = $confirmation['quantity'] ?? $item->quantity_ordered;
                        $item->confirm($quantity);
                    }
                }
            } else {
                // Confirm all pending items
                $order->items()
                    ->where('status', OrderItemStatus::PENDING)
                    ->get()
                    ->each->confirm();
            }

            // Update ready_time if provided
            if ($readyTime !== null) {
                $order->update(['ready_time' => $readyTime]);
            }

            // Check and transition to CONFIRMED if all items are decided
            $order->checkAndTransitionToConfirmed();

            // If order is now confirmed, log and record prices
            if ($order->fresh()->status === OrderStatus::CONFIRMED) {
                $this->timelineService->logOrderConfirmed($order);
                $this->recordOrderPrices($order);
            }

            return true;
        });
    }

    /**
     * Partially confirm order (confirm specific items only)
     */
    public function partialConfirmOrder(PurchaseOrder $order, array $itemConfirmations, ?string $readyTime = null): bool
    {
        return DB::transaction(function () use ($order, $itemConfirmations, $readyTime) {
            // Check if order is in decision phase
            if (!$order->status->isDecisionPhase() || $order->status === OrderStatus::CONFIRMED) {
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
        if (!$order->reject($reason)) {
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
        if (!$order->cancel($reason, $byBranch, $bySupplier)) {
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
        return DB::transaction(function () use ($order, $reason, $newDeliveryDate) {
            if ($newDeliveryDate) {
                $order->expected_delivery_at = $newDeliveryDate;
            }

            if (!$order->reportDelay($reason)) {
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
     * Approve item request (branch manager approves supplier's request for specific item)
     *
     * @param PurchaseOrder $order
     * @param string $itemId
     * @param array|null $additionalData Optional data like new_delivery_time
     * @return bool
     * @throws \InvalidArgumentException
     */
    public function approveItemRequest(PurchaseOrder $order, string $itemId, ?array $additionalData = null): bool
    {
        $item = $order->items()->where('item_id', $itemId)->first();

        if (!$item) {
            throw new \InvalidArgumentException('Item not found in order');
        }

        if (!$item->status->needsApproval() && !in_array($item->status, [
            OrderItemStatus::PARTIAL_CONFIRMATION,
            OrderItemStatus::PARTIAL
        ])) {
            throw new \InvalidArgumentException('Item must be in needs approval status to approve request');
        }

        return DB::transaction(function () use ($item, $additionalData, $order) {
            $item->approveRequest($additionalData);
            $item->save();

            // Refresh order to get latest items status
            $order->refresh();
            $order->load('items');

            // Check if all items are now confirmed/rejected, update order status accordingly
            $order->checkAndTransitionToConfirmed();

            // Log timeline event
            $this->timelineService->logItemApproved($order, $item);

            return true;
        });
    }

    /**
     * Reject item request (branch manager rejects supplier's request for specific item)
     *
     * @param PurchaseOrder $order
     * @param string $itemId
     * @param string|null $reason
     * @return bool
     * @throws \InvalidArgumentException
     */
    public function rejectItemRequest(PurchaseOrder $order, string $itemId, ?string $reason = null): bool
    {
        $item = $order->items()->where('item_id', $itemId)->first();

        if (!$item) {
            throw new \InvalidArgumentException('Item not found in order');
        }

        if (!$item->status->needsApproval() && !in_array($item->status, [
            OrderItemStatus::PARTIAL_CONFIRMATION,
            OrderItemStatus::PARTIAL
        ])) {
            throw new \InvalidArgumentException('Item must be in needs approval status to reject request');
        }

        return DB::transaction(function () use ($item, $reason, $order) {
            $item->rejectRequest($reason);
            $item->save();

            // Refresh order to get latest items status
            $order->refresh();
            $order->load('items');

            // Check if all items are now confirmed/rejected, update order status accordingly
            $order->checkAndTransitionToConfirmed();

            // Log timeline event
            $this->timelineService->logItemRejected($order, $item, $reason);

            return true;
        });
    }

    /**
     * Cancel item (branch manager cancels specific item)
     *
     * cancelByBranch will automatically check if item has approval_type:
     * - If yes: sets status to CANCELED_MODIFICATION
     * - Otherwise: sets status to CANCELLED_BY_BRANCH
     *
     * @param PurchaseOrder $order
     * @param string $itemId
     * @param string|null $reason
     * @return bool
     * @throws \InvalidArgumentException
     */
    public function cancelItem(PurchaseOrder $order, string $itemId, ?string $reason = null): bool
    {
        $item = $order->items()->where('item_id', $itemId)->first();

        if (!$item) {
            throw new \InvalidArgumentException('Item not found in order');
        }

        if ($item->status->isCancelled()) {
            throw new \InvalidArgumentException('Item is already cancelled');
        }

        return DB::transaction(function () use ($item, $reason, $order) {
            // Cancel item by branch
            // cancelByBranch will automatically check for approval_type and set appropriate status
            $item->cancelByBranch($reason);

            // Refresh order to get latest items status
            $order->refresh();
            $order->load('items');

            // Check if all items are now cancelled/confirmed/rejected, update order status accordingly
            $order->checkAndTransitionToConfirmed();

            // Log timeline event
            $this->timelineService->logItemRejected($order, $item, $reason ?? 'Item cancelled by branch manager');

            return true;
        });
    }

    /**
     * Approve delay report for the entire order
     * Branch manager approves supplier's delay request for all delayed items
     *
     * @param PurchaseOrder $order
     * @return bool
     */
    public function approveOrderDelay(PurchaseOrder $order): bool
    {
        // Check if order is in delayed status
        if ($order->status !== OrderStatus::DELAYED) {
            throw new \InvalidArgumentException('Order is not in delayed status');
        }

        return DB::transaction(function () use ($order) {
            // Load items to get latest status
            $order->load('items');

            // Get all items in delayed status
            $delayedItems = $order->items()->whereIn('status', [
                OrderItemStatus::DELAYED_SUPPLIER,
                OrderItemStatus::DELAYED_BRANCH,
                OrderItemStatus::DELAYED, // For backward compatibility
            ])->get();

            if ($delayedItems->isEmpty()) {
                throw new \InvalidArgumentException('No delayed items found in order');
            }

            // Update all delayed items to delayed_approved
            foreach ($delayedItems as $item) {
                $item->status = OrderItemStatus::DELAYED_APPROVED;
                $item->save();

                // Log timeline event for each item
                $this->timelineService->logItemDelayApproved($order, $item);
            }

            // Update order status to delayed_approved
            $order->transitionTo(OrderStatus::DELAYED_APPROVED);

            return true;
        });
    }

    /**
     * Approve delay request (Branch Manager accepts) → status: delayed_confirmed, Track available
     *
     * @param PurchaseOrder $order
     * @return bool
     */
    public function approveDelayRequest(PurchaseOrder $order): bool
    {
        if ($order->status !== OrderStatus::DELAYED) {
            throw new \InvalidArgumentException('Order is not in delayed status');
        }

        return DB::transaction(function () use ($order) {
            $order->load('items');

            $delayedItems = $order->items()->whereIn('status', [
                OrderItemStatus::DELAYED_SUPPLIER,
                OrderItemStatus::DELAYED_BRANCH,
                OrderItemStatus::DELAYED,
            ])->get();

            if ($delayedItems->isEmpty()) {
                throw new \InvalidArgumentException('No delayed items found in order');
            }

            foreach ($delayedItems as $item) {
                $item->status = OrderItemStatus::DELAYED_CONFIRMED;
                $item->save();
                $this->timelineService->logItemDelayApproved($order, $item);
            }

            $order->transitionTo(OrderStatus::DELAYED_CONFIRMED);

            return true;
        });
    }

    /**
     * Reject delay request (Branch Manager rejects) → status: delayed_canceled, moves to Purchase History
     *
     * @param PurchaseOrder $order
     * @param string|null $reason
     * @return bool
     */
    public function rejectDelayRequest(PurchaseOrder $order, ?string $reason = null): bool
    {
        if ($order->status !== OrderStatus::DELAYED) {
            throw new \InvalidArgumentException('Order is not in delayed status');
        }

        return DB::transaction(function () use ($order, $reason) {
            $order->cancellation_reason = $reason;
            $order->save();

            $order->load('items');
            $delayedItems = $order->items()->whereIn('status', [
                OrderItemStatus::DELAYED_SUPPLIER,
                OrderItemStatus::DELAYED_BRANCH,
                OrderItemStatus::DELAYED,
            ])->get();

            if (!$delayedItems->isEmpty()) {
                foreach ($delayedItems as $item) {
                    $item->status = OrderItemStatus::DELAYED_CANCELED;
                    $approvalData = $item->approval_data ?? [];
                    $approvalData['cancellation_reason'] = $reason;
                    $item->approval_data = $approvalData;
                    $item->save();
                    $this->timelineService->logItemDelayRejected($order, $item, $reason ?? 'Delay request rejected by branch manager');
                }
            }

            $order->transitionTo(OrderStatus::DELAYED_CANCELED);

            return true;
        });
    }

    /**
     * Reject delay report for the entire order
     * Branch manager rejects supplier's delay request and cancels all delayed items
     *
     * @param PurchaseOrder $order
     * @param string|null $reason
     * @return bool
     */
    public function rejectOrderDelay(PurchaseOrder $order, ?string $reason = null): bool
    {
        // Check if order is in delayed status
        if ($order->status !== OrderStatus::DELAYED) {
            throw new \InvalidArgumentException('Order is not in delayed status');
        }

        return DB::transaction(function () use ($order, $reason) {
            // Load items to get latest status
            $order->load('items');

            // Get all items in delayed status
            $delayedItems = $order->items()->whereIn('status', [
                OrderItemStatus::DELAYED_SUPPLIER,
                OrderItemStatus::DELAYED_BRANCH,
                OrderItemStatus::DELAYED, // For backward compatibility
            ])->get();

            if ($delayedItems->isEmpty()) {
                throw new \InvalidArgumentException('No delayed items found in order');
            }

            // Update all delayed items to delayed_canceled (same as order status)
            foreach ($delayedItems as $item) {
                $item->status = OrderItemStatus::DELAYED_CANCELED;

                // Store cancellation reason in approval_data
                $approvalData = $item->approval_data ?? [];
                $approvalData['cancellation_reason'] = $reason;
                $item->approval_data = $approvalData;

                $item->save();

                // Log timeline event for each item
                $this->timelineService->logItemDelayRejected($order, $item, $reason ?? 'Delay request rejected by branch manager');
            }

            $order->transitionTo(OrderStatus::DELAYED_CANCELED);

            return true;
        });
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
     * Get order details
     *
     * Security: Optionally filter by branch_id to ensure user has access
     * For internal transfers, also checks from_branch_id for authorization
     *
     * @param string $orderId
     * @param string|null $branchId Optional branch ID for authorization check
     * @return PurchaseOrder|null
     */
    public function getOrderDetails(string $orderId, ?string $branchId = null): ?PurchaseOrder
    {
        // First, try to find the order by ID
        $order = PurchaseOrder::with([
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

        if (!$order) {
            return null;
        }

        // Security: Check authorization if branch_id is provided
        if ($branchId !== null) {
            // Regular orders: user must belong to the order's branch
            $hasAccess = $order->branch_id === $branchId;

            // Internal transfers: user can also access if they belong to from_branch_id (sending branch)
            if (!$hasAccess && $order->order_type === OrderType::INTERNAL_TRANSFER) {
                $hasAccess = $order->from_branch_id === $branchId;
            }

            if (!$hasAccess) {
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
            ->orderBy('occurred_at', 'desc')
            ->get();
    }

    /**
     * Apply date filters to query
     */
    private function applyDateFilters($query, array $filters): void
    {
        if (!empty($filters['date_range'])) {
            match ($filters['date_range']) {
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

    /**
     * Record order prices in price_histories table
     * Called when order is confirmed or partially confirmed
     *
     * @param PurchaseOrder $order
     * @param array|null $itemConfirmations Optional: only record confirmed items if provided
     * @return void
     */
    private function recordOrderPrices(PurchaseOrder $order, ?array $itemConfirmations = null): void
    {
        try {
            // Load order items with relationships
            $order->load(['items', 'supplier', 'fromBranch']);

            // Determine source information based on order type
            $sourceId = match ($order->order_type) {
                OrderType::DIRECT_SUPPLIER => $order->supplier_id,
                OrderType::VIA_PURCHASING_OFFICER => null, // Purchasing officer doesn't have a specific ID
                OrderType::INTERNAL_TRANSFER => $order->from_branch_id,
                default => null,
            };

            $sourceName = match ($order->order_type) {
                OrderType::DIRECT_SUPPLIER => $order->supplier?->name,
                OrderType::VIA_PURCHASING_OFFICER => 'Purchasing Officer',
                OrderType::INTERNAL_TRANSFER => $order->fromBranch?->name,
                default => null,
            };

            // Calculate delivery days (from created_at to confirmed_at)
            $deliveryDays = null;
            if ($order->created_at && $order->confirmed_at) {
                $deliveryDays = (int) $order->created_at->diffInDays($order->confirmed_at);
            }

            // Get rating from supplier if available
            $rating = $order->supplier?->rating ?? null;

            // Record price for each item
            foreach ($order->items as $item) {
                // If itemConfirmations provided, only record confirmed items
                if ($itemConfirmations !== null) {
                    $isConfirmed = collect($itemConfirmations)->contains(function ($confirmation) use ($item) {
                        return ($confirmation['item_id'] ?? null) === $item->id;
                    });
                    if (!$isConfirmed) {
                        continue; // Skip unconfirmed items
                    }
                }

                // Skip if item doesn't have item_id (unlisted items)
                if (!$item->item_id) {
                    continue;
                }

                // Convert quality_ordered string to QualityLevel enum if needed
                $qualityLevel = null;
                if ($item->quality_ordered) {
                    try {
                        $qualityLevel = is_string($item->quality_ordered)
                            ? QualityLevel::from($item->quality_ordered)
                            : $item->quality_ordered;
                    } catch (\ValueError $e) {
                        // Invalid quality level, skip
                        $qualityLevel = null;
                    }
                }

                // Record price in price_histories
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
        } catch (\Exception $e) {
            // Log error but don't fail the order confirmation
            Log::error('Error recording order prices in price_histories', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Normalize quality level value to enum or null
     *
     * Validates and converts quality level string to QualityLevel enum.
     * Returns null if value is invalid or empty.
     *
     * @param string|null $qualityValue
     * @return QualityLevel|null
     */
    private function normalizeQualityLevel(?string $qualityValue): ?QualityLevel
    {
        if (empty($qualityValue)) {
            return null;
        }

        $normalizedValue = strtolower(trim($qualityValue));
        $allowedValues = ['economy', 'standard', 'premium'];

        if (!in_array($normalizedValue, $allowedValues)) {
            Log::warning('Invalid quality level value provided, setting to null', [
                'provided_quality' => $qualityValue,
                'normalized_value' => $normalizedValue,
            ]);
            return null;
        }

        try {
            return QualityLevel::from($normalizedValue);
        } catch (\ValueError $e) {
            Log::warning('Failed to convert quality level to enum, setting to null', [
                'provided_quality' => $qualityValue,
                'normalized_value' => $normalizedValue,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
