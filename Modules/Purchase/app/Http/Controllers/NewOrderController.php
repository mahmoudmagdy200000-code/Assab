<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Http\Requests\ComparePricesRequest;
use Modules\Purchase\Http\Requests\StoreDirectSupplierOrderRequest;
use Modules\Purchase\Http\Requests\StoreInternalTransferRequest;
use Modules\Purchase\Http\Requests\StorePurchasingOfficerOrderRequest;
use Modules\Purchase\Services\PriceComparisonService;
use Modules\Purchase\Services\PurchaseOrderService;
use Modules\Purchase\Http\Requests\FilterBranchItemsRequest;
use Modules\Purchase\Http\Requests\FilterOrdersRequest;
use Modules\Purchase\Http\Requests\StorePurchaseOrderRequest;
use Modules\Purchase\Http\Requests\StoreMultipleOrdersRequest;
use Modules\Purchase\Transformers\BranchItemResource;
use Modules\Purchase\Transformers\OrderSummaryResource;
use Modules\Purchase\Transformers\PriceComparisonResource;
use Modules\Purchase\Transformers\PurchaseOrderResource;
use Modules\Purchase\Transformers\SupplierResource;
use Modules\Purchase\Transformers\TransferItemResource;
use Modules\Purchase\Transformers\PurchasingOfficerItemResource;
use Modules\Purchase\Transformers\SupplierItemResource;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Http\Requests\GetTransferItemsRequest;
use Modules\Purchase\Http\Requests\GetDirectSupplierItemsRequest;
use Modules\Purchase\Http\Requests\GetPurchasingOfficerItemsRequest;
use Modules\Purchase\Http\Requests\GetSupplierItemsRequest;
// Add these imports
use Modules\Branch\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Purchase\Models\SupplierItem;
use Modules\Purchase\Models\PurchaseSupplier;

class NewOrderController extends BaseController
{
    public function __construct(
        private readonly PurchaseOrderService $orderService,
        private readonly PriceComparisonService $priceService
    ) {}




