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
use Modules\Purchase\Transformers\BranchItemResource;
use Modules\Purchase\Transformers\OrderSummaryResource;
use Modules\Purchase\Transformers\PriceComparisonResource;
use Modules\Purchase\Transformers\PurchaseOrderResource;
use Modules\Purchase\Transformers\SupplierResource;
use Modules\Purchase\Models\PurchaseOrder;

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
     * Create purchase order
     *
     * Unified endpoint for creating orders. The order_type determines the order source:
     * - direct_supplier: Order from a supplier
     * - via_purchasing_officer: Order via purchasing officer
     * - internal_transfer: Transfer from another branch
     *
     * @group New Order
     */
    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            // Convert order_type string to enum
            $data['order_type'] = OrderType::from($data['order_type']);

            // Set common fields
            $data['branch_id'] = auth()->user()->branch_id;
            $data['requested_by'] = auth()->id();

            // For internal transfer, set to_branch_id
            if ($data['order_type'] === OrderType::INTERNAL_TRANSFER) {
                $data['to_branch_id'] = auth()->user()->branch_id;
            }

            $order = $this->orderService->createOrder($data);

            $orderTypeLabel = match ($data['order_type']) {
                OrderType::DIRECT_SUPPLIER => 'Direct supplier',
                OrderType::VIA_PURCHASING_OFFICER => 'Purchasing officer',
                OrderType::INTERNAL_TRANSFER => 'Internal transfer',
                default => 'Purchase',
            };

            return $this->createdResponse(
                new PurchaseOrderResource($order),
                "{$orderTypeLabel} order created successfully"
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'creating purchase order');
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
}
