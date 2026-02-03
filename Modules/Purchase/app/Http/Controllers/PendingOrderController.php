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
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Services\PurchaseOrderService;
use Modules\Purchase\Services\TimelineService;
use Modules\Purchase\Transformers\DirectSupplierOrderResource;
use Modules\Purchase\Transformers\InternalTransferOrderResource;
use Modules\Purchase\Transformers\ModificationDetailResource;
use Modules\Purchase\Transformers\PendingOrderListResource;
use Modules\Purchase\Transformers\PurchaseOrderListResource;
use Modules\Purchase\Transformers\PurchaseOrderResource;
use App\Http\Resources\UnifiedTimelineResource;
use Modules\Purchase\Transformers\ViaPurchasingOfficerOrderResource;
use Modules\Purchase\Models\PurchaseOrderItem;

class PendingOrderController extends BaseController
{
    public function __construct(
        private readonly PurchaseOrderService $orderService,
        private readonly TimelineService $timelineService
    ) {}

    /**
     * Get pending orders list
     *
     * Returns both orders and requested orders (same as NewOrderController)
     *
     * @group Pending Orders
     */
    public function index(FilterPendingOrdersRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $filters['branch_id'] = auth()->user()->branch_id;
            $perPage = $request->get('per_page', 15);

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

            // Transform orders with request_type
            $ordersCollection = PurchaseOrderListResource::collection($orders);
            foreach ($ordersCollection->collection as $resource) {
                $resource->additional(['request_type' => 'order']);
            }

            // Transform requested orders with request_type
            $requestedOrdersCollection = PurchaseOrderListResource::collection($requestedOrders);
            foreach ($requestedOrdersCollection->collection as $resource) {
                $resource->additional(['request_type' => 'request']);
            }

            $data = [
                'orders' => $ordersCollection,
                'requested_orders' => $requestedOrdersCollection,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Pending orders retrieved successfully',
                'data' => $data,
                'links' => $links,
                'meta' => $meta,
            ], 200);
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
            // Security: Pass branch_id to service for authorization check
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

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
            // Security: Pass branch_id to service for authorization check
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            $readyTime = $request->validated()['ready_time'] ?? null;

