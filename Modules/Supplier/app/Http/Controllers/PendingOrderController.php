<?php

namespace Modules\Supplier\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Services\PurchaseOrderService;
use Modules\Purchase\Services\TimelineService;
use Modules\Supplier\Http\Requests\Orders\AcceptOrderRequest;
use Modules\Supplier\Http\Requests\Orders\RejectOrderRequest;
use Modules\Supplier\Http\Requests\Orders\RequestModificationRequest;
use Modules\Supplier\Services\OrderService;
use Modules\Supplier\Transformers\OrderResource;

class PendingOrderController extends BaseController
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly PurchaseOrderService $purchaseOrderService,
        private readonly TimelineService $timelineService
    ) {}

    /**
     * Get pending orders list for supplier
     *
     * @group Supplier Pending Orders
     */
    public function index(): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $filters = request()->only(['status', 'date_from', 'date_to', 'search']);
            $perPage = request()->get('per_page', 15);

            // Get orders with pending statuses
            $filters['status'] = $filters['status'] ?? null;
            $orders = $this->orderService->getPendingOrders($supplier, $filters, $perPage);

            return $this->paginatedResponse(
                OrderResource::collection($orders),
                'Pending orders retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching pending orders');
        }
    }

    /**
     * Get order details
     *
     * @group Supplier Pending Orders
     */
    public function show(string $id): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $order = $this->orderService->getOrderDetails($id, $supplier);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            // Log view event
            $this->timelineService->logOrderViewed($order, $supplier->id);

            return $this->successResponse(
                new OrderResource($order),
                'Order details retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching order details');
        }
    }

    /**
     * Approve order (for PENDING_APPROVAL status)
     * Supplier approves the final order after modifications
     *
     * @group Supplier Pending Orders
     */
    public function approve(AcceptOrderRequest $request, string $id): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $order = PurchaseOrder::findOrFail($id);

            if ($order->supplier_id !== $supplier->id) {
                return $this->errorResponse('Unauthorized access to this order', 403);
            }

            if ($order->status !== OrderStatus::PENDING_APPROVAL) {
                return $this->errorResponse('Order is not in pending approval status', 400);
            }

            $readyTime = $request->validated()['ready_time'] ?? null;
            $success = $this->purchaseOrderService->confirmOrder($order, $request->get('items'), $readyTime);

            if (!$success) {
                return $this->errorResponse('Cannot approve order in current status', 400);
            }

            return $this->successResponse(
                new OrderResource($order->fresh(['items'])),
                'Order approved successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'approving order');
        }
    }

    /**
     * Partially approve order (for PARTIAL_CONFIRMATION status)
     * Supplier confirms partial quantity
     *
     * @group Supplier Pending Orders
     */
    public function partialApprove(AcceptOrderRequest $request, string $id): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $order = PurchaseOrder::findOrFail($id);

            if ($order->supplier_id !== $supplier->id) {
                return $this->errorResponse('Unauthorized access to this order', 403);
            }

            if ($order->status !== OrderStatus::PARTIAL_CONFIRMATION) {
                return $this->errorResponse('Order is not in partial confirmation status', 400);
            }

            $readyTime = $request->validated()['ready_time'] ?? null;
            $success = $this->purchaseOrderService->partialConfirmOrder($order, $request->get('items'), $readyTime);

            if (!$success) {
                return $this->errorResponse('Cannot partially approve order', 400);
            }

            return $this->successResponse(
                new OrderResource($order->fresh(['items'])),
                'Order partially approved successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'partially approving order');
        }
    }

    /**
     * Approve modifications (for PENDING_CONFIRMATION status)
     * Supplier approves branch manager's modifications
     *
     * @group Supplier Pending Orders
     */
    public function approveModifications(string $id): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $order = PurchaseOrder::findOrFail($id);

            if ($order->supplier_id !== $supplier->id) {
                return $this->errorResponse('Unauthorized access to this order', 403);
            }

            if ($order->status !== OrderStatus::PENDING_CONFIRMATION) {
                return $this->errorResponse('Order is not in pending confirmation status', 400);
            }

            $success = $this->purchaseOrderService->approveModifications($order);

            if (!$success) {
                return $this->errorResponse('Cannot approve modifications', 400);
            }

            return $this->successResponse(
                new OrderResource($order->fresh(['items'])),
                'Modifications approved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'approving modifications');
        }
    }

    /**
     * Reject modifications (for PENDING_CONFIRMATION status)
     * Supplier rejects branch manager's modifications
     *
     * @group Supplier Pending Orders
     */
    public function rejectModifications(RejectOrderRequest $request, string $id): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $order = PurchaseOrder::findOrFail($id);

            if ($order->supplier_id !== $supplier->id) {
                return $this->errorResponse('Unauthorized access to this order', 403);
            }

            if ($order->status !== OrderStatus::PENDING_CONFIRMATION) {
                return $this->errorResponse('Order is not in pending confirmation status', 400);
            }

            $success = $this->purchaseOrderService->rejectOrder($order, $request->validated()['reason']);

            if (!$success) {
                return $this->errorResponse('Cannot reject modifications', 400);
            }

            return $this->successResponse(
                new OrderResource($order->fresh()),
                'Modifications rejected successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'rejecting modifications');
        }
    }


    /**
     * Mark order as preparing
     * Supplier starts preparing the order and uploads quality certificate
     *
     * @group Supplier Pending Orders
     */
    public function markAsPreparing(string $id): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $order = PurchaseOrder::findOrFail($id);

            if ($order->supplier_id !== $supplier->id) {
                return $this->errorResponse('Unauthorized access to this order', 403);
            }

            $success = $this->purchaseOrderService->markAsPreparing($order);

            if (!$success) {
                return $this->errorResponse('Cannot mark order as preparing in current status', 400);
            }

            return $this->successResponse(
                new OrderResource($order->fresh(['items'])),
                'Order marked as preparing successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'marking order as preparing');
        }
    }

    /**
     * Mark order as on the way
     * Supplier marks order as dispatched and provides delivery details & invoice
     *
     * @group Supplier Pending Orders
     */
    public function markAsOnTheWay(): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $orderId = request()->get('order_id');
            $order = PurchaseOrder::findOrFail($orderId);

            if ($order->supplier_id !== $supplier->id) {
                return $this->errorResponse('Unauthorized access to this order', 403);
            }

            $deliveryDetails = request()->only([
                'delivery_vehicle_number',
                'driver_name',
                'driver_phone',
                'invoice_number',
                'invoice_file',
            ]);

            $success = $this->purchaseOrderService->markAsOnTheWay($order, $deliveryDetails);

            if (!$success) {
                return $this->errorResponse('Cannot mark order as on the way in current status', 400);
            }

            return $this->successResponse(
                new OrderResource($order->fresh(['items'])),
                'Order marked as on the way successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'marking order as on the way');
        }
    }

    /**
     * Report delay
     * Supplier requests delivery date/time extension
     *
     * @group Supplier Pending Orders
     */
    public function reportDelay(): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $orderId = request()->get('order_id');
            $reason = request()->get('reason');
            $newDeliveryDate = request()->get('new_delivery_date');

            if (empty($reason)) {
                return $this->errorResponse('Reason is required', 400);
            }

            $order = PurchaseOrder::findOrFail($orderId);

            if ($order->supplier_id !== $supplier->id) {
                return $this->errorResponse('Unauthorized access to this order', 403);
            }

            $success = $this->purchaseOrderService->reportDelay($order, $reason, $newDeliveryDate);

            if (!$success) {
                return $this->errorResponse('Cannot report delay in current status', 400);
            }

            return $this->successResponse(
                new OrderResource($order->fresh(['items'])),
                'Delay reported successfully. Waiting for branch manager approval.'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'reporting delay');
        }
    }

    /**
     * Get order timeline
     *
     * @group Supplier Pending Orders
     */
    public function timeline(string $id): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $order = PurchaseOrder::findOrFail($id);

            if ($order->supplier_id !== $supplier->id) {
                return $this->errorResponse('Unauthorized access to this order', 403);
            }

            $timeline = $this->purchaseOrderService->getOrderTimeline($id);

            return $this->successResponse(
                $timeline,
                'Order timeline retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching order timeline');
        }
    }
}

