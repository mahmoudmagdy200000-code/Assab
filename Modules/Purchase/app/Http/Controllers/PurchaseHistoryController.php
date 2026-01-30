<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\Purchase\Http\Requests\FilterPurchaseHistoryRequest;
use Modules\Purchase\Services\PurchaseOrderService;
use Modules\Purchase\Services\TimelineService;
use Modules\Purchase\Transformers\PurchaseOrderResource;
use Modules\Purchase\Transformers\PurchaseHistoryDetailsResource;

class PurchaseHistoryController extends BaseController
{
    public function __construct(
        private readonly PurchaseOrderService $orderService,
        private readonly TimelineService $timelineService
    ) {}

    /**
     * Get purchase history list
     *
     * @group Purchase History
     */
    public function index(FilterPurchaseHistoryRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $filters['branch_id'] = auth()->user()->branch_id;

            $orders = $this->orderService->getHistory($filters, $request->get('per_page', 15));

            return $this->paginatedResponse(
                PurchaseOrderResource::collection($orders),
                'Purchase history retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching purchase history');
        }
    }

    /**
     * Get single order details
     *
     * @group Purchase History
     */
    public function show(string $id): JsonResponse
    {
        try {
            // Security: Pass branch_id to service for authorization check
            $user = auth()->user();
            $userBranchId = $user->branch_id;
            $order = $this->orderService->getOrderDetails($id, $userBranchId);

            if (!$order) {
                return $this->notFoundResponse('Order not found');
            }

            $this->timelineService->logOrderViewed($order, $user->id);

            return $this->successResponse(
                new PurchaseHistoryDetailsResource($order),
                'Order details retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching order details');
        }
    }

    /**
     * Get order timeline
     *
     * @group Purchase History
     */
    public function timeline(string $id): JsonResponse
    {
        try {
            $timeline = $this->orderService->getOrderTimeline($id);

            return $this->successResponse(
                $timeline,
                'Order timeline retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching order timeline');
        }
    }
}