            try {
                $success = $this->orderService->confirmOrder($order, $request->get('items'), $readyTime);

                if (!$success) {
                    return $this->errorResponse('Cannot approve order in current status', 400);
                }
            } catch (\InvalidArgumentException $e) {
                return $this->errorResponse($e->getMessage(), 400);
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
            // Security: Pass branch_id to service for authorization check
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            $readyTime = $request->validated()['ready_time'] ?? null;

            try {
                $success = $this->orderService->partialConfirmOrder($order, $request->get('items'), $readyTime);

                if (!$success) {
                    return $this->errorResponse('Cannot partially approve order', 400);
                }
            } catch (\InvalidArgumentException $e) {
                return $this->errorResponse($e->getMessage(), 400);
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
            // Security: Pass branch_id to service for authorization check
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

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
            // Security: Pass branch_id to service for authorization check
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            // Branch manager is canceling (from Purchase module)
            $success = $this->orderService->cancelOrder($order, $request->reason, byBranch: true);

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
            // Security: Pass branch_id to service for authorization check
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            $action = $request->action;

            $success = match ($action) {
                'approve_all' => $this->orderService->confirmOrder($order, null, $request->ready_time),
                'partial_approve' => $this->orderService->partialConfirmOrder($order, $request->get('items'), $request->ready_time),
                'reject_all' => $this->orderService->rejectOrder($order, $request->reason),
                default => false,
            };

            if (!$success) {
                return $this->errorResponse('Cannot process transfer request', 400);
            }

            // Refresh order to get latest status
            $order->refresh();

            // If order was approved/partially approved, check if we need to transition to confirmed/partial_confirmed
            // This happens when the receiving branch accepts the order
            if ($action !== 'reject_all') {
                $currentStatus = $order->status;

                // If fully approved, transition to confirmed when received
                if ($currentStatus === OrderStatus::FULLY_APPROVED) {
                    $order->transitionTo(OrderStatus::CONFIRMED);
                }
                // If partially approved, transition to partial_confirmed when received
                elseif ($currentStatus === OrderStatus::PARTIAL_APPROVED) {
                    $order->transitionTo(OrderStatus::PARTIAL_CONFIRMED);
                }
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
                UnifiedTimelineResource::collection($timeline),
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
            // Security: Pass branch_id to service for authorization check
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

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
            // Security: Pass branch_id to service for authorization check
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

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
            // Security: Pass branch_id to service for authorization check
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

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
            // Security: Pass branch_id to service for authorization check
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            if ($order->status !== OrderStatus::DELAYED) {
                return $this->errorResponse('Order is not in delayed status', 400);
            }

            // Approve delay: status → delayed_confirmed, Track becomes available
            $success = $this->orderService->approveDelayRequest($order);

            if (!$success) {
                return $this->errorResponse('Cannot approve delay', 400);
            }

            return $this->successResponse(
                new DirectSupplierOrderResource($order->fresh(['items', 'supplier'])),
                'Delay approved successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
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
            // Security: Pass branch_id to service for authorization check
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            if ($order->status !== OrderStatus::DELAYED) {
                return $this->errorResponse('Order is not in delayed status', 400);
            }

            // Reject delay: status → delayed_canceled, moves to Purchase History
            $success = $this->orderService->rejectDelayRequest($order, $request->reason);

            if (!$success) {
                return $this->errorResponse('Cannot reject delay', 400);
            }

            return $this->successResponse(
                new DirectSupplierOrderResource($order->fresh(['items', 'supplier'])),
                'Delay rejected. Order has been canceled.'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
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
     * According to requirements 3.1.2.4.3.4.1:
     * - View Details with all order information
     * - Timeline Tracking
     * - Store Information
     *
     * @group Pending Orders - Internal Transfer
     */
    public function internalTransferOrderDetails(string $id): JsonResponse
    {
        try {
            // Security: Pass branch_id to service for authorization check
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            // Ensure this is an Internal Transfer Order
            if ($order->order_type !== OrderType::INTERNAL_TRANSFER) {
                return $this->errorResponse('This endpoint is only for Internal Transfer Orders', 400);
            }

            // Log view event (for Timeline Tracking)
            $this->timelineService->logOrderViewed($order, auth()->id());

            // Ensure all required relationships are loaded
            $order->loadMissing([
                'items',
                'fromBranch',
                'requestedBy',
                'timelines',
                'branch',
            ]);

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
            // Security: Pass branch_id to service for authorization check
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

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

    /**
     * Approve item request (branch manager approves supplier's request for specific item)
     *
     * @group Pending Orders
     */
    public function approveItemRequest(string $id, string $itemId): JsonResponse
    {
        try {
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            $additionalData = request()->only(['new_delivery_time']);

            $success = $this->orderService->approveItemRequest($order, $itemId, $additionalData);

            if (!$success) {
                return $this->errorResponse('Failed to approve item request', 400);
            }

            return $this->successResponse(
                new PurchaseOrderResource($order->fresh(['items'])),
                'Item request approved successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'approving item request');
        }
    }

    /**
     * Reject item request (branch manager rejects supplier's request for specific item)
     *
     * @group Pending Orders
     */
    public function rejectItemRequest(RejectOrderRequest $request, string $id, string $itemId): JsonResponse
    {
        try {
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            $reason = $request->validated()['reason'] ?? null;

            $success = $this->orderService->rejectItemRequest($order, $itemId, $reason);

            if (!$success) {
                return $this->errorResponse('Failed to reject item request', 400);
            }

            return $this->successResponse(
                new PurchaseOrderResource($order->fresh(['items'])),
                'Item request rejected successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'rejecting item request');
        }
    }

    /**
     * Cancel item (branch manager cancels specific item)
     *
     * @group Pending Orders
     */
    public function cancelItem(RejectOrderRequest $request, string $id, string $itemId): JsonResponse
    {
        try {
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            $reason = $request->validated()['reason'] ?? null;

            $success = $this->orderService->cancelItem($order, $itemId, $reason);

            if (!$success) {
                return $this->errorResponse('Failed to cancel item', 400);
            }

            return $this->successResponse(
                new PurchaseOrderResource($order->fresh(['items'])),
                'Item cancelled successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'cancelling item');
        }
    }

    /**
     * Get modification details for a specific item
     *
     * @group Pending Orders
     */
    public function getModificationDetails(string $id, string $itemId): JsonResponse
    {
        try {
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            // Ensure relationships are loaded
            $order->load(['requestedBy', 'supplier']);

            $item = PurchaseOrderItem::where('purchase_order_id', $order->id)
                ->where('id', $itemId)
                ->with('purchaseOrder')
                ->first();

            if (!$item) {
                return $this->notFoundResponse('Item not found');
            }

            // Check if item has modifications or is cancelled
            $hasModifications = $item->approval_type !== null
                || $item->original_quantity !== null
                || $item->is_alternative
                || ($order->expected_delivery_at && $order->preferred_delivery_date);

            $isCancelled = $item->status->isCancelled();

            if (!$hasModifications && !$isCancelled) {
                return $this->errorResponse('No modifications found for this item', 404);
            }

            // Get modification details
            $modificationResource = new ModificationDetailResource($item);
            $responseData = $modificationResource->toArray(request());

            // Add cancellation_reason if item is cancelled (same logic as getCancellationReason)
            if ($isCancelled) {
                $isCancelledByBranchOrSupplier = in_array($item->status, [
                    \Modules\Purchase\Enums\OrderItemStatus::CANCELLED_BY_BRANCH,
                    \Modules\Purchase\Enums\OrderItemStatus::CANCELLED_BY_SUPPLIER,
                    \Modules\Purchase\Enums\OrderItemStatus::CANCELED_MODIFICATION,
                    \Modules\Purchase\Enums\OrderItemStatus::CANCELLED_DELAYED,
                    \Modules\Purchase\Enums\OrderItemStatus::DELAYED_CANCELED,
                ]);

                $cancellationReason = null;
                if ($isCancelledByBranchOrSupplier) {
                    $cancellationReason = $item->approval_data['cancellation_reason'] ?? null;
                }

                $cancelledAt = $item->updated_at?->format('Y-m-d H:i:s');
                $cancelledBy = $this->getCancelledByInfo($item, $order);

                // Add cancellation details as object to response
                $responseData = array_merge($responseData, [
                    'cancellation' => [
                        'cancellation_reason' => $cancellationReason,
                        'cancelled_at' => $cancelledAt,
                        'cancelled_by' => $cancelledBy,
                    ],
                ]);
            }

            return $this->successResponse(
                $responseData,
                'Modification details retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching modification details');
        }
    }

    /**
     * Approve modification for a specific item
     *
     * @group Pending Orders
     */
    public function approveModification(string $id, string $itemId): JsonResponse
    {
        try {
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            // Get the purchase order item
            $purchaseOrderItem = PurchaseOrderItem::where('purchase_order_id', $order->id)
                ->where('id', $itemId)
                ->first();

            if (!$purchaseOrderItem) {
                return $this->notFoundResponse('Item not found');
            }

            $additionalData = request()->only(['new_delivery_time']);
            $success = $this->orderService->approveItemRequest($order, $purchaseOrderItem->item_id, $additionalData);

            if (!$success) {
                return $this->errorResponse('Failed to approve modification', 400);
            }

            return $this->successResponse(
                new PurchaseOrderResource($order->fresh(['items'])),
                'Modification approved successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'approving modification');
        }
    }

    /**
     * Reject modification for a specific item
     *
     * @group Pending Orders
     */
    public function rejectModification(RejectOrderRequest $request, string $id, string $itemId): JsonResponse
    {
        try {
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            // Get the purchase order item
            $purchaseOrderItem = PurchaseOrderItem::where('purchase_order_id', $order->id)
                ->where('id', $itemId)
                ->first();

            if (!$purchaseOrderItem) {
                return $this->notFoundResponse('Item not found');
            }

            $reason = $request->validated()['reason'] ?? null;
            $success = $this->orderService->rejectItemRequest($order, $purchaseOrderItem->item_id, $reason);

            if (!$success) {
                return $this->errorResponse('Failed to reject modification', 400);
            }

            return $this->successResponse(
                new PurchaseOrderResource($order->fresh(['items'])),
                'Modification rejected successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'rejecting modification');
        }
    }

    /**
     * Get cancellation reason for a specific item
     *
     * @group Pending Orders
     */
    public function getCancellationReason(string $id, string $itemId): JsonResponse
    {
        try {
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            // Ensure relationships are loaded
            $order->load(['requestedBy', 'supplier']);

            $item = PurchaseOrderItem::where('purchase_order_id', $order->id)
                ->where('id', $itemId)
                ->first();

            if (!$item) {
                return $this->notFoundResponse('Item not found');
            }

            if (!$item->status->isCancelled()) {
                return $this->errorResponse('Item is not cancelled', 400);
            }

            // Only return cancellation_reason if cancelled by branch or supplier
            $isCancelledByBranchOrSupplier = in_array($item->status, [
                \Modules\Purchase\Enums\OrderItemStatus::CANCELLED_BY_BRANCH,
                \Modules\Purchase\Enums\OrderItemStatus::CANCELLED_BY_SUPPLIER,
                \Modules\Purchase\Enums\OrderItemStatus::CANCELED_MODIFICATION,
                \Modules\Purchase\Enums\OrderItemStatus::CANCELLED_DELAYED,
                \Modules\Purchase\Enums\OrderItemStatus::DELAYED_CANCELED,
            ]);

            $cancellationReason = null;
            if ($isCancelledByBranchOrSupplier) {
                $cancellationReason = $item->approval_data['cancellation_reason'] ?? null;
            }

            $cancelledAt = $item->updated_at?->format('Y-m-d H:i:s');

            // Determine who cancelled based on item status
            $cancelledBy = $this->getCancelledByInfo($item, $order);

            return $this->successResponse(
                [
                    'cancellation' => [
                        'cancellation_reason' => $cancellationReason,
                        'cancelled_at' => $cancelledAt,
                        'cancelled_by' => $cancelledBy,
                    ],
                ],
                'Cancellation reason retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching cancellation reason');
        }
    }

    /**
     * Get information about who cancelled the item
     */
    private function getCancelledByInfo(PurchaseOrderItem $item, PurchaseOrder $order): ?array
    {
        $status = $item->status;

        // Check if cancelled by branch manager
        if (in_array($status, [
            \Modules\Purchase\Enums\OrderItemStatus::CANCELLED_BY_BRANCH,
            \Modules\Purchase\Enums\OrderItemStatus::CANCELED_MODIFICATION,
            \Modules\Purchase\Enums\OrderItemStatus::CANCELLED_DELAYED,
            \Modules\Purchase\Enums\OrderItemStatus::DELAYED_CANCELED, // Branch rejected delay request
        ])) {
            // Load relationship if not already loaded
            if (!$order->relationLoaded('requestedBy')) {
                $order->load('requestedBy');
            }

            if ($order->requestedBy) {
                return [
                    'id' => $order->requestedBy->id,
                    'name' => $order->requestedBy->name,
                    'type' => 'branch_manager',
                    'image' => $order->requestedBy->image_url ?? null,
                ];
            }
        }

        // Check if cancelled by supplier
        if ($status === \Modules\Purchase\Enums\OrderItemStatus::CANCELLED_BY_SUPPLIER) {
            // Load relationship if not already loaded
            if (!$order->relationLoaded('supplier')) {
                $order->load('supplier');
            }

            if ($order->supplier) {
                return [
                    'id' => $order->supplier->id,
                    'name' => $order->supplier->name,
                    'type' => 'supplier',
                    'image' => $order->supplier->image_url ?? null,
                ];
            }
        }

        // Default: cancelled by branch manager (for CANCELLED status)
        if ($status === \Modules\Purchase\Enums\OrderItemStatus::CANCELLED) {
            // Load relationship if not already loaded
            if (!$order->relationLoaded('requestedBy')) {
                $order->load('requestedBy');
            }

            if ($order->requestedBy) {
                return [
                    'id' => $order->requestedBy->id,
                    'name' => $order->requestedBy->name,
                    'type' => 'branch_manager',
                    'image' => $order->requestedBy->image_url ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * Approve delay report for the entire order
     * Branch manager approves supplier's delay request for all items in the order
     *
     * @group Pending Orders
     */
    public function approveOrderDelay(string $id): JsonResponse
    {
        try {
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            // Check if order is in delayed status
            if ($order->status !== \Modules\Purchase\Enums\OrderStatus::DELAYED) {
                return $this->errorResponse('Order is not in delayed status', 400);
            }

            $success = $this->orderService->approveOrderDelay($order);

            if (!$success) {
                return $this->errorResponse('Failed to approve order delay', 400);
            }

            return $this->successResponse(
                new PurchaseOrderResource($order->fresh(['items'])),
                'Order delay approved successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'approving order delay');
        }
    }

    /**
     * Reject delay report for the entire order
     * Branch manager rejects supplier's delay request and cancels all delayed items
     *
     * @group Pending Orders
     */
    public function rejectOrderDelay(RejectOrderRequest $request, string $id): JsonResponse
    {
        try {
            $userBranchId = auth()->user()->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            // Check if order is in delayed status
            if ($order->status !== \Modules\Purchase\Enums\OrderStatus::DELAYED) {
                return $this->errorResponse('Order is not in delayed status', 400);
            }

            $reason = $request->validated()['reason'] ?? null;
            $success = $this->orderService->rejectOrderDelay($order, $reason);

            if (!$success) {
                return $this->errorResponse('Failed to reject order delay', 400);
            }

            return $this->successResponse(
                new PurchaseOrderResource($order->fresh(['items'])),
                'Order delay rejected. All delayed items have been cancelled.'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'rejecting order delay');
        }
    }
}