    /**
     * Get list of purchase orders with filters
     *
     * Filters:
     * - Search: by item name or order number
     * - Order Type: direct_supplier, via_purchasing_officer, internal_transfer
     * - Status: filter by order status
     * - Date: filter by date range
     *
     * @group New Order
     */
    public function index(FilterOrdersRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $filters['branch_id'] = auth()->user()->branch_id;
            $perPage = $request->get('per_page', 15);

            $orders = $this->orderService->getOrders($filters, $perPage);

            return $this->paginatedResponse(
                PurchaseOrderResource::collection($orders),
                'Orders retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching orders');
        }
    }

    /**
     * Get branch items with search and filters
     *
     * Filters:
     * - Search: by item name
     * - Category: filter by category
     * - Subcategory: filter by subcategory
     * - Supplier: filter by supplier (from Expense module)
     *
     * @group New Order
     */
    public function getBranchItems(FilterBranchItemsRequest $request): JsonResponse
    {
        try {
            $branchId = $request->get('branch_id', auth()->user()->branch_id);

            if (!$branchId) {
                return $this->errorResponse('Branch ID is required', 400);
            }

            $filters = $request->validated();
            $perPage = $request->get('per_page', 15);

            $branchItems = $this->orderService->getBranchItems($branchId, $filters, $perPage);

            return $this->paginatedResponse(
                BranchItemResource::collection($branchItems),
                'Branch items retrieved successfully'
            );
        } catch (\Exception $e) {
            Log::error('Error in getBranchItems', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'branch_id' => $request->get('branch_id', auth()->user()->branch_id ?? null),
                'filters' => $request->validated(),
            ]);
            return $this->handleException($e, 'fetching branch items');
        }
    }

    /**
     * Compare prices for an item across all sources
     *
     * @group New Order
     */
    public function comparePrices(ComparePricesRequest $request): JsonResponse
    {
        try {
            $comparison = $this->priceService->comparePrices(
                $request->item_id,
                $request->quantity ?? null
            );

            return $this->successResponse(
                new PriceComparisonResource($comparison),
                'Price comparison retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'comparing prices');
        }
    }

    /**
     * Get suppliers for an item
     *
     * @group New Order
     */
    public function getSuppliers(Request $request): JsonResponse
    {
        try {
            $itemId = $request->get('item_id');
            $filters = [
                'status' => $request->get('status'),
                'max_delivery_hours' => $request->get('max_delivery_hours'),
                'search' => $request->get('search'),
            ];

            $suppliers = $this->priceService->getSuppliers($itemId, $filters);

            return $this->successResponse(
                $suppliers,
                'Suppliers retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching suppliers');
        }
    }

    /**
     * Get branches with stock for internal transfer
     *
     * Filters:
     * - min_availability: Minimum availability percentage (0-100)
     * - search: Search by branch name
     * - response_time: Filter by response time (fast, normal, slow)
     *   - fast: response_rate >= 80%
     *   - normal: 50% <= response_rate < 80%
     *   - slow: response_rate < 50%
     * - max_distance_km: Maximum distance in kilometers
     * - sort_by: Sort results (distance, response_rate, rating, availability)
     * - sort_order: Sort order (asc, desc)
     *
     * @group New Order
     */
    public function getBranches(Request $request): JsonResponse
    {
        try {
            $itemId = $request->get('item_id');
            $quantity = $request->get('quantity', 1);
            $branchId = auth()->user()->branch_id;

            // Validate and prepare filters
            $filters = [
                'min_availability' => $request->get('min_availability') ? (float) $request->get('min_availability') : null,
                'search' => $request->get('search'),
                'response_time' => $request->get('response_time'), // fast, normal, slow
                'max_distance_km' => $request->get('max_distance_km') ? (float) $request->get('max_distance_km') : null,
            ];

            // Validate response_time filter
            if (!empty($filters['response_time']) && !in_array($filters['response_time'], ['fast', 'normal', 'slow'])) {
                return $this->errorResponse('Invalid response_time filter. Must be: fast, normal, or slow', 400);
            }

            // Validate max_distance_km
            if (!empty($filters['max_distance_km']) && $filters['max_distance_km'] < 0) {
                return $this->errorResponse('max_distance_km must be a positive number', 400);
            }

            // Get branches with filters applied
            $branches = $this->priceService->getBranchesWithStock($itemId, $quantity, $branchId, $filters);

            // Apply sorting if requested
            $sortBy = $request->get('sort_by', 'distance'); // default: sort by distance
            $sortOrder = $request->get('sort_order', 'asc'); // default: ascending

            $branches = $this->sortBranches($branches, $sortBy, $sortOrder);

            return $this->successResponse(
                $branches->values(),
                'Branches retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching branches');
        }
    }

    /**
     * Sort branches by specified criteria
     * Optimized to use sortBy/sortByDesc instead of sort with callback
     */
    private function sortBranches($branches, string $sortBy, string $sortOrder)
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

        // Use sortBy/sortByDesc which is more efficient than sort with callback
        $sorted = $isAscending
            ? $branches->sortBy(function ($item) use ($sortKey) {
                $value = $item[$sortKey] ?? null;
                // Handle null values - put them at the end by using a high value
                if ($value === null) {
                    return $sortKey === 'distance_km' ? PHP_FLOAT_MAX : PHP_FLOAT_MIN;
                }
                return $value;
            })
            : $branches->sortByDesc(function ($item) use ($sortKey) {
                $value = $item[$sortKey] ?? null;
                // Handle null values - put them at the end by using a low value
                if ($value === null) {
                    return $sortKey === 'distance_km' ? PHP_FLOAT_MIN : PHP_FLOAT_MAX;
                }
                return $value;
            });

        return $sorted->values();
    }

    /**
     * Create purchase order(s)
     *
     * Unified endpoint for creating single or multiple orders from different sources.
     *
     * Supports creating multiple orders in one request:
     * - branches[]: Internal transfers from multiple branches (each branch has its own items)
     * - direct_supplier[]: Multiple direct supplier orders
     * - purchase_officer[]: Multiple purchasing officer orders
     *
     * All fields are optional, but at least one order type must be provided.
     *
     * @group New Order
     */
    public function store(StoreMultipleOrdersRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            // Check if this is a single order (old format) or multiple orders (new format)
            $isMultipleOrders = isset($data['branches']) || isset($data['direct_supplier']) || isset($data['purchase_officer']);

            if ($isMultipleOrders) {
                // Create multiple orders
                $branchId = auth()->user()->branch_id;
                $requestedBy = auth()->id();

                $orders = $this->orderService->createMultipleOrders($data, $branchId, $requestedBy);

                $orderCount = $orders->count();
                $orderTypes = $orders->map(function ($order) {
                    $orderType = is_string($order->order_type)
                        ? OrderType::from($order->order_type)
                        : $order->order_type;
                    return $orderType->label();
                })->unique()->values()->toArray();

                return $this->createdResponse(
                    PurchaseOrderResource::collection($orders),
                    "Successfully created {$orderCount} order(s): " . implode(', ', $orderTypes)
                );
            } else {
                // Fallback to single order creation (backward compatibility)
                // This handles the old request format if needed
                return $this->errorResponse(
                    'Invalid request format. Please use branches[], direct_supplier[], or purchase_officer[] arrays.',
                    400
                );
            }
        } catch (\Exception $e) {
            return $this->handleException($e, 'creating purchase order(s)');
        }
    }

    /**
     * Save order as draft
     *
     * @group New Order
     */
    public function saveDraft(Request $request): JsonResponse
    {
        try {
            $data = $request->all();
            $data['branch_id'] = auth()->user()->branch_id;
            $data['requested_by'] = auth()->id();

            $order = $this->orderService->saveDraft($data);

            return $this->createdResponse(
                new PurchaseOrderResource($order),
                'Order saved as draft successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'saving draft');
        }
    }

    /**
     * Submit order
     *
     * @group New Order
     */
    public function submit(string $id): JsonResponse
    {
        try {
            $order = $this->orderService->getOrderDetails($id);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            $success = $this->orderService->submitOrder($order);

            if (!$success) {
                return $this->errorResponse('Cannot submit order in current status', 400);
            }

            return $this->successResponse(
                new PurchaseOrderResource($order->fresh(['items', 'supplier', 'branch'])),
                'Order submitted successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'submitting order');
        }
    }

    /**
     * Get order summary
     *
     * @group New Order
     */
    public function getSummary(string $id): JsonResponse
    {
        try {
            // Performance optimization: Use select to limit columns and eager load relationships
            $order = PurchaseOrder::with([
                'items:id,purchase_order_id,item_id,quantity,unit_price,total_price',
                'supplier:id,name,phone,email',
                'branch:id,name,location',
                'fromBranch:id,name,location',
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
                'created_at',
                'updated_at'
            ])->find($id);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            return $this->successResponse(
                new OrderSummaryResource($order),
                'Order summary retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching order summary');
        }
    }

    /**
     * Update order items
     *
     * @group New Order
     */
    public function updateItems(Request $request, string $id): JsonResponse
    {
        try {
            $order = $this->orderService->getOrderDetails($id);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            $this->orderService->updateItems($order, $request->get('items', []));

            return $this->successResponse(
                new PurchaseOrderResource($order->fresh(['items'])),
                'Order items updated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'updating order items');
        }
    }

    /**
     * Get transfer items with inventory and transport details
     *
     * Returns a list of items from the transferring branch with:
     * - Item details (name, logo, quantity, quality)
     * - Available quantity in transferring branch (with quality)
     * - Remaining balance in transferring branch (with quality)
     * - Expiry date
     * - Cooling status (transfer ready)
     * - Transport details (method, estimated time, driver, temperature)
     *
     * @param GetTransferItemsRequest $request
     * @return JsonResponse
     *
     * @group New Order
     */
    public function getTransferItems(GetTransferItemsRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $fromBranchId = $validated['branch_id'];
            $toBranchId = auth()->user()->branch_id;
            $perPage = $validated['per_page'] ?? 15;

            // Check if from and to branches are different
            if ($fromBranchId === $toBranchId) {
                return $this->errorResponse('From and to branches cannot be the same', 400);
            }

            // Get transport details once (same for all items)
            $transportDetails = $this->calculateTransportDetails($fromBranchId, $toBranchId);

            // Store requested item_id for later use in mapping
            $requestedItemId = $validated['item_id'] ?? null;

            // If item_id is provided, check BranchInventory first (source of truth)
            // Store it in a variable accessible throughout the function
            $inventoryItem = null;
            $localBranchItemId = null; // Store the local BranchItem id if found

            if (!empty($validated['item_id'])) {
                // Get the original BranchItem to get item_name
                $originalItem = BranchItem::find($validated['item_id']);

                if ($originalItem) {
                    // Find BranchItem in THIS branch with same item_name
                    // getBranchesWithStock uses item_id from another branch, but inventory in this branch
                    // might reference the local BranchItem id
                    $localBranchItem = BranchItem::where('branch_id', $fromBranchId)
                        ->where('item_name', $originalItem->item_name)
                        ->first();

                    if ($localBranchItem) {
                        $localBranchItemId = $localBranchItem->id;

                        // Try to find inventory using the local BranchItem id
                        $inventoryItem = BranchInventory::where('branch_id', $fromBranchId)
                            ->where('item_id', $localBranchItem->id)
                            ->first();
                    }

                    // Also try with original item_id (in case it matches)
                    if (!$inventoryItem) {
                        $inventoryItem = BranchInventory::where('branch_id', $fromBranchId)
                            ->where('item_id', $validated['item_id'])
                            ->first();
                    }
                }
            }

            // Get items from the transferring branch
            $query = BranchItem::where('branch_id', $fromBranchId)
                ->with([
                    'branch:id,name,location',
                ]);

            // Filter by specific item_id if provided
            if (!empty($validated['item_id'])) {
                // Use localBranchItemId if found, otherwise use original item_id
                if ($localBranchItemId) {
                    $query->where('id', $localBranchItemId);
                } else {
                    // Try to find by original item_id first
                    $branchItemInBranch = BranchItem::where('branch_id', $fromBranchId)
                        ->where('id', $validated['item_id'])
                        ->exists();

                    if ($branchItemInBranch) {
                        $query->where('id', $validated['item_id']);
                    } else {
                        // Try to find by item_name
                        $originalItem = BranchItem::find($validated['item_id']);
                        if ($originalItem) {
                            $query->where('item_name', $originalItem->item_name);
                        } else {
                            $query->where('id', $validated['item_id']);
                        }
                    }
                }
            }

            // Apply filters
            if (!empty($validated['search'])) {
                $query->where(function ($q) use ($validated) {
                    $q->where('item_name', 'like', '%' . $validated['search'] . '%')
                        ->orWhere('item_code', 'like', '%' . $validated['search'] . '%');
                });
            }

            if (!empty($validated['category'])) {
                $query->where('category', $validated['category']);
            }

            $items = $query->paginate($perPage);
            $itemsCollection = $items->getCollection();

            // Performance optimization: Load all inventories in batch queries instead of N+1
            $itemIds = $itemsCollection->pluck('id')->toArray();

            // Load all from inventories in one query
            $fromInventories = BranchInventory::where('branch_id', $fromBranchId)
                ->whereIn('item_id', $itemIds)
                ->get()
                ->keyBy('item_id');

            // Load all to inventories in one query
            $toInventories = BranchInventory::where('branch_id', $toBranchId)
                ->whereIn('item_id', $itemIds)
                ->get()
                ->keyBy('item_id');

            // Performance optimization: Load all original items in batch if needed
            $itemNamesAndCodes = $itemsCollection->map(function ($item) {
                return ['name' => $item->item_name, 'code' => $item->item_code];
            })->unique(function ($item) {
                return $item['name'] . '|' . $item['code'];
            });

            // Build a map of (item_name, item_code) -> original item_id
            $originalItemsMap = [];
            if (!$requestedItemId && $itemNamesAndCodes->isNotEmpty()) {
                $names = $itemNamesAndCodes->pluck('name')->unique()->toArray();
                $codes = $itemNamesAndCodes->pluck('code')->unique()->toArray();

                $originalItems = BranchItem::whereIn('item_name', $names)
                    ->whereIn('item_code', $codes)
                    ->select('id', 'item_name', 'item_code')
                    ->orderBy('created_at', 'asc')
                    ->get()
                    ->groupBy(function ($item) {
                        return $item->item_name . '|' . $item->item_code;
                    })
                    ->map(function ($group) {
                        return $group->first(); // Get oldest one (original)
                    });

                foreach ($originalItems as $item) {
                    $key = $item->item_name . '|' . $item->item_code;
                    $originalItemsMap[$key] = $item->id;
                }
            }

            // Load requested original item if needed
            $requestedOriginalItem = null;
            if ($requestedItemId) {
                $requestedOriginalItem = BranchItem::find($requestedItemId);
            }

            // Transform the collection for the resource
            $transformedItems = $itemsCollection->map(function ($item) use (
                $fromInventories,
                $toInventories,
                $transportDetails,
                $requestedItemId,
                $originalItemsMap,
                $requestedOriginalItem
            ) {
                // Use the found item's id for inventory lookup (this is the local BranchItem id)
                $itemIdForInventory = $item->id;

                // Get inventory from maps (O(1) lookup instead of query)
                $fromInventory = $fromInventories[$itemIdForInventory] ?? null;
                $toInventory = $toInventories[$itemIdForInventory] ?? null;

                // Determine which item_id to use in response
                // If item_id was requested, use it; otherwise try to find original item_id
                $responseItemId = $requestedItemId;

                if (!$responseItemId) {
                    // Use pre-loaded map instead of query
                    $key = $item->item_name . '|' . $item->item_code;
                    $responseItemId = $originalItemsMap[$key] ?? $item->id;
                }

                // Prepare item for response
                if ($responseItemId && $responseItemId !== $item->id) {
                    // Use pre-loaded original item if available
                    if ($requestedOriginalItem && $requestedOriginalItem->id === $responseItemId) {
                        $itemForResponse = $requestedOriginalItem;
                    } else {
                        // Fallback: clone the found item with response item_id
                        $itemForResponse = clone $item;
                        $itemForResponse->id = $responseItemId;
                    }
                } else {
                    $itemForResponse = $item;
                }

                // Return structured data for the resource
                return [
                    'item' => $itemForResponse,
                    'from_inventory' => $fromInventory,
                    'to_inventory' => $toInventory,
                    'transport_details' => $transportDetails,
                    'requested_item_id' => $responseItemId, // Use determined item_id for response
                ];
            });

            // If no items found but item_id was requested and exists in inventory, create item from local BranchItem
            if ($transformedItems->isEmpty() && $requestedItemId && $inventoryItem) {
                // Get the local BranchItem (the one that matches the inventory)
                $localBranchItem = BranchItem::find($inventoryItem->item_id);

                // Get the original BranchItem for display (to maintain requested item_id)
                $originalItem = BranchItem::find($requestedItemId);

                if ($localBranchItem) {
                    // Reload inventory item to ensure we have fresh data
                    $inventoryItem = $inventoryItem->fresh();

                    $toInventory = BranchInventory::where('branch_id', $toBranchId)
                        ->where('item_id', $localBranchItem->id)
                        ->first();

                    // Create a collection with one item (use local item for inventory data, original for display)
                    $transformedItems = collect([[
                        'item' => $localBranchItem, // Use local item (matches inventory)
                        'from_inventory' => $inventoryItem,
                        'to_inventory' => $toInventory,
                        'transport_details' => $transportDetails,
                        'requested_item_id' => $requestedItemId, // Keep requested id for response
                    ]]);

                    // Update pagination to show this single item
                    $items = new \Illuminate\Pagination\LengthAwarePaginator(
                        collect([$originalItem]),
                        1,
                        15,
                        1
                    );
                }
            }

            // Create ResourceCollection and set the paginator
            $resourceCollection = TransferItemResource::collection($transformedItems);
            // Only set paginator if items exist (to avoid errors when manually creating collection)
            if (isset($items)) {
                $resourceCollection->resource = $items;
            }

            // Get paginated response
            $response = $this->successResponse(
                $resourceCollection,
                'Transfer items retrieved successfully'
            );

            // Add transport_summary to the response data
            $responseData = $response->getData(true);
            $responseData['transport_summary'] = $transportDetails;

            return response()->json($responseData, 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error fetching transfer items: ' . $e->getMessage(), [
                'exception' => $e,
                'request' => $request->all(),
                'user_id' => auth()->id()
            ]);

            return $this->errorResponse(
                'Error in fetching transfer items: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Calculate transport details between branches
     *
     * @param string $fromBranchId
     * @param string $toBranchId
     * @return array
     */
    private function calculateTransportDetails(string $fromBranchId, string $toBranchId): array
    {
        try {
            // Performance optimization: Load only needed columns
            $branches = Branch::whereIn('id', [$fromBranchId, $toBranchId])
                ->select('id', 'name', 'location')
                ->get()
                ->keyBy('id');

            $fromBranch = $branches[$fromBranchId] ?? null;
            $toBranch = $branches[$toBranchId] ?? null;

            if (!$fromBranch || !$toBranch) {
                return [
                    'method' => 'Vehicle (Free)',
                    'cost' => 'Free',
                    'estimated_time_hours' => 0,
                    'driver' => null,
                    'recommended_temperature' => null,
                    'distance_km' => 0,
                    'notes' => 'Branch information not available'
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
                    'address' => $fromBranch->location,
                ],
                'to_branch' => [
                    'id' => $toBranch->id,
                    'name' => $toBranch->name,
                    'address' => $toBranch->location,
                ],
                'notes' => 'Transport details are estimated and may vary'
            ];
        } catch (\Exception $e) {
            Log::error('Error calculating transport details: ' . $e->getMessage());

            return [
                'method' => 'Vehicle (Free)',
                'cost' => 'Free',
                'estimated_time_hours' => 0,
                'driver' => null,
                'recommended_temperature' => null,
                'distance_km' => 0,
                'notes' => 'Error calculating transport details'
            ];
        }
    }

    /**
     * Calculate distance between branches (simplified)
     * In a real application, you would use coordinates and calculate actual distance
     */
    private function calculateDistance($fromBranch, $toBranch): float
    {
        // TODO: Implement actual distance calculation using coordinates
        // For now, return a random distance between 5-100 km
        // In production, use coordinates from branches to calculate real distance
        return round(rand(5, 100) + (rand(0, 99) / 100), 2);
    }

    /**
     * Calculate estimated time between branches
     */
    private function calculateEstimatedTime($fromBranch, $toBranch): float
    {
        // Calculate based on distance (simplified)
        // Assuming average speed of 40 km/h in urban areas
        $distance = $this->calculateDistance($fromBranch, $toBranch);
        $estimatedHours = $distance / 40; // 40 km/h average

        // Add fixed time for loading/unloading
        $estimatedHours += 0.5;

        return round($estimatedHours, 2);
    }

    /**
     * Auto-assign available driver
     * Optimized to select only needed columns
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

            if (!$driver) {
                // Try to find any available driver in the system
                $driver = User::where('role', 'driver')
                    ->where('status', 'active')
                    ->whereDoesntHave('currentTransports', function ($query) {
                        $query->whereIn('status', ['in_transit', 'loading', 'unloading']);
                    })
                    ->select('id', 'name', 'phone', 'email', 'vehicle_number', 'vehicle_type', 'vehicle_capacity')
                    ->first();
            }

            if (!$driver) {
                return null;
            }

            return [
                'id' => $driver->id,
                'name' => $driver->name,
                'phone' => $driver->phone ?? null,
                'email' => $driver->email,
                'vehicle_number' => $driver->vehicle_number ?? 'N/A',
                'vehicle_type' => $driver->vehicle_type ?? 'Truck',
                'capacity_kg' => $driver->vehicle_capacity ?? 1000,
            ];
        } catch (\Exception $e) {
            Log::error('Error auto-assigning driver: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get Direct Supplier Items with prices
     *
     * Returns suppliers for a specific item with prices (similar structure to getBranches).
     *
     * Filters:
     * - status: Filter by supplier status (online, offline, away)
     * - max_delivery_hours: Maximum delivery time in hours
     * - max_distance_km: Maximum distance in kilometers
     * - search: Search by supplier name
     * - sort_by: Sort results (price, delivery_time, rating)
     * - sort_order: Sort order (asc, desc)
     *
     * Input:
     * - item_id (required)
     * - quantity (optional, default: 1)
     *
     * @group New Order
     */
    public function getDirectSupplierItems(GetDirectSupplierItemsRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $itemId = $validated['item_id'];
            $quantity = isset($validated['quantity']) ? (float) $validated['quantity'] : 1.0;
            $branchId = auth()->user()->branch_id;

            // Get branch item
            $branchItem = BranchItem::where('branch_id', $branchId)
                ->where('id', $itemId)
                ->first();

            if (!$branchItem) {
                return $this->errorResponse('Item not found in your branch', 404);
            }

            // Prepare filters
            $filters = [
                'status' => $validated['status'] ?? null,
                'max_delivery_hours' => isset($validated['max_delivery_hours']) ? (int) $validated['max_delivery_hours'] : null,
                'max_distance_km' => isset($validated['max_distance_km']) ? (float) $validated['max_distance_km'] : null,
                'search' => $validated['search'] ?? null,
            ];

            // Validate filters
            if (!empty($filters['max_delivery_hours']) && $filters['max_delivery_hours'] < 1) {
                return $this->errorResponse('max_delivery_hours must be a positive number', 400);
            }

            if (!empty($filters['max_distance_km']) && $filters['max_distance_km'] < 0) {
                return $this->errorResponse('max_distance_km must be a positive number', 400);
            }

            // Get all supplier items for this item
            $query = SupplierItem::where('item_id', $itemId)
                ->with('supplier')
                ->available()
                ->whereHas('supplier', fn($q) => $q->active());

            // Apply status filter
            if (!empty($filters['status'])) {
                $statusEnum = \Modules\Purchase\Enums\SupplierStatus::from($filters['status']);
                $query->whereHas('supplier', fn($q) => $q->byStatus($statusEnum));
            }

            // Apply filters
            if (!empty($filters['max_delivery_hours'])) {
                $query->byDeliveryTime($filters['max_delivery_hours']);
            }

            if (!empty($filters['search'])) {
                $query->whereHas('supplier', fn($q) => $q->search($filters['search']));
            }

            $supplierItems = $query->get();

            // Get item logo URL
            $itemLogo = null;
            if ($branchItem->item_logo) {
                if (is_array($branchItem->item_logo)) {
                    $logo = $branchItem->item_logo[0] ?? null;
                } else {
                    $logo = $branchItem->item_logo;
                }

                if ($logo) {
                    $itemLogo = str_starts_with($logo, 'http')
                        ? $logo
                        : asset('storage/' . $logo);
                }
            }

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
                if (!$supplier) {
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
                if (!empty($filters['max_distance_km']) && $distanceKm && $distanceKm > $filters['max_distance_km']) {
                    return null;
                }

                // Calculate availability (assuming suppliers can always provide, but we can check min_order_quantity)
                $availabilityPercentage = 100; // Default: always available
                if ($supplierItem->max_order_quantity) {
                    $maxAvailable = (float) $supplierItem->max_order_quantity;
                    $availabilityPercentage = min(100, round(($maxAvailable / $quantity) * 100, 1));
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
                    'delivery_days' => $supplierItem->delivery_hours ? round($supplierItem->delivery_hours / 24, 1) : null,
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

            return $this->successResponse(
                $suppliersList,
                'Direct supplier items retrieved successfully'
            );
        } catch (\Exception $e) {
            Log::error('Error fetching direct supplier items: ' . $e->getMessage(), [
                'exception' => $e,
                'request' => $request->all(),
            ]);

            return $this->errorResponse(
                'Error in fetching direct supplier items: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Sort suppliers by specified criteria
     */
    private function sortSuppliers($suppliers, string $sortBy, string $sortOrder)
    {
        $isAscending = strtolower($sortOrder) === 'asc';

        $sortKey = match ($sortBy) {
            'price' => 'price_rate',
            'delivery_time' => 'delivery_hours',
            'rating' => 'rating',
            default => 'price_rate',
        };

        $sorted = $isAscending
            ? $suppliers->sortBy(function ($item) use ($sortKey) {
                $value = $item[$sortKey] ?? null;
                if ($value === null) {
                    return $sortKey === 'price_rate' ? PHP_FLOAT_MAX : PHP_FLOAT_MIN;
                }
                return $value;
            })
            : $suppliers->sortByDesc(function ($item) use ($sortKey) {
                $value = $item[$sortKey] ?? null;
                if ($value === null) {
                    return $sortKey === 'price_rate' ? PHP_FLOAT_MIN : PHP_FLOAT_MAX;
                }
                return $value;
            });

        return $sorted->values();
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
        if (!$from || !$to) {
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

        // Estimate hours (assuming average speed of 40 km/h in urban areas)
        $estimatedHours = ($distanceKm / 40) + 0.5; // Add 0.5 hours for loading/unloading

        return [
            'distance_km' => $distanceKm,
            'estimated_hours' => $estimatedHours,
        ];
    }

    /**
     * Get Purchasing Officer Items with price comparison
     *
     * Returns a list of items from purchasing officer with price comparison.
     * Similar structure to getTransferItems.
     *
     * Filters:
     * - search: Search by item name or code
     * - category: Filter by category
     * - per_page: Items per page (default: 15)
     *
     * @group New Order
     */
    public function getPurchasingOfficerItems(GetPurchasingOfficerItemsRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $branchId = auth()->user()->branch_id;
            $perPage = $validated['per_page'] ?? 15;

            // Get branch items with filters (similar to getTransferItems)
            $query = BranchItem::where('branch_id', $branchId);

            // Apply filters
            if (!empty($validated['search'])) {
                $query->where(function ($q) use ($validated) {
                    $q->where('item_name', 'like', '%' . $validated['search'] . '%')
                        ->orWhere('item_code', 'like', '%' . $validated['search'] . '%');
                });
            }

            if (!empty($validated['category'])) {
                $query->where('category', $validated['category']);
            }

            $branchItems = $query->orderBy('item_name', 'asc')->paginate($perPage);
            $itemsCollection = $branchItems->getCollection();

            // Get all item IDs
            $itemIds = $itemsCollection->pluck('id')->toArray();

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
            if (!empty($itemIds)) {
                $supplierItems = SupplierItem::whereIn('item_id', $itemIds)
                    ->with('supplier')
                    ->available()
                    ->whereHas('supplier', fn($q) => $q->active())
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
                $itemId = $branchItem->id;

                // Get prices
                $poPrice = $poPrices[$itemId] ?? null;
                $directSupplierPrice = $supplierPricesMap[$itemId] ?? null;

                $poUnitPrice = $poPrice ? (float) $poPrice['unit_price'] : 0;
                $directSupplierUnitPrice = $directSupplierPrice ? (float) $directSupplierPrice : 0;

                // Use default quantity of 1 for comparison (can be edited by user)
                $defaultQuantity = 1.0;

                $directSupplierTotal = $directSupplierUnitPrice * $defaultQuantity;
                $poTotal = $poUnitPrice * $defaultQuantity;
                $savings = $directSupplierTotal - $poTotal;

                // Add to totals
                $totalDirectSupplierAmount += $directSupplierTotal;
                $totalPurchasingOfficerAmount += $poTotal;
                $totalExpectedSavings += $savings;

                // Return structured data for the resource (similar to getTransferItems)
                return [
                    'item' => $branchItem,
                    'quantity' => $defaultQuantity, // Editable
                    'quality' => 'standard', // Editable, default
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

            // Get paginated response
            $response = $this->successResponse(
                $resourceCollection,
                'Purchasing officer items retrieved successfully'
            );

            // Add price_comparison_summary to the response data (similar to transport_summary in getTransferItems)
            $responseData = $response->getData(true);
            $responseData['price_comparison_summary'] = [
                'total_amount_from_direct_supplier' => round($totalDirectSupplierAmount, 2),
                'total_amount_via_purchasing_officer' => round($totalPurchasingOfficerAmount, 2),
                'total_expected_savings' => round($totalExpectedSavings, 2),
            ];

            return response()->json($responseData, 200);
        } catch (\Exception $e) {
            Log::error('Error fetching purchasing officer items: ' . $e->getMessage(), [
                'exception' => $e,
                'request' => $request->all(),
            ]);

            return $this->errorResponse(
                'Error in fetching purchasing officer items: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get Supplier Items
     *
     * Returns a list of items from a specific supplier with supplier details.
     * Similar structure to getTransferItems.
     *
     * Filters:
     * - item_id: Filter by specific item
     * - search: Search by item name or code
     * - category: Filter by category
     * - per_page: Items per page (default: 15)
     *
     * Input:
     * - supplier_id (required)
     *
     * @group New Order
     */
    public function getSupplierItems(GetSupplierItemsRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $supplierId = $validated['supplier_id'];
            $branchId = auth()->user()->branch_id;
            $perPage = $validated['per_page'] ?? 15;

            // Get supplier with details
            $supplier = PurchaseSupplier::find($supplierId);
            if (!$supplier) {
                return $this->errorResponse('Supplier not found', 404);
            }

            // Get supplier items
            $query = SupplierItem::where('supplier_id', $supplierId)
                ->available();

            // Filter by specific item_id if provided
            if (!empty($validated['item_id'])) {
                $query->where('item_id', $validated['item_id']);
            }

            $supplierItems = $query->get();

            if ($supplierItems->isEmpty()) {
                return $this->successResponse(
                    [
                        'supplier' => (new SupplierResource($supplier))->toArray(request()),
                        'items' => [],
                    ],
                    'Supplier items retrieved successfully'
                );
            }

            // Get BranchItems referenced by SupplierItems
            $supplierItemIds = $supplierItems->pluck('item_id')->toArray();
            $referencedBranchItems = BranchItem::whereIn('id', $supplierItemIds)
                ->select('id', 'item_name', 'item_code', 'item_unit', 'category', 'subcategory')
                ->get()
                ->keyBy('id');

            // Get branch items in current branch that match the referenced items by name/code
            $itemNames = $referencedBranchItems->pluck('item_name')->unique()->toArray();
            $itemCodes = $referencedBranchItems->pluck('item_code')->unique()->toArray();

            $branchItemsQuery = BranchItem::where('branch_id', $branchId)
                ->where(function ($q) use ($itemNames, $itemCodes, $validated) {
                    $q->whereIn('item_name', $itemNames)
                        ->orWhereIn('item_code', $itemCodes);

                    // If specific item_id is requested, also check by id
                    if (!empty($validated['item_id'])) {
                        $q->orWhere('id', $validated['item_id']);
                    }
                });

            // Apply filters
            if (!empty($validated['search'])) {
                $branchItemsQuery->where(function ($q) use ($validated) {
                    $q->where('item_name', 'like', '%' . $validated['search'] . '%')
                        ->orWhere('item_code', 'like', '%' . $validated['search'] . '%');
                });
            }

            if (!empty($validated['category'])) {
                $branchItemsQuery->where('category', $validated['category']);
            }

            $branchItems = $branchItemsQuery->paginate($perPage);
            $itemsCollection = $branchItems->getCollection();

            // Create maps for matching
            // Map by item_id (direct match)
            $supplierItemsByIdMap = $supplierItems->keyBy('item_id');
            // Map by item_name and item_code (for cross-branch matching)
            $supplierItemsByNameMap = $supplierItems->mapWithKeys(function ($supplierItem) use ($referencedBranchItems) {
                $refItem = $referencedBranchItems[$supplierItem->item_id] ?? null;
                if (!$refItem) {
                    return [];
                }
                $key = $refItem->item_name . '|' . $refItem->item_code;
                return [$key => $supplierItem];
            });

            // Transform the collection for the resource
            $transformedItems = $itemsCollection->map(function ($branchItem) use (
                $supplierItemsByIdMap,
                $supplierItemsByNameMap
            ) {
                // Try direct match by id first
                $supplierItem = $supplierItemsByIdMap[$branchItem->id] ?? null;

                // If not found, try matching by name and code
                if (!$supplierItem) {
                    $key = $branchItem->item_name . '|' . $branchItem->item_code;
                    $supplierItem = $supplierItemsByNameMap[$key] ?? null;
                }

                if (!$supplierItem) {
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

            // Get paginated response
            $response = $this->successResponse(
                $resourceCollection,
                'Supplier items retrieved successfully'
            );

            // Add supplier details to the response data
            $responseData = $response->getData(true);
            $responseData['supplier'] = (new SupplierResource($supplier))->toArray(request());

            return response()->json($responseData, 200);
        } catch (\Exception $e) {
            Log::error('Error fetching supplier items: ' . $e->getMessage(), [
                'exception' => $e,
                'request' => $request->all(),
            ]);

            return $this->errorResponse(
                'Error in fetching supplier items: ' . $e->getMessage(),
                500
            );
        }
    }
}
