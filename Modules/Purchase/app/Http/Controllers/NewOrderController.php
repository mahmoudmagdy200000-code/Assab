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
use Modules\Purchase\Services\OrderDataService;
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
use Modules\Purchase\Http\Requests\GetTransferItemsRequest;
use Modules\Purchase\Http\Requests\GetDirectSupplierItemsRequest;
use Modules\Purchase\Http\Requests\GetPurchasingOfficerItemsRequest;
use Modules\Purchase\Http\Requests\GetSupplierItemsRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class NewOrderController extends BaseController
{
    public function __construct(
        private readonly PurchaseOrderService $orderService,
        private readonly PriceComparisonService $priceService,
        private readonly OrderDataService $dataService
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

            $result = $this->dataService->getOrdersList($filters, $perPage);

            return response()->json([
                'success' => true,
                'message' => 'Orders retrieved successfully',
                'data' => $result['data'],
                'links' => $result['links'],
                'meta' => $result['meta'],
            ], 200);
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
     * Query Parameters:
     * - item_id (required): UUID of the item
     * - quantity (optional): Quantity to compare prices for (default: 1)
     *
     * @group New Order
     */
    public function comparePrices(ComparePricesRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $comparison = $this->priceService->comparePrices(
                $validated['item_id'],
                $validated['quantity'] ?? null,
                $request->user()?->branch_id
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
     * Returns branches with available stock from daily inventory including:
     * - Branch Name, Image, Manager Name
     * - Available Quantity (from daily inventory, e.g., "10 KG Available")
     * - Item Details (Title, Logo, Quantity, Total Amount)
     * - Store Details (Distance, Response Rate, Last Update, Rating)
     *
     * Filters:
     * - availability: Minimum availability percentage (All, 60%, 70%, 80%, 90%, 100%)
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
            // Support both 'availability' (new) and 'min_availability' (legacy) for backward compatibility
            $availability = $request->get('availability') ?? $request->get('min_availability');

            // Convert availability filter to min_availability
            // If "All" or empty, no filter. Otherwise use the percentage value
            $minAvailability = null;
            if (!empty($availability) && $availability !== 'All' && $availability !== 'all') {
                // Remove % sign if present and convert to float
                $availabilityValue = is_numeric($availability)
                    ? (float) $availability
                    : (float) str_replace('%', '', $availability);

                // Validate availability values (60, 70, 80, 90, 100)
                $allowedValues = [60, 70, 80, 90, 100];
                if (in_array($availabilityValue, $allowedValues)) {
                    $minAvailability = $availabilityValue;
                }
            }

            $filters = [
                'min_availability' => $minAvailability,
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

            // Validate availability filter
            if ($minAvailability !== null && !in_array($minAvailability, [60, 70, 80, 90, 100])) {
                return $this->errorResponse('Invalid availability filter. Must be: All, 60, 70, 80, 90, or 100', 400);
            }

            // Get branches with filters applied
            $branches = $this->priceService->getBranchesWithStock($itemId, $quantity, $branchId, $filters);

            // Apply sorting if requested
            $sortBy = $request->get('sort_by', 'distance'); // default: sort by distance
            $sortOrder = $request->get('sort_order', 'asc'); // default: ascending

            $branches = $this->dataService->sortBranches($branches, $sortBy, $sortOrder);

            return $this->successResponse(
                $branches->values(),
                'Branches retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching branches');
        }
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
                // Validate user and branch
                $user = auth()->user();
                if (!$user) {
                    return $this->unauthorizedResponse('User not authenticated');
                }

                $branchId = $user->branch_id;
                if (!$branchId) {
                    return $this->errorResponse(
                        'User must be associated with a branch to create orders',
                        400
                    );
                }

                $requestedBy = auth()->id();
                if (!$requestedBy) {
                    return $this->unauthorizedResponse('User ID not found');
                }

                $isDraft = $data['is_draft'] ?? false;
                $isEmergency = ($data['is_emergency'] ?? false) === true;

                // Log request details for debugging
                Log::info('Creating multiple orders', [
                    'user_id' => $requestedBy,
                    'branch_id' => $branchId,
                    'is_draft' => $isDraft,
                    'has_branches' => !empty($data['branches']),
                    'has_direct_supplier' => !empty($data['direct_supplier']),
                    'has_purchase_officer' => !empty($data['purchase_officer']),
                    'branches_count' => !empty($data['branches']) ? count($data['branches']) : 0,
                    'direct_supplier_count' => !empty($data['direct_supplier']) ? count($data['direct_supplier']) : 0,
                    'purchase_officer_count' => !empty($data['purchase_officer']) ? count($data['purchase_officer']) : 0,
                    'is_emergency' => $isEmergency,
                ]);
                $orders = $this->orderService->createMultipleOrders($data, $branchId, $requestedBy, $isDraft, $isEmergency);

                if ($orders->isEmpty()) {
                    return $this->errorResponse(
                        'No orders were created. Please check your request data.',
                        400
                    );
                }

                $orderCount = $orders->count();
                $orderTypes = $orders->map(function ($order) {
                    $orderType = is_string($order->order_type)
                        ? OrderType::from($order->order_type)
                        : $order->order_type;
                    return $orderType->label();
                })->unique()->values()->toArray();

                $statusMessage = $isDraft
                    ? "Successfully saved {$orderCount} order(s) as draft: " . implode(', ', $orderTypes)
                    : "Successfully created {$orderCount} order(s): " . implode(', ', $orderTypes);

                return $this->createdResponse(
                    PurchaseOrderResource::collection($orders),
                    $statusMessage
                );
            } else {
                // Fallback to single order creation (backward compatibility)
                // This handles the old request format if needed
                return $this->errorResponse(
                    'Invalid request format. Please use branches[], direct_supplier[], or purchase_officer[] arrays.',
                    400
                );
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->errors());
        } catch (\InvalidArgumentException $e) {
            Log::error('Invalid argument in store method', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
            ]);
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            Log::error('Error creating purchase order(s)', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request_data' => $request->all(),
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? null,
            ]);
            return $this->handleException($e, 'creating purchase order(s)');
        }
    }

    /**
     * Submit order (draft → pending).
     * Accepts optional same body as create/update (e.g. items) to apply changes before submitting.
     *
     * @group New Order
     */
    public function submit(Request $request, string $id): JsonResponse
    {
        try {
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            $items = $request->input('items', []);
            if (!empty($items)) {
                $this->orderService->updateItems($order, $items);
                $order->refresh();
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
     * Delete draft order
     *
     * @group New Order
     */
    public function deleteDraft(string $id): JsonResponse
    {
        try {
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            $success = $this->orderService->deleteDraftOrder($order);

            if (!$success) {
                return $this->errorResponse('Cannot delete order. Only draft orders can be deleted.', 400);
            }

            return $this->deletedResponse('Draft order deleted successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'deleting draft order');
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
            $userBranchId = auth()->user()->branch_id;
            $order = $this->dataService->getOrderSummary($id, $userBranchId);

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
            // Security: Pass branch_id to service for authorization check
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

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
            $toBranchId = auth()->user()->branch_id;
            $fromBranchId = $validated['branch_id'];

            // Security: Validate branch IDs
            if (empty($fromBranchId) || empty($toBranchId)) {
                return $this->errorResponse('Branch ID is required', 400);
            }

            // Security: Verify user has access to destination branch
            if ($toBranchId !== auth()->user()->branch_id) {
                return $this->errorResponse('Unauthorized access to destination branch', 403);
            }

            // Check if from and to branches are different
            if ($fromBranchId === $toBranchId) {
                return $this->errorResponse('From and to branches cannot be the same', 400);
            }

            $result = $this->dataService->getTransferItems($validated, $toBranchId);

            $response = $this->successResponse(
                $result['data'],
                'Transfer items retrieved successfully'
            );

            // Add transport_summary
            $responseData = $response->getData(true);
            $responseData['transport_summary'] = $result['transport_summary'];

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
            $branchId = auth()->user()->branch_id;

            // Security: Validate branch ID
            if (empty($branchId)) {
                return $this->errorResponse('User must be associated with a branch', 400);
            }

            // Validate filters
            if (!empty($validated['max_delivery_hours']) && $validated['max_delivery_hours'] < 1) {
                return $this->errorResponse('max_delivery_hours must be a positive number', 400);
            }

            if (!empty($validated['max_distance_km']) && $validated['max_distance_km'] < 0) {
                return $this->errorResponse('max_distance_km must be a positive number', 400);
            }

            $result = $this->dataService->getDirectSupplierItems($validated, $branchId);

            return $this->successResponse(
                $result['data'],
                'Direct supplier items retrieved successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 404);
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

            $result = $this->dataService->getPurchasingOfficerItems($validated, $branchId);

            $response = $this->successResponse(
                $result['data'],
                'Purchasing officer items retrieved successfully'
            );

            // Add price_comparison_summary to the response data
            $responseData = $response->getData(true);
            $responseData['price_comparison_summary'] = $result['price_comparison_summary'];

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
            $branchId = auth()->user()->branch_id;

            // Security: Validate branch ID
            if (empty($branchId)) {
                return $this->errorResponse('User must be associated with a branch', 400);
            }

            $result = $this->dataService->getSupplierItems($validated, $branchId);

            $response = $this->successResponse(
                $result['data'],
                'Supplier items retrieved successfully'
            );

            // Add supplier details to the response data
            $responseData = $response->getData(true);
            $responseData['supplier'] = $result['supplier'];

            return response()->json($responseData, 200);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 404);
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
