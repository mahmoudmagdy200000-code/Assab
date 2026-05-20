<?php

namespace Modules\Purchase\Services;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Constants\PurchaseConstants;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\SupplierItem;
use Modules\Purchase\Traits\ItemHelperTrait;
use Modules\Purchase\Transformers\PurchaseOrderListResource;
use Modules\Purchase\Transformers\PurchasingOfficerItemResource;
use Modules\Purchase\Transformers\SupplierItemResource;
use Modules\Purchase\Transformers\SupplierResource;
use Modules\Purchase\Transformers\TransferItemResource;
use Modules\Supplier\Models\Supplier;
use Modules\Supplier\Models\SupplierProduct;

class OrderDataService
{
    use ItemHelperTrait;

    public function __construct(
        private readonly PurchaseOrderService $orderService,
        private readonly PriceComparisonService $priceService
    ) {}

    /**
     * Get orders list with inventory data
     */
    public function getOrdersList(array $filters, ?int $perPage = null): array
    {
        $perPage = $perPage ?? PurchaseConstants::DEFAULT_PER_PAGE;
        $orders = $this->orderService->getOrders($filters, $perPage);
        $requestedOrders = $this->orderService->getPendingOrders($filters, $perPage);

        // Get pagination info from orders paginator
        $paginator = $orders;
        $currentPage = $paginator->currentPage();
        $lastPage = $paginator->lastPage();

        // Build Laravel-style page links array
        $pageLinks = [];
        for ($i = 1; $i <= $lastPage; $i++) {
            $pageLinks[] = [
                'url' => $paginator->url($i),
                'label' => (string) $i,
                'active' => $i === $currentPage,
            ];
        }

        $links = [
            'first' => $paginator->url(1),
            'last' => $paginator->url($lastPage),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];

        $meta = [
            'current_page' => $currentPage,
            'from' => $paginator->firstItem(),
            'last_page' => $lastPage,
            'links' => $pageLinks,
            'path' => $paginator->path(),
            'per_page' => $paginator->perPage(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
        ];

        // Get branch_id for inventory lookup
        $branchId = $filters['branch_id'] ?? null;

        // Performance: Batch load inventory data for all items in all orders
        $allItemIds = $orders->flatMap(function ($order) {
            return $order->relationLoaded('items')
                ? $order->items->pluck('item_id')->filter()
                : collect();
        })->merge(
            $requestedOrders->flatMap(function ($order) {
                return $order->relationLoaded('items')
                    ? $order->items->pluck('item_id')->filter()
                    : collect();
            })
        )->unique();

        // Load all inventory records in one query
        $inventoryMap = [];
        if ($branchId && $allItemIds->isNotEmpty()) {
            $inventories = BranchInventory::where('branch_id', $branchId)
                ->whereIn('item_id', $allItemIds->toArray())
                ->get()
                ->keyBy('item_id');

            foreach ($inventories as $inventory) {
                $inventoryMap[$inventory->item_id] = $inventory;
            }
        }

        // Transform orders with request_type, branch_id, and inventory_map
        $ordersCollection = PurchaseOrderListResource::collection($orders);
        foreach ($ordersCollection->collection as $resource) {
            $resource->additional([
                'request_type' => 'order',
                'branch_id' => $branchId,
                'inventory_map' => $inventoryMap,
            ]);
        }

        // Transform requested orders with request_type, branch_id, and inventory_map
        $requestedOrdersCollection = PurchaseOrderListResource::collection($requestedOrders);
        foreach ($requestedOrdersCollection->collection as $resource) {
            $resource->additional([
                'request_type' => 'request',
                'branch_id' => $branchId,
                'inventory_map' => $inventoryMap,
            ]);
        }

        return [
            'data' => [
                'orders' => $ordersCollection,
                'requested_orders' => $requestedOrdersCollection,
            ],
            'links' => $links,
            'meta' => $meta,
        ];
    }

    /**
     * Get order summary
     */
    public function getOrderSummary(string $id, string $branchId): ?PurchaseOrder
    {
        // Performance optimization: Use select to limit columns and eager load relationships
        return PurchaseOrder::with([
            'items:id,purchase_order_id,item_id,item_name,item_logo,quantity_ordered,quality_ordered,unit_price,total_price,available_in_source,remaining_balance,expiry_date,cooling_status',
            'supplier', // Load all supplier columns to access image_url accessor
            'branch:id,name,lat,lng,opening_hours,closing_hours,image',
            'fromBranch:id,name,lat,lng',
            'requestedBy:id,name,email',
        ])->select([
            'id',
            'order_number',
            'order_type',
            'status',
            'branch_id',
            'supplier_id',
            'from_branch_id',
            'requested_by',
            'total_amount',
            'message',
            'notification_channels',
            'priority',
            'preferred_delivery_date',
            'latest_delivery_date',
            'special_instructions',
            'transport_method',
            'estimated_transport_hours',
            'driver_name',
            'temperature',
            'created_at',
            'updated_at',
        ])->where('branch_id', $branchId)
            ->find($id);
    }

    /**
     * Get transfer items with inventory and transport details
     */
    public function getTransferItems(array $validated, string $toBranchId): array
    {
        $fromBranchId = $validated['branch_id'];
        $perPage = $validated['per_page'] ?? PurchaseConstants::DEFAULT_PER_PAGE;

        // Get transport details once (same for all items)
        $transportDetails = $this->calculateTransportDetails($fromBranchId, $toBranchId);

        // Step 1: Get Item.id from request using helper method
        $requestedItemId = $validated['item_id'] ?? null;
        $targetItemId = $this->resolveItemId($requestedItemId);

        // Step 2: Get BranchInventory records from fromBranch that have stock
        // Performance: Eager load only needed item columns
        $inventoryQuery = BranchInventory::where('branch_id', $fromBranchId)
            ->whereColumn('available_quantity', '>', 'reserved_quantity')
            ->with(['item:id,name,code,logo,unit,category,subcategory']);

        // Filter by specific Item.id if provided
        if ($targetItemId) {
            $inventoryQuery->where('item_id', $targetItemId);
        }

        $inventories = $inventoryQuery->get();

        // Step 3: Get Item IDs from inventories
        $itemIds = $inventories->pluck('item_id')->unique()->toArray();

        if (empty($itemIds)) {
            // Return empty collection if no items with stock found
            $items = new LengthAwarePaginator(
                collect([]),
                0,
                $perPage,
                1
            );
            $itemsCollection = collect([]);
        } else {
            // Step 4: Get Items that have inventory (stock)
            $query = Item::whereIn('id', $itemIds)
                ->where('is_active', true);

            // Apply filters
            if (! empty($validated['search'])) {
                $query->where(function ($q) use ($validated) {
                    $q->where('name', 'like', '%'.$validated['search'].'%')
                        ->orWhere('code', 'like', '%'.$validated['search'].'%');
                });
            }

            if (! empty($validated['category'])) {
                $query->byCategory($validated['category']);
            }

            if (! empty($validated['subcategory'])) {
                $query->bySubcategory($validated['subcategory']);
            }

            $items = $query->paginate($perPage);
            $itemsCollection = $items->getCollection();
        }

        // Step 5: Load all inventories in batch (performance optimization)
        $itemIdsForInventory = $itemsCollection->pluck('id')->toArray();

        // Load from inventories
        $fromInventories = BranchInventory::where('branch_id', $fromBranchId)
            ->whereIn('item_id', $itemIdsForInventory)
            ->get()
            ->keyBy('item_id');

        // Load to inventories
        $toInventories = BranchInventory::where('branch_id', $toBranchId)
            ->whereIn('item_id', $itemIdsForInventory)
            ->get()
            ->keyBy('item_id');

        // Step 6: Transform items for response
        $transformedItems = $itemsCollection->map(function ($item) use (
            $fromInventories,
            $toInventories,
            $transportDetails
        ) {
            $fromInventory = $fromInventories[$item->id] ?? null;
            $toInventory = $toInventories[$item->id] ?? null;

            return [
                'item' => $item, // Item model (central)
                'from_inventory' => $fromInventory,
                'to_inventory' => $toInventory,
                'transport_details' => $transportDetails,
                'requested_item_id' => $item->id, // Item.id (consistent with getBranches)
            ];
        });

        // Step 7: Create ResourceCollection
        $resourceCollection = TransferItemResource::collection($transformedItems);
        if (isset($items)) {
            $resourceCollection->resource = $items;
        }

        return [
            'data' => $resourceCollection,
            'transport_summary' => $transportDetails,
        ];
    }

    /**
     * Get direct supplier items with prices
     */
    public function getDirectSupplierItems(array $validated, string $branchId): array
    {
        $itemId = $validated['item_id'];
        $quantity = isset($validated['quantity']) ? (float) $validated['quantity'] : 1.0;

        // Get branch item (item_id is Item.id, not BranchItem.id)
        $branchItem = BranchItem::where('branch_id', $branchId)
            ->where('item_id', $itemId)
            ->first();

        if (! $branchItem) {
            throw new \InvalidArgumentException('Item not found in your branch');
        }

        // Prepare filters
        $filters = [
            'status' => $validated['status'] ?? null,
            'max_delivery_hours' => isset($validated['max_delivery_hours']) ? (int) $validated['max_delivery_hours'] : null,
            'max_distance_km' => isset($validated['max_distance_km']) ? (float) $validated['max_distance_km'] : null,
            'search' => $validated['search'] ?? null,
        ];

        // Get all supplier products for this item (new system) or supplier items (legacy)
        // Try SupplierProduct first (new system in Supplier module)
        $supplierProducts = SupplierProduct::where('item_id', $itemId)
            ->with('supplier')
            ->available()
            ->whereHas('supplier', fn ($q) => $q->active());

        // Apply status filter
        if (! empty($filters['status'])) {
            $statusEnum = \Modules\Purchase\Enums\SupplierStatus::from($filters['status']);
            $supplierProducts->whereHas('supplier', fn ($q) => $q->byStatus($statusEnum));
        }

        // Apply filters
        if (! empty($filters['max_delivery_hours'])) {
            $supplierProducts->where('delivery_hours', '<=', $filters['max_delivery_hours']);
        }

        if (! empty($filters['search'])) {
            $supplierProducts->whereHas('supplier', fn ($q) => $q->search($filters['search']));
        }

        $supplierProducts = $supplierProducts->get();

        // If no products found, try legacy SupplierItem
        if ($supplierProducts->isEmpty()) {
            $query = SupplierItem::where('item_id', $itemId)
                ->with('supplier')
                ->available()
                ->whereHas('supplier', fn ($q) => $q->active());

            // Apply status filter
            if (! empty($filters['status'])) {
                $statusEnum = \Modules\Purchase\Enums\SupplierStatus::from($filters['status']);
                $query->whereHas('supplier', fn ($q) => $q->byStatus($statusEnum));
            }

            // Apply filters
            if (! empty($filters['max_delivery_hours'])) {
                $query->byDeliveryTime($filters['max_delivery_hours']);
            }

            if (! empty($filters['search'])) {
                $query->whereHas('supplier', fn ($q) => $q->search($filters['search']));
            }

            $supplierItems = $query->get();
        } else {
            // Convert SupplierProduct to SupplierItem-like structure for compatibility
            $supplierItems = $supplierProducts->map(function ($product) {
                return (object) [
                    'id' => $product->id,
                    'supplier_id' => $product->supplier_id,
                    'item_id' => $product->item_id,
                    'unit_price' => $product->unit_price,
                    'economy_price' => $product->economy_price,
                    'standard_price' => $product->standard_price,
                    'premium_price' => $product->premium_price,
                    'is_available' => $product->is_available,
                    'min_order_quantity' => $product->min_order_quantity,
                    'max_order_quantity' => $product->max_order_quantity,
                    'delivery_hours' => $product->delivery_hours,
                    'rating' => $product->rating,
                    'supplier' => $product->supplier,
                ];
            });
        }

        // Get item logo URL using helper method
        $itemLogo = $this->getItemLogoUrl($branchItem->item_logo);

        // Get current branch for distance calculation
        $currentBranch = Branch::find($branchId);
        $currentCoordinates = $this->parseCoordinates($currentBranch->map_coordinates ?? null);

        // Build suppliers list with structure similar to getBranches
        $suppliersList = collect($supplierItems)->map(function ($supplierItem) use (
            $branchItem,
            $itemId,
            $quantity,
            $itemLogo,
            $filters,
            $currentCoordinates
        ) {
            $supplier = $supplierItem->supplier;
            if (! $supplier) {
                return null;
            }

            // Calculate distance (if supplier has coordinates)
            $distance = null;
            $distanceKm = null;
            if ($currentCoordinates && $supplier->address) {
                // Try to parse coordinates from supplier address or use a default calculation
                $supplierCoordinates = $this->parseCoordinates($supplier->address);
                if ($supplierCoordinates) {
                    $distance = $this->calculateDistanceFromCoordinates($currentCoordinates, $supplierCoordinates);
                    $distanceKm = $distance ? round($distance['distance_km'], 2) : null;
                }
            }

            // Apply distance filter
            if (! empty($filters['max_distance_km']) && $distanceKm && $distanceKm > $filters['max_distance_km']) {
                return null;
            }

            // Calculate availability (assuming suppliers can always provide, but we can check min_order_quantity)
            $availabilityPercentage = PurchaseConstants::FULL_AVAILABILITY_PERCENTAGE; // Default: always available
            if ($supplierItem->max_order_quantity) {
                $maxAvailable = (float) $supplierItem->max_order_quantity;
                $availabilityPercentage = min(
                    PurchaseConstants::FULL_AVAILABILITY_PERCENTAGE,
                    round(($maxAvailable / $quantity) * 100, 1)
                );
            }

            $unitPrice = (float) $supplierItem->unit_price;
            $totalAmount = $unitPrice * $quantity;

            return [
                'supplier_id' => $supplier->id,
                'supplier' => (new SupplierResource($supplier))->toArray(request()),
                // Item Details
                'item_id' => $itemId,
                'item_price' => $branchItem->item_price,
                'item_unit' => $branchItem->item_unit,

                'item_title' => $branchItem->item_name,
                'item_code' => $branchItem->item_code,
                'item_logo' => $itemLogo,
                'quantity' => $quantity,
                'total_amount' => round($totalAmount, 2),
                // Pricing
                'price_rate' => round($unitPrice, 2),
                'economy_price' => $supplierItem->economy_price ? round((float) $supplierItem->economy_price, 2) : null,
                'standard_price' => $supplierItem->standard_price ? round((float) $supplierItem->standard_price, 2) : null,
                'premium_price' => $supplierItem->premium_price ? round((float) $supplierItem->premium_price, 2) : null,
                // Delivery
                'delivery_hours' => $supplierItem->delivery_hours,
                'delivery_days' => $supplierItem->delivery_hours
                    ? round($supplierItem->delivery_hours / PurchaseConstants::HOURS_PER_DAY, 1)
                    : null,
                // Availability
                'availability_percentage' => $availabilityPercentage,
                'min_order_quantity' => $supplierItem->min_order_quantity ? (float) $supplierItem->min_order_quantity : null,
                'max_order_quantity' => $supplierItem->max_order_quantity ? (float) $supplierItem->max_order_quantity : null,
                // Distance
                'distance_km' => $distanceKm,
                'estimated_hours' => $distance ? round($distance['estimated_hours'], 1) : null,
                // Rating
                'rating' => round((float) ($supplierItem->rating ?? $supplier->rating ?? 0), 1),
                'response_rate' => $supplier->response_rate_percentage ? round((float) $supplier->response_rate_percentage, 1) : null,
            ];
        })->filter()->values(); // Remove null values from filters

        // Apply sorting
        $sortBy = $validated['sort_by'] ?? 'price';
        $sortOrder = $validated['sort_order'] ?? 'asc';

        $suppliersList = $this->sortSuppliers($suppliersList, $sortBy, $sortOrder);

        return [
            'data' => $suppliersList,
        ];
    }

    /**
     * Get purchasing officer items with price comparison
     */
    public function getPurchasingOfficerItems(array $validated, string $branchId): array
    {
        $perPage = $validated['per_page'] ?? PurchaseConstants::DEFAULT_PER_PAGE;

        // Get branch items with filters (similar to getTransferItems)
        // Performance: Eager load only needed item columns
        $query = BranchItem::where('branch_id', $branchId)
            ->with('item:id,name,code,unit,logo,category,subcategory');

        // Apply filters using scopes (which use whereHas on item relationship)
        if (! empty($validated['search'])) {
            $query->search($validated['search']);
        }

        if (! empty($validated['category'])) {
            $query->byCategory($validated['category']);
        }

        // Order by item name through join with items table
        $branchItems = $query->join('items', 'branch_item.item_id', '=', 'items.id')
            ->orderBy('items.name', 'asc')
            ->select('branch_item.*') // Select only branch_item columns to avoid conflicts
            ->paginate($perPage);
        $itemsCollection = $branchItems->getCollection();

        // Get all item IDs (use item_id, not id from BranchItem)
        $itemIds = $itemsCollection->pluck('item_id')->filter()->unique()->toArray();

        // Performance optimization: Get purchasing officer prices for all items in batch
        $poPrices = [];
        foreach ($itemIds as $itemId) {
            $poPrice = $this->priceService->getPurchasingOfficerPrices($itemId);
            if ($poPrice) {
                $poPrices[$itemId] = $poPrice;
            }
        }

        // Performance optimization: Get direct supplier prices for all items in batch
        $supplierPricesMap = [];
        if (! empty($itemIds)) {
            $supplierItems = SupplierItem::whereIn('item_id', $itemIds)
                ->with('supplier')
                ->available()
                ->whereHas('supplier', fn ($q) => $q->active())
                ->get()
                ->groupBy('item_id');

            foreach ($supplierItems as $itemId => $items) {
                // Get the best (lowest) price from all suppliers for this item
                $bestPrice = $items->min('unit_price');
                $supplierPricesMap[$itemId] = $bestPrice;
            }
        }

        // Calculate totals for summary
        $totalDirectSupplierAmount = 0;
        $totalPurchasingOfficerAmount = 0;
        $totalExpectedSavings = 0;

        // Transform the collection for the resource (similar to getTransferItems)
        $transformedItems = $itemsCollection->map(function ($branchItem) use (
            $poPrices,
            $supplierPricesMap,
            &$totalDirectSupplierAmount,
            &$totalPurchasingOfficerAmount,
            &$totalExpectedSavings
        ) {
            // Use item_id from BranchItem, not id
            $itemId = $branchItem->item_id;

            // Get prices
            $poPrice = $poPrices[$itemId] ?? null;
            $directSupplierPrice = $supplierPricesMap[$itemId] ?? null;

            $poUnitPrice = $poPrice ? (float) $poPrice['unit_price'] : 0;
            $directSupplierUnitPrice = $directSupplierPrice ? (float) $directSupplierPrice : 0;

            // Use default quantity from constants for comparison (can be edited by user)
            $defaultQuantity = PurchaseConstants::DEFAULT_QUANTITY;

            $directSupplierTotal = $directSupplierUnitPrice * $defaultQuantity;
            $poTotal = $poUnitPrice * $defaultQuantity;
            $savings = $directSupplierTotal - $poTotal;
            $resolvedItemPrice = (float) ($branchItem->item_price ?? 0);
            if ($resolvedItemPrice <= 0) {
                $resolvedItemPrice = $directSupplierUnitPrice > 0
                    ? $directSupplierUnitPrice
                    : $poUnitPrice;
            }

            // Add to totals
            $totalDirectSupplierAmount += $directSupplierTotal;
            $totalPurchasingOfficerAmount += $poTotal;
            $totalExpectedSavings += $savings;

            // Return structured data for the resource (similar to getTransferItems)
            return [
                'item' => $branchItem,
                'quantity' => $defaultQuantity, // Editable
                'quality' => 'standard', // Editable, default
                'resolved_item_price' => round($resolvedItemPrice, 2),
                'price_comparison' => [
                    'direct_supplier' => [
                        'unit_price' => round($directSupplierUnitPrice, 2),
                        'total_amount' => round($directSupplierTotal, 2),
                    ],
                    'purchasing_officer' => [
                        'unit_price' => round($poUnitPrice, 2),
                        'total_amount' => round($poTotal, 2),
                    ],
                    'savings' => round($savings, 2),
                ],
            ];
        });

        // Create ResourceCollection and set the paginator (similar to getTransferItems)
        $resourceCollection = PurchasingOfficerItemResource::collection($transformedItems);
        $resourceCollection->resource = $branchItems;

        return [
            'data' => $resourceCollection,
            'price_comparison_summary' => [
                'total_amount_from_direct_supplier' => round($totalDirectSupplierAmount, 2),
                'total_amount_via_purchasing_officer' => round($totalPurchasingOfficerAmount, 2),
                'total_expected_savings' => round($totalExpectedSavings, 2),
            ],
        ];
    }

    /**
     * Get supplier items
     *
     * Uses both SupplierProduct and SupplierItem so the list matches getDirectSupplierItems:
     * any item that appears for this supplier in "choose source" will appear here.
     */
    public function getSupplierItems(array $validated, string $branchId): array
    {
        $supplierId = $validated['supplier_id'];
        $perPage = $validated['per_page'] ?? PurchaseConstants::DEFAULT_PER_PAGE;

        // Get supplier with details (using new Supplier model)
        $supplier = \Modules\Supplier\Models\Supplier::find($supplierId);
        if (! $supplier) {
            throw new \InvalidArgumentException('Supplier not found');
        }

        // Get items from BOTH SupplierProduct and SupplierItem (same logic as getDirectSupplierItems)
        // so that items shown when choosing source appear in supplier-items list
        $itemIdFilter = ! empty($validated['item_id']) ? $validated['item_id'] : null;

        $supplierProducts = SupplierProduct::where('supplier_id', $supplierId)
            ->available();
        if ($itemIdFilter) {
            $supplierProducts->where('item_id', $itemIdFilter);
        }
        $supplierProducts = $supplierProducts->get();

        $query = SupplierItem::where('supplier_id', $supplierId)
            ->available();
        if ($itemIdFilter) {
            $query->where('item_id', $itemIdFilter);
        }
        $legacySupplierItems = $query->get();

        // Merge: collect all item_ids from both sources (SupplierProduct takes precedence per item_id)
        $productsByItemId = $supplierProducts->keyBy('item_id');
        $legacyByItemId = $legacySupplierItems->keyBy('item_id');
        $allItemIds = $supplierProducts->pluck('item_id')
            ->merge($legacySupplierItems->pluck('item_id'))
            ->unique()
            ->values()
            ->toArray();

        if (empty($allItemIds)) {
            return [
                'data' => [],
                'supplier' => (new SupplierResource($supplier))->toArray(request()),
            ];
        }

        // Build unified supplier items: prefer SupplierProduct, fallback to SupplierItem (same as getDirectSupplierItems)
        $supplierItems = collect($allItemIds)->map(function ($itemId) use ($productsByItemId, $legacyByItemId) {
            $product = $productsByItemId->get($itemId);
            if ($product) {
                return (object) [
                    'id' => $product->id,
                    'supplier_id' => $product->supplier_id,
                    'item_id' => $product->item_id,
                    'unit_price' => $product->unit_price,
                    'economy_price' => $product->economy_price,
                    'standard_price' => $product->standard_price,
                    'premium_price' => $product->premium_price,
                    'is_available' => $product->is_available,
                    'min_order_quantity' => $product->min_order_quantity,
                    'max_order_quantity' => $product->max_order_quantity,
                    'delivery_hours' => $product->delivery_hours,
                    'rating' => $product->rating,
                ];
            }
            $legacy = $legacyByItemId->get($itemId);

            return $legacy ?: null;
        })->filter()->values();

        $supplierItemIds = $supplierItems->pluck('item_id')->toArray();

        // Get branch items in current branch that match the referenced items by item_id
        $branchItemsQuery = BranchItem::where('branch_id', $branchId)
            ->with('item:id,name,code,unit,category,subcategory')
            ->whereIn('item_id', $supplierItemIds);

        // Apply filters using scopes (which use whereHas on item relationship)
        if (! empty($validated['search'])) {
            $branchItemsQuery->search($validated['search']);
        }

        if (! empty($validated['category'])) {
            $branchItemsQuery->byCategory($validated['category']);
        }

        $branchItems = $branchItemsQuery->paginate($perPage);
        $itemsCollection = $branchItems->getCollection();

        // Create maps for matching by item_id (both use item_id from items table)
        $supplierItemsByItemIdMap = $supplierItems->keyBy('item_id');

        // Transform the collection for the resource
        $transformedItems = $itemsCollection->map(function ($branchItem) use ($supplierItemsByItemIdMap) {
            // Match by item_id (both SupplierItem and SupplierProduct use item_id from items table)
            $supplierItem = $supplierItemsByItemIdMap[$branchItem->item_id] ?? null;

            if (! $supplierItem) {
                return null;
            }

            return [
                'supplier_item' => $supplierItem,
                'branch_item' => $branchItem,
            ];
        })->filter()->values(); // Remove null values

        // Create ResourceCollection
        $resourceCollection = SupplierItemResource::collection($transformedItems);
        $resourceCollection->resource = $branchItems;

        return [
            'data' => $resourceCollection,
            'supplier' => (new SupplierResource($supplier))->toArray(request()),
        ];
    }

    /**
     * Sort branches by specified criteria
     */
    public function sortBranches($branches, string $sortBy, string $sortOrder): Collection
    {
        $isAscending = strtolower($sortOrder) === 'asc';

        // Map sort field to data key
        $sortKey = match ($sortBy) {
            'distance' => 'distance_km',
            'response_rate' => 'response_rate',
            'rating' => 'rating',
            'availability' => 'availability_percentage',
            default => 'distance_km',
        };

        // Performance: Use direct array access with null coalescing for better performance
        // Handle nested distance key (distance is an array with distance_km)
        if ($sortKey === 'distance_km' && $isAscending) {
            return $branches->sortBy(function ($item) {
                $distance = $item['distance'] ?? null;
                $value = is_array($distance) ? ($distance['distance_km'] ?? null) : ($item['distance_km'] ?? null);

                return $value ?? PHP_FLOAT_MAX;
            })->values();
        }

        if ($sortKey === 'distance_km' && ! $isAscending) {
            return $branches->sortByDesc(function ($item) {
                $distance = $item['distance'] ?? null;
                $value = is_array($distance) ? ($distance['distance_km'] ?? null) : ($item['distance_km'] ?? null);

                return $value ?? PHP_FLOAT_MIN;
            })->values();
        }

        // For other fields, use direct access
        $sorted = $isAscending
            ? $branches->sortBy(function ($item) use ($sortKey) {
                $value = $item[$sortKey] ?? null;

                return $value ?? ($sortKey === 'distance_km' ? PHP_FLOAT_MAX : PHP_FLOAT_MIN);
            })
            : $branches->sortByDesc(function ($item) use ($sortKey) {
                $value = $item[$sortKey] ?? null;

                return $value ?? ($sortKey === 'distance_km' ? PHP_FLOAT_MIN : PHP_FLOAT_MAX);
            });

        return $sorted->values();
    }

    /**
     * Sort suppliers by specified criteria
     */
    public function sortSuppliers($suppliers, string $sortBy, string $sortOrder): Collection
    {
        $isAscending = strtolower($sortOrder) === 'asc';

        $sortKey = match ($sortBy) {
            'price' => 'price_rate',
            'delivery_time' => 'delivery_hours',
            'rating' => 'rating',
            default => 'price_rate',
        };

        // Performance: Use direct array access with null coalescing
        $sorted = $isAscending
            ? $suppliers->sortBy(function ($item) use ($sortKey) {
                return $item[$sortKey] ?? ($sortKey === 'price_rate' ? PHP_FLOAT_MAX : PHP_FLOAT_MIN);
            })
            : $suppliers->sortByDesc(function ($item) use ($sortKey) {
                return $item[$sortKey] ?? ($sortKey === 'price_rate' ? PHP_FLOAT_MIN : PHP_FLOAT_MAX);
            });

        return $sorted->values();
    }

    /**
     * Calculate transport details between branches
     */
    public function calculateTransportDetails(string $fromBranchId, string $toBranchId): array
    {
        try {
            // Performance optimization: Load only needed columns
            $branches = Branch::whereIn('id', [$fromBranchId, $toBranchId])
                ->select('id', 'name', 'lat', 'lng')
                ->get()
                ->keyBy('id');

            $fromBranch = $branches[$fromBranchId] ?? null;
            $toBranch = $branches[$toBranchId] ?? null;

            if (! $fromBranch || ! $toBranch) {
                return [
                    'method' => 'Vehicle (Free)',
                    'cost' => 'Free',
                    'estimated_time_hours' => 0,
                    'driver' => null,
                    'recommended_temperature' => null,
                    'distance_km' => 0,
                    'notes' => 'Branch information not available',
                ];
            }

            // Calculate estimated time based on distance (simplified)
            $estimatedHours = $this->calculateEstimatedTime($fromBranch, $toBranch);
            $distance = $this->calculateDistance($fromBranch, $toBranch);

            // Auto-assign driver
            $driver = $this->autoAssignDriver($fromBranchId);

            // Transportation method is always Vehicle (Free) for internal transfers
            $method = 'Vehicle (Free)';

            return [
                'method' => $method,
                'cost' => 'Free',
                'estimated_time_hours' => $estimatedHours,
                'driver' => $driver,
                'recommended_temperature' => null, // Will be set per item based on cooling_status
                'distance_km' => $distance,
                'from_branch' => [
                    'id' => $fromBranch->id,
                    'name' => $fromBranch->name,
                    'location' => $fromBranch->location ?? null,
                    'lat' => $fromBranch->lat ? (float) $fromBranch->lat : null,
                    'lng' => $fromBranch->lng ? (float) $fromBranch->lng : null,
                ],
                'to_branch' => [
                    'id' => $toBranch->id,
                    'name' => $toBranch->name,
                    'location' => $toBranch->location ?? null,
                    'address' => $toBranch->location ?? null, // Keep for backward compatibility
                ],
                'notes' => 'Transport details are estimated and may vary',
            ];
        } catch (\Exception $e) {
            Log::error('Error calculating transport details: '.$e->getMessage());

            return [
                'method' => 'Vehicle (Free)',
                'cost' => 'Free',
                'estimated_time_hours' => 0,
                'driver' => null,
                'recommended_temperature' => null,
                'distance_km' => 0,
                'notes' => 'Error calculating transport details',
            ];
        }
    }

    /**
     * Calculate distance between branches (simplified)
     */
    private function calculateDistance($fromBranch, $toBranch): float
    {
        // TODO: Implement actual distance calculation using coordinates
        // For now, return a random distance between min and max constants
        // In production, use coordinates from branches to calculate real distance
        return round(
            rand(PurchaseConstants::DEFAULT_MIN_DISTANCE_KM, PurchaseConstants::DEFAULT_MAX_DISTANCE_KM)
                + (rand(0, 99) / 100),
            2
        );
    }

    /**
     * Calculate estimated time between branches
     */
    private function calculateEstimatedTime($fromBranch, $toBranch): float
    {
        // Calculate based on distance using constants
        $distance = $this->calculateDistance($fromBranch, $toBranch);
        $estimatedHours = $distance / PurchaseConstants::AVERAGE_SPEED_KMH;

        // Add fixed time for loading/unloading
        $estimatedHours += PurchaseConstants::LOADING_UNLOADING_HOURS;

        return round($estimatedHours, 2);
    }

    /**
     * Auto-assign available driver
     */
    private function autoAssignDriver(string $branchId): ?array
    {
        try {
            // Performance optimization: Select only needed columns
            $driver = User::where('branch_id', $branchId)
                ->where('role', 'driver')
                ->where('status', 'active')
                ->whereDoesntHave('currentTransports', function ($query) {
                    $query->whereIn('status', ['in_transit', 'loading', 'unloading']);
                })
                ->select('id', 'name', 'phone', 'email', 'vehicle_number', 'vehicle_type', 'vehicle_capacity')
                ->first();

            if (! $driver) {
                // Try to find any available driver in the system
                $driver = User::where('role', 'driver')
                    ->where('status', 'active')
                    ->whereDoesntHave('currentTransports', function ($query) {
                        $query->whereIn('status', ['in_transit', 'loading', 'unloading']);
                    })
                    ->select('id', 'name', 'phone', 'email', 'vehicle_number', 'vehicle_type', 'vehicle_capacity')
                    ->first();
            }

            if (! $driver) {
                return null;
            }

            return [
                'id' => $driver->id,
                'name' => $driver->name,
                'phone' => $driver->phone ?? null,
                'email' => $driver->email,
                'vehicle_number' => $driver->vehicle_number ?? 'N/A',
                'vehicle_type' => $driver->vehicle_type ?? 'Truck',
                'capacity_kg' => $driver->vehicle_capacity ?? PurchaseConstants::CHUNK_SIZE_LARGE,
            ];
        } catch (\Exception $e) {
            Log::error('Error auto-assigning driver: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Parse coordinates from string format
     */
    private function parseCoordinates(?string $coordinates): ?array
    {
        if (empty($coordinates)) {
            return null;
        }

        // Try different formats
        if (preg_match('/(\d+\.?\d*)[,\s]+(\d+\.?\d*)/', $coordinates, $matches)) {
            return [
                'lat' => (float) $matches[1],
                'lng' => (float) $matches[2],
            ];
        }

        return null;
    }

    /**
     * Calculate distance between two coordinates using Haversine formula
     */
    private function calculateDistanceFromCoordinates(?array $from, ?array $to): ?array
    {
        if (! $from || ! $to) {
            return null;
        }

        // Haversine formula for distance calculation
        $earthRadius = 6371; // Earth radius in kilometers

        $latFrom = deg2rad($from['lat']);
        $lonFrom = deg2rad($from['lng']);
        $latTo = deg2rad($to['lat']);
        $lonTo = deg2rad($to['lng']);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos($latFrom) * cos($latTo) *
            sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distanceKm = $earthRadius * $c;

        // Estimate hours using constants
        $estimatedHours = ($distanceKm / PurchaseConstants::AVERAGE_SPEED_KMH)
            + PurchaseConstants::LOADING_UNLOADING_HOURS;

        return [
            'distance_km' => $distanceKm,
            'estimated_hours' => $estimatedHours,
        ];
    }
}
