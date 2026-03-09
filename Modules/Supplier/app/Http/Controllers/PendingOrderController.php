<?php

namespace Modules\Supplier\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Services\PurchaseOrderService;
use Modules\Purchase\Services\TimelineService;
use Modules\Supplier\Http\Requests\Orders\AcceptOrderRequest;
use Modules\Supplier\Http\Requests\Orders\FilterPendingOrdersRequest;
use Modules\Supplier\Http\Requests\Orders\RejectOrderRequest;
use Modules\Supplier\Http\Requests\Orders\RejectItemRequest;
use Modules\Supplier\Http\Requests\Orders\RequestModificationRequest;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Transformers\ModificationDetailResource;
use Modules\Supplier\Services\NotificationService;
use Modules\Supplier\Services\OrderService;
use Modules\Supplier\Transformers\OrderResource;

class PendingOrderController extends BaseController
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly PurchaseOrderService $purchaseOrderService,
        private readonly TimelineService $timelineService,
        private readonly NotificationService $notificationService
    ) {}

    /**
     * Get pending orders list for supplier
     *
     * @group Supplier Pending Orders
     *
     * Query Parameters:
     * - status: Filter by status (pending, partial_confirmed, confirmed, delayed, alternative_product, rejected)
     * - date_from: Filter orders from date
     * - date_to: Filter orders to date
     * - search: Search by order number
     * - per_page: Number of items per page (default: 15, max: 100)
     */
    public function index(FilterPendingOrdersRequest $request): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $filters = $request->validated();
            $perPage = $filters['per_page'] ?? 15;

            // Remove per_page from filters as it's not a filter
            unset($filters['per_page']);

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
     * Partially approve order (for PENDING status)
     * Supplier requests partial approval for specific items - items move to NEEDS_APPROVAL status
     * Order stays PENDING until branch manager approves/rejects all items
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

            $allowedStatuses = [OrderStatus::PENDING, OrderStatus::EMERGENCY, OrderStatus::VARIANCE];
            if (!in_array($order->status, $allowedStatuses)) {
                return $this->errorResponse(
                    "Order is not in pending, emergency or variance status. Current status: {$order->status->label()}",
                    400
                );
            }

            $items = $request->get('items', []);

            if (empty($items)) {
                return $this->errorResponse('Items array is required for partial approval', 400);
            }

            // Use root-level note/modification_request as fallback for items that don't have a note
            $globalNote = $request->input('note') ?? $request->input('modification_request') ?? $request->input('message');
            foreach ($items as &$item) {
                $item['note'] = $item['note'] ?? $globalNote;
            }
            unset($item);

            // Use OrderService to handle partial approval requests
            $order = $this->orderService->requestPartialApproval($order, $supplier, $items);

            // Update order with expected delivery if provided
            if ($request->has('expected_delivery_at')) {
                $order->expected_delivery_at = $request->validated()['expected_delivery_at'];
                $order->save();
            }

            return $this->successResponse(
                new OrderResource($order->fresh(['items'])),
                'Partial approval requests submitted. Waiting for branch manager approval.'
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

            $validated = $request->validated();
            $success = $this->purchaseOrderService->rejectOrder($order, $validated['reason'] ?? '');

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
    /**
     * Request delivery time change for specific item
     *
     * @group Supplier Pending Orders
     */
    public function requestTimeChange(RequestModificationRequest $request, string $id, string $itemId): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $order = PurchaseOrder::findOrFail($id);

            if ($order->supplier_id !== $supplier->id) {
                return $this->errorResponse('Unauthorized access to this order', 403);
            }

            $validated = $request->validated();

            if (!isset($validated['new_delivery_time'])) {
                return $this->errorResponse('new_delivery_time is required for time change requests', 422);
            }

            if (!isset($validated['reason'])) {
                return $this->errorResponse('reason is required for time change requests', 422);
            }

            $note = $validated['note'] ?? $validated['modification_request'] ?? null;
            $order = $this->orderService->requestTimeChange(
                $order,
                $supplier,
                $itemId,
                $validated['new_delivery_time'],
                $validated['reason'] ?? '',
                $note
            );

            return $this->successResponse(
                new OrderResource($order->fresh(['items'])),
                'Time change request submitted. Waiting for branch manager approval.'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'requesting time change');
        }
    }

    /**
     * Request alternative product for specific item
     *
     * @group Supplier Pending Orders
     */
    public function requestAlternative(RequestModificationRequest $request, string $id, string $itemId): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $order = PurchaseOrder::findOrFail($id);

            if ($order->supplier_id !== $supplier->id) {
                return $this->errorResponse('Unauthorized access to this order', 403);
            }

            $validated = $request->validated();

            $note = $validated['note'] ?? $validated['modification_request'] ?? null;
            $order = $this->orderService->requestAlternative(
                $order,
                $supplier,
                $itemId,
                $validated['alternative_item_id'],
                $validated['reason'] ?? '',
                $note
            );

            return $this->successResponse(
                new OrderResource($order->fresh(['items'])),
                'Alternative product request submitted. Waiting for branch manager approval.'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'requesting alternative product');
        }
    }

    /**
     * Confirm specific item in order
     *
     * @group Supplier Pending Orders
     */
    public function confirmItem(AcceptOrderRequest $request, string $id, string $itemId): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $order = PurchaseOrder::findOrFail($id);

            if ($order->supplier_id !== $supplier->id) {
                return $this->errorResponse('Unauthorized access to this order', 403);
            }

            $validated = $request->validated();
            $quantity = $request->input('quantity');

            // Extract quantity from items array if provided (for backward compatibility)
            if ($quantity === null && isset($validated['items']) && is_array($validated['items'])) {
                foreach ($validated['items'] as $item) {
                    if (isset($item['item_id']) && $item['item_id'] === $itemId) {
                        $quantity = $item['quantity'] ?? null;
                        break;
                    }
                }
            }

            // Convert to float if provided
            $quantity = $quantity !== null ? (float) $quantity : null;

            $order = $this->orderService->confirmItem($order, $supplier, $itemId, $quantity);

            return $this->successResponse(
                new OrderResource($order->fresh(['items'])),
                'Item confirmed successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'confirming item');
        }
    }

    /**
     * Reject specific item in order
     *
     * @group Supplier Pending Orders
     */
    public function rejectItem(RejectItemRequest $request, string $id, string $itemId): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $order = PurchaseOrder::findOrFail($id);

            if ($order->supplier_id !== $supplier->id) {
                return $this->errorResponse('Unauthorized access to this order', 403);
            }

            $validated = $request->validated();

            $order = $this->orderService->rejectItem(
                $order,
                $supplier,
                $itemId,
                $validated['reason'] ?? '',
                $validated['explanation'] ?? null
            );

            return $this->successResponse(
                new OrderResource($order->fresh(['items'])),
                'Item rejected successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'rejecting item');
        }
    }

    /**
     * Cancel specific item in order (by supplier)
     *
     * @group Supplier Pending Orders
     */
    public function cancelItem(RejectItemRequest $request, string $id, string $itemId): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $order = PurchaseOrder::findOrFail($id);

            if ($order->supplier_id !== $supplier->id) {
                return $this->errorResponse('Unauthorized access to this order', 403);
            }

            $validated = $request->validated();

            $order = $this->orderService->cancelItem(
                $order,
                $supplier,
                $itemId,
                $validated['reason'] ?? ''
            );

            return $this->successResponse(
                new OrderResource($order->fresh(['items'])),
                'Item cancelled successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'cancelling item');
        }
    }

    /**
     * Approve item request (supplier approves branch request for specific item)
     * Used when item status is needs_approval_supplier
     *
     * @group Supplier Pending Orders
     */
    public function approveItemRequest(string $id, string $itemId): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $order = PurchaseOrder::findOrFail($id);

            if ($order->supplier_id !== $supplier->id) {
                return $this->errorResponse('Unauthorized access to this order', 403);
            }

            $additionalData = request()->only(['new_delivery_time']);

            $success = $this->purchaseOrderService->approveItemRequest($order, $itemId, $additionalData);

            if (!$success) {
                return $this->errorResponse('Failed to approve item request', 400);
            }

            return $this->successResponse(
                new OrderResource($order->fresh(['items'])),
                'Item request approved successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'approving item request');
        }
    }

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

    /**
     * Get modification details for a specific item (for supplier)
     *
     * @group Supplier Pending Orders
     */
    public function getModificationDetails(string $id, string $itemId): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $order = PurchaseOrder::findOrFail($id);

            if ($order->supplier_id !== $supplier->id) {
                return $this->errorResponse('Unauthorized access to this order', 403);
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
     * Get cancellation reason for a specific item (for supplier)
     *
     * @group Supplier Pending Orders
     */
    public function getCancellationReason(string $id, string $itemId): JsonResponse
    {
        try {
            $supplier = auth('supplier')->user();
            $order = PurchaseOrder::findOrFail($id);

            if ($order->supplier_id !== $supplier->id) {
                return $this->errorResponse('Unauthorized access to this order', 403);
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
}
