<?php

namespace Modules\Supplier\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\SupplierItem;
use Modules\Supplier\Http\Requests\Orders\AcceptOrderRequest;
use Modules\Supplier\Http\Requests\Orders\RejectOrderRequest;
use Modules\Supplier\Http\Requests\Orders\RequestModificationRequest;
use Modules\Supplier\Services\OrderService;
use Modules\Supplier\Transformers\OrderResource;

class OrderController extends BaseController
{
    public function __construct(
        private readonly OrderService $orderService
    ) {}

    /**
     * Get orders list with filters
     */
    public function index(): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $filters = request()->only(['status', 'date_from', 'date_to', 'search']);
            $perPage = request()->get('per_page', 15);

            $orders = $this->orderService->getOrders($supplier, $filters, $perPage);

            return $this->paginatedResponse(
                OrderResource::collection($orders),
                'Orders retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching orders');
        }
    }

    /**
     * Get order details
     */
    public function show(string $id): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $order = $this->orderService->getOrderDetails($id, $supplier);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            return $this->successResponse(
                new OrderResource($order),
                'Order details retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching order details');
        }
    }

    /**
     * Accept order
     */
    public function accept(AcceptOrderRequest $request, string $id): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $order = PurchaseOrder::findOrFail($id);

            $order = $this->orderService->acceptOrder($order, $supplier, $request->validated());

            return $this->successResponse(
                new OrderResource($order),
                'Order accepted successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'accepting order');
        }
    }

    /**
     * Reject order
     */
    public function reject(RejectOrderRequest $request, string $id): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $order = PurchaseOrder::findOrFail($id);

            $order = $this->orderService->rejectOrder($order, $supplier, $request->validated());

            return $this->successResponse(
                new OrderResource($order),
                'Order rejected successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'rejecting order');
        }
    }

    /**
     * Request order modification
     */
    public function requestModification(RequestModificationRequest $request, string $id): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $order = PurchaseOrder::findOrFail($id);

            $order = $this->orderService->requestModification($order, $supplier, $request->validated());

            return $this->successResponse(
                new OrderResource($order),
                'Modification request submitted successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'requesting modification');
        }
    }

    /**
     * Get order dashboard statistics
     */
    public function dashboard(): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $stats = $this->orderService->getDashboardStats($supplier);

            return $this->successResponse($stats, 'Dashboard statistics retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching dashboard statistics');
        }
    }

    /**
     * Get supplier items for logged in supplier
     *
     * Filters:
     * - Search: by item name
     * - is_available: filter by availability
     *
     * @group Supplier Orders
     */
    public function getSupplierItems(): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $filters = request()->only(['search', 'is_available']);
            $perPage = request()->get('per_page', 15);

            // Build query
            $query = SupplierItem::where('supplier_id', $supplier->id)
                ->with(['item' => function ($q) {
                    $q->select('id', 'name', 'code', 'unit', 'logo', 'category', 'subcategory');
                }]);

            // Search by item name (through Item model)
            if (!empty($filters['search'])) {
                $searchTerm = $filters['search'];
                $query->whereHas('item', function ($q) use ($searchTerm) {
                    $q->where('name', 'like', '%' . $searchTerm . '%');
                });
            }

            // Filter by availability
            if (isset($filters['is_available'])) {
                $query->where('is_available', filter_var($filters['is_available'], FILTER_VALIDATE_BOOLEAN));
            }

            $items = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return $this->paginatedResponse(
                $items,
                'Supplier items retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching supplier items');
        }
    }
}
