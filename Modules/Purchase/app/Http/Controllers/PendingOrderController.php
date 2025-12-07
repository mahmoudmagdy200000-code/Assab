<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\Purchase\Http\Requests\ApproveOrderRequest;
use Modules\Purchase\Http\Requests\ApproveTransferRequest;
use Modules\Purchase\Http\Requests\FilterPendingOrdersRequest;
use Modules\Purchase\Http\Requests\RejectOrderRequest;
use Modules\Purchase\Services\PurchaseOrderService;
use Modules\Purchase\Services\TimelineService;
use Modules\Purchase\Transformers\PurchaseOrderResource;
use Modules\Purchase\Transformers\TimelineResource;

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
                PurchaseOrderResource::collection($orders),
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
            
            $success = match($action) {
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
}

