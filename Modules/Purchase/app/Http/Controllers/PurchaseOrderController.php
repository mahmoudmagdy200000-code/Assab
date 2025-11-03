<?php

namespace Modules\Purchase\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Purchase\Http\Requests\CreatePurchaseOrderRequest;
use Modules\Purchase\Http\Requests\UpdatePurchaseOrderRequest;
use Modules\Purchase\Transformers\PurchaseOrderResource;
use Modules\Purchase\Services\PurchaseOrderService;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private PurchaseOrderService $purchaseOrderService
    ) {}

    /**
     * Get purchase history with filters
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->get('search'),
            'type' => $request->get('type'),
            'status' => $request->get('status'),
            'date_from' => $request->get('date_from'),
            'date_to' => $request->get('date_to'),
            'branch_id' => auth()->user()->branch_id,
        ];

        $orders = $this->purchaseOrderService->getPurchaseHistory($filters);

        return response()->json([
            'success' => true,
            'data' => PurchaseOrderResource::collection($orders),
        ]);
    }

    /**
     * Get pending orders
     */
    public function pending(Request $request): JsonResponse
    {
        $filters = [
            'type' => $request->get('type'),
            'status' => $request->get('status'),
            'date_from' => $request->get('date_from'),
            'date_to' => $request->get('date_to'),
            'branch_id' => auth()->user()->branch_id,
        ];

        $orders = $this->purchaseOrderService->getPendingOrders($filters);

        return response()->json([
            'success' => true,
            'data' => PurchaseOrderResource::collection($orders),
        ]);
    }

    /**
     * Store new purchase order
     */
    public function store(CreatePurchaseOrderRequest $request): JsonResponse
    {
        $order = $this->purchaseOrderService->createOrder(
            $request->validated(),
            auth()->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Purchase order created successfully',
            'data' => new PurchaseOrderResource($order),
        ], 201);
    }

    /**
     * Show purchase order details
     */
    public function show($id): JsonResponse
    {
        $order = $this->purchaseOrderService->getOrderDetails($id);

        return response()->json([
            'success' => true,
            'data' => new PurchaseOrderResource($order),
        ]);
    }

    /**
     * Update purchase order
     */
    public function update(UpdatePurchaseOrderRequest $request, $id): JsonResponse
    {
        $order = $this->purchaseOrderService->updateOrder(
            $id,
            $request->validated(),
            auth()->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Purchase order updated successfully',
            'data' => new PurchaseOrderResource($order),
        ]);
    }

    /**
     * Cancel purchase order
     */
    public function cancel(Request $request, $id): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $order = $this->purchaseOrderService->cancelOrder(
            $id,
            $request->get('reason'),
            auth()->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Purchase order canceled successfully',
            'data' => new PurchaseOrderResource($order),
        ]);
    }

    /**
     * Approve order modification
     */
    public function approveModification(Request $request, $id): JsonResponse
    {
        $request->validate([
            'modification_id' => 'required|exists:purchase_modifications,id',
        ]);

        $order = $this->purchaseOrderService->approveModification(
            $id,
            $request->get('modification_id'),
            auth()->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Modification approved successfully',
            'data' => new PurchaseOrderResource($order),
        ]);
    }

    /**
     * Reject order modification
     */
    public function rejectModification(Request $request, $id): JsonResponse
    {
        $request->validate([
            'modification_id' => 'required|exists:purchase_modifications,id',
            'reason' => 'required|string|max:1000',
        ]);

        $order = $this->purchaseOrderService->rejectModification(
            $id,
            $request->get('modification_id'),
            $request->get('reason'),
            auth()->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Modification rejected successfully',
            'data' => new PurchaseOrderResource($order),
        ]);
    }

    /**
     * Get order timeline
     */
    public function timeline($id): JsonResponse
    {
        $timeline = $this->purchaseOrderService->getOrderTimeline($id);

        return response()->json([
            'success' => true,
            'data' => $timeline,
        ]);
    }

    /**
     * Change order source
     */
    public function changeSource(Request $request, $id): JsonResponse
    {
        $request->validate([
            'new_order_type' => 'required|in:direct_supplier,purchasing_officer,internal_transfer',
            'supplier_id' => 'required_if:new_order_type,direct_supplier|exists:suppliers,id',
            'purchasing_officer_id' => 'required_if:new_order_type,purchasing_officer|exists:users,id',
            'transfer_from_branch_id' => 'required_if:new_order_type,internal_transfer|exists:branches,id',
        ]);

        $order = $this->purchaseOrderService->changeOrderSource(
            $id,
            $request->validated(),
            auth()->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Order source changed successfully',
            'data' => new PurchaseOrderResource($order),
        ]);
    }
}
