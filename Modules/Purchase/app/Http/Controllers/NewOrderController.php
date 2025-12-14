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
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Http\Requests\GetTransferItemsRequest;
// Add these imports
use Modules\Branch\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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
     */
    private function sortBranches($branches, string $sortBy, string $sortOrder)
    {
        $isAscending = strtolower($sortOrder) === 'asc';

        return $branches->sort(function ($a, $b) use ($sortBy, $isAscending) {
            $valueA = match ($sortBy) {
                'distance' => $a['distance_km'] ?? PHP_FLOAT_MAX,
                'response_rate' => $a['response_rate'] ?? 0,
                'rating' => $a['rating'] ?? 0,
                'availability' => $a['availability_percentage'] ?? 0,
                default => $a['distance_km'] ?? PHP_FLOAT_MAX,
            };

            $valueB = match ($sortBy) {
                'distance' => $b['distance_km'] ?? PHP_FLOAT_MAX,
                'response_rate' => $b['response_rate'] ?? 0,
                'rating' => $b['rating'] ?? 0,
                'availability' => $b['availability_percentage'] ?? 0,
                default => $b['distance_km'] ?? PHP_FLOAT_MAX,
            };

            // Handle null values - put them at the end
            if ($valueA === null && $valueB === null) {
                return 0;
            }
            if ($valueA === null) {
                return 1;
            }
            if ($valueB === null) {
                return -1;
            }

            if ($isAscending) {
                return $valueA <=> $valueB;
            } else {
                return $valueB <=> $valueA;
            }
        })->values();
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
            $order = PurchaseOrder::with([
                'items',
                'supplier',
                'branch',
                'fromBranch',
                'requestedBy',
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
            if (!empty($validated['item_id'])) {
                $inventoryItem = BranchInventory::where('branch_id', $fromBranchId)
                    ->where('item_id', $validated['item_id'])
                    ->first();
            }

            // Get items from the transferring branch
            $query = BranchItem::where('branch_id', $fromBranchId)
                ->with([
                    'branch:id,name,location',
                ]);

            // Filter by specific item_id if provided
            if (!empty($validated['item_id'])) {
                if ($inventoryItem) {
                    // Found in inventory - get the original BranchItem
                    $originalItem = BranchItem::find($validated['item_id']);
                    if ($originalItem) {
                        // Check if BranchItem with this ID exists in THIS branch
                        $branchItemInBranch = BranchItem::where('branch_id', $fromBranchId)
                            ->where('id', $validated['item_id'])
                            ->exists();

                        if ($branchItemInBranch) {
                            // Item exists in this branch with the same ID - use it directly
                            $query->where('id', $validated['item_id']);
                        } else {
                            // Item exists in inventory but not in BranchItem in this branch
                            // Find by item_name in this branch (will use original item in mapping)
                            $query->where('item_name', $originalItem->item_name);
                        }
                    } else {
                        // If original BranchItem not found, try direct match
                        $query->where('id', $validated['item_id']);
                    }
                } else {
                    // Not in inventory - check if BranchItem exists in this branch directly
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

            // Transform the collection for the resource
            $transformedItems = $items->getCollection()->map(function ($item) use ($fromBranchId, $toBranchId, $transportDetails, $requestedItemId) {
                // Use requested item_id for inventory lookup (this is what BranchInventory uses)
                $itemIdForInventory = $requestedItemId ?? $item->id;

                // Get inventory from transferring branch using requested item_id
                $fromInventory = BranchInventory::where('branch_id', $fromBranchId)
                    ->where('item_id', $itemIdForInventory)
                    ->first();

                // Get inventory from receiving branch (optional, for reference)
                $toInventory = BranchInventory::where('branch_id', $toBranchId)
                    ->where('item_id', $itemIdForInventory)
                    ->first();

                // If requested item_id is different from found item, use the original item
                if ($requestedItemId && $item->id !== $requestedItemId) {
                    // Get the original BranchItem with requested id to use its data
                    $originalItem = BranchItem::find($requestedItemId);
                    if ($originalItem) {
                        // Use original item's data
                        $itemForResponse = $originalItem;
                    } else {
                        // If original not found, clone the found item with requested id
                        $itemForResponse = clone $item;
                        $itemForResponse->id = $requestedItemId;
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
                    'requested_item_id' => $requestedItemId, // Pass requested id to resource
                ];
            });

            // If no items found but item_id was requested and exists in inventory, create item from original
            if ($transformedItems->isEmpty() && $requestedItemId && $inventoryItem) {
                // Get the original BranchItem (could be from any branch)
                $originalItem = BranchItem::find($requestedItemId);
                if ($originalItem) {
                    $toInventory = BranchInventory::where('branch_id', $toBranchId)
                        ->where('item_id', $requestedItemId)
                        ->first();

                    // Create a collection with one item
                    $transformedItems = collect([[
                        'item' => $originalItem,
                        'from_inventory' => $inventoryItem,
                        'to_inventory' => $toInventory,
                        'transport_details' => $transportDetails,
                        'requested_item_id' => $requestedItemId,
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
            // Get branches
            $fromBranch = Branch::find($fromBranchId);
            $toBranch = Branch::find($toBranchId);

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
     */
    private function autoAssignDriver(string $branchId): ?array
    {
        try {
            // Check for available drivers in the branch
            $driver = User::where('branch_id', $branchId)
                ->where('role', 'driver')
                ->where('status', 'active') // Assuming you have a status field
                ->whereDoesntHave('currentTransports', function ($query) {
                    $query->whereIn('status', ['in_transit', 'loading', 'unloading']);
                })
                ->first();

            if (!$driver) {
                // Try to find any available driver in the system
                $driver = User::where('role', 'driver')
                    ->where('status', 'active')
                    ->whereDoesntHave('currentTransports', function ($query) {
                        $query->whereIn('status', ['in_transit', 'loading', 'unloading']);
                    })
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
}
