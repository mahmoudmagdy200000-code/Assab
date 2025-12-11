<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Http\Requests\ApproveOrderRequest;
use Modules\Purchase\Http\Requests\ApproveTransferRequest;
use Modules\Purchase\Http\Requests\FilterPendingOrdersRequest;
use Modules\Purchase\Http\Requests\RejectOrderRequest;
use Modules\Purchase\Services\PurchaseOrderService;
use Modules\Purchase\Services\TimelineService;
use Modules\Purchase\Transformers\DirectSupplierOrderResource;
use Modules\Purchase\Transformers\InternalTransferOrderResource;
use Modules\Purchase\Transformers\PendingOrderListResource;
use Modules\Purchase\Transformers\PurchaseOrderResource;
use Modules\Purchase\Transformers\TimelineResource;
use Modules\Purchase\Transformers\ViaPurchasingOfficerOrderResource;

class PendingOrderController extends BaseController
{
    public function __construct(
        private readonly PurchaseOrderService $orderService,
        private readonly TimelineService $timelineService
    ) {}

    /**
     * Get pending orders list
     *
     * @group Pending Orders
     */
    public function index(FilterPendingOrdersRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $filters['branch_id'] = auth()->user()->branch_id;

            $orders = $this->orderService->getPendingOrders($filters, $request->get('per_page', 15));

            return $this->paginatedResponse(
                PendingOrderListResource::collection($orders),
                'Pending orders retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching pending orders');
        }
    }

    /**
     * Get order details
     *
     * @group Pending Orders
     */
    public function show(string $id): JsonResponse
    {
        try {
            $order = $this->orderService->getOrderDetails($id);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            // Log view event
            $this->timelineService->logOrderViewed($order, auth()->id());

            return $this->successResponse(
                new PurchaseOrderResource($order),
                'Order details retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching order details');
        }
    }

    /**
     * Approve order
     *
     * @group Pending Orders
     */
    public function approve(ApproveOrderRequest $request, string $id): JsonResponse
    {
        try {
            $order = $this->orderService->getOrderDetails($id);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            $success = $this->orderService->confirmOrder($order, $request->get('items'));

            if (!$success) {
                return $this->errorResponse('Cannot approve order in current status', 400);
            }

            return $this->successResponse(
                new PurchaseOrderResource($order->fresh(['items'])),
                'Order approved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'approving order');
        }
    }

    /**
     * Partially approve order
     *
     * @group Pending Orders
     */
    public function partialApprove(ApproveOrderRequest $request, string $id): JsonResponse
    {
        try {
            $order = $this->orderService->getOrderDetails($id);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            $success = $this->orderService->partialConfirmOrder($order, $request->get('items'));

            if (!$success) {
                return $this->errorResponse('Cannot partially approve order', 400);
            }

            return $this->successResponse(
                new PurchaseOrderResource($order->fresh(['items'])),
                'Order partially approved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'partially approving order');
        }
    }

    /**
     * Reject order
     *
     * @group Pending Orders
     */
    public function reject(RejectOrderRequest $request, string $id): JsonResponse
    {
        try {
            $order = $this->orderService->getOrderDetails($id);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            $success = $this->orderService->rejectOrder($order, $request->reason);

            if (!$success) {
                return $this->errorResponse('Cannot reject order in current status', 400);
            }

            return $this->successResponse(
                new PurchaseOrderResource($order->fresh()),
                'Order rejected successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'rejecting order');
        }
    }

    /**
     * Cancel order
     *
     * @group Pending Orders
     */
    public function cancel(RejectOrderRequest $request, string $id): JsonResponse
    {
        try {
            $order = $this->orderService->getOrderDetails($id);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            $success = $this->orderService->cancelOrder($order, $request->reason);

            if (!$success) {
                return $this->errorResponse('Cannot cancel order in current status', 400);
            }

            return $this->successResponse(
                new PurchaseOrderResource($order->fresh()),
                'Order canceled successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'canceling order');
        }
    }

    /**
     * Approve transfer request (for received transfers)
     *
     * @group Pending Orders
     */
    public function approveTransfer(ApproveTransferRequest $request, string $id): JsonResponse
    {
        try {
            $order = $this->orderService->getOrderDetails($id);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            $action = $request->action;

            $success = match ($action) {
                'approve_all' => $this->orderService->confirmOrder($order),
                'partial_approve' => $this->orderService->partialConfirmOrder($order, $request->get('items')),
                'reject_all' => $this->orderService->rejectOrder($order, $request->reason),
                default => false,
            };

            if (!$success) {
                return $this->errorResponse('Cannot process transfer request', 400);
            }

            // Update ready time
            if ($action !== 'reject_all') {
                $order->update(['ready_time' => $request->ready_time]);
            }

            return $this->successResponse(
                new PurchaseOrderResource($order->fresh(['items'])),
                'Transfer request processed successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'processing transfer request');
        }
    }

    /**
     * Get order timeline
     *
     * @group Pending Orders
     */
    public function timeline(string $id): JsonResponse
    {
        try {
            $timeline = $this->orderService->getOrderTimeline($id);

            return $this->successResponse(
                TimelineResource::collection($timeline),
                'Order timeline retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching order timeline');
        }
    }

    /**
     * Approve order modifications
     *
     * @group Pending Orders
     */
    public function approveModifications(string $id): JsonResponse
    {
        try {
            $order = $this->orderService->getOrderDetails($id);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            $success = $this->orderService->approveModifications($order);

            if (!$success) {
                return $this->errorResponse('Cannot approve modifications', 400);
            }

            return $this->successResponse(
                new PurchaseOrderResource($order->fresh(['items'])),
                'Modifications approved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'approving modifications');
        }
    }

    /**
     * Reject order modifications
     *
     * @group Pending Orders
     */
    public function rejectModifications(RejectOrderRequest $request, string $id): JsonResponse
    {
        try {
            $order = $this->orderService->getOrderDetails($id);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            $success = $this->orderService->rejectModifications($order, $request->reason);

            if (!$success) {
                return $this->errorResponse('Cannot reject modifications', 400);
            }

            return $this->successResponse(
                new PurchaseOrderResource($order->fresh()),
                'Modifications rejected successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'rejecting modifications');
        }
    }

    /**
     * Get Direct Supplier Orders list
     *
     * @group Pending Orders - Direct Supplier
     */
    public function directSupplierOrders(FilterPendingOrdersRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $filters['branch_id'] = auth()->user()->branch_id;
            $filters['type'] = 'direct_supplier'; // Force direct_supplier type

            $orders = $this->orderService->getPendingOrders($filters, $request->get('per_page', 15));

            return $this->paginatedResponse(
                DirectSupplierOrderResource::collection($orders),
                'Direct supplier orders retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching direct supplier orders');
        }
    }

    /**
     * Get Direct Supplier Order details
     *
     * @group Pending Orders - Direct Supplier
     */
    public function directSupplierOrderDetails(string $id): JsonResponse
    {
        try {
            $order = $this->orderService->getOrderDetails($id);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            // Ensure this is a Direct Supplier Order
            if ($order->order_type !== OrderType::DIRECT_SUPPLIER) {
                return $this->errorResponse('This endpoint is only for Direct Supplier Orders', 400);
            }

            // Log view event
            $this->timelineService->logOrderViewed($order, auth()->id());

            return $this->successResponse(
                new DirectSupplierOrderResource($order),
                'Direct supplier order details retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching direct supplier order details');
        }
    }

    /**
     * Approve delay request
     *
     * @group Pending Orders - Direct Supplier
     */
    public function approveDelay(string $id): JsonResponse
    {
        try {
            $order = $this->orderService->getOrderDetails($id);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            if ($order->status !== OrderStatus::DELAYED) {
                return $this->errorResponse('Order is not in delayed status', 400);
            }

            // Approve delay: change status to confirmed
            $success = $this->orderService->confirmOrder($order);

            if (!$success) {
                return $this->errorResponse('Cannot approve delay', 400);
            }

            return $this->successResponse(
                new DirectSupplierOrderResource($order->fresh(['items', 'supplier'])),
                'Delay approved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'approving delay');
        }
    }

    /**
     * Reject delay request
     *
     * @group Pending Orders - Direct Supplier
     */
    public function rejectDelay(RejectOrderRequest $request, string $id): JsonResponse
    {
        try {
            $order = $this->orderService->getOrderDetails($id);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            if ($order->status !== OrderStatus::DELAYED) {
                return $this->errorResponse('Order is not in delayed status', 400);
            }

            // Reject delay: cancel the order
            $success = $this->orderService->cancelOrder($order, $request->reason);

            if (!$success) {
                return $this->errorResponse('Cannot reject delay', 400);
            }

            return $this->successResponse(
                new DirectSupplierOrderResource($order->fresh(['items', 'supplier'])),
                'Delay rejected. Order has been canceled.'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'rejecting delay');
        }
    }

    /**
     * Get Internal Transfer Orders list
     *
     * @group Pending Orders - Internal Transfer
     */
    public function internalTransferOrders(FilterPendingOrdersRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $filters['branch_id'] = auth()->user()->branch_id;
            $filters['type'] = 'internal_transfer'; // Force internal_transfer type

            $orders = $this->orderService->getPendingOrders($filters, $request->get('per_page', 15));

            return $this->paginatedResponse(
                InternalTransferOrderResource::collection($orders),
                'Internal transfer orders retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching internal transfer orders');
        }
    }

    /**
     * Get Internal Transfer Order details
     *
     * @group Pending Orders - Internal Transfer
     */
    public function internalTransferOrderDetails(string $id): JsonResponse
    {
        try {
            $order = $this->orderService->getOrderDetails($id);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            // Ensure this is an Internal Transfer Order
            if ($order->order_type !== OrderType::INTERNAL_TRANSFER) {
                return $this->errorResponse('This endpoint is only for Internal Transfer Orders', 400);
            }

            // Log view event
            $this->timelineService->logOrderViewed($order, auth()->id());

            return $this->successResponse(
                new InternalTransferOrderResource($order),
                'Internal transfer order details retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching internal transfer order details');
        }
    }

    /**
     * Get Via Purchasing Officer Orders list
     *
     * @group Pending Orders - Via Purchasing Officer
     */
    public function viaPurchasingOfficerOrders(FilterPendingOrdersRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $filters['branch_id'] = auth()->user()->branch_id;
            $filters['type'] = 'via_purchasing_officer'; // Force via_purchasing_officer type

            $orders = $this->orderService->getPendingOrders($filters, $request->get('per_page', 15));

            return $this->paginatedResponse(
                ViaPurchasingOfficerOrderResource::collection($orders),
                'Via purchasing officer orders retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching via purchasing officer orders');
        }
    }

    /**
     * Get Via Purchasing Officer Order details
     *
     * @group Pending Orders - Via Purchasing Officer
     */
    public function viaPurchasingOfficerOrderDetails(string $id): JsonResponse
    {
        try {
            $order = $this->orderService->getOrderDetails($id);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            // Ensure this is a Via Purchasing Officer Order
            if ($order->order_type !== OrderType::VIA_PURCHASING_OFFICER) {
                return $this->errorResponse('This endpoint is only for Via Purchasing Officer Orders', 400);
            }

            // Log view event
            $this->timelineService->logOrderViewed($order, auth()->id());

            return $this->successResponse(
                new ViaPurchasingOfficerOrderResource($order),
                'Via purchasing officer order details retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching via purchasing officer order details');
        }
    }
}
