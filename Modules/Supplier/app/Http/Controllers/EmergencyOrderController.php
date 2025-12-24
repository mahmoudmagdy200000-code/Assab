<?php

namespace Modules\Supplier\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\Supplier\Http\Requests\EmergencyOrders\RespondToEmergencyRequest;
use Modules\Supplier\Services\EmergencyOrderService;
use Modules\Supplier\Transformers\OrderResource;

class EmergencyOrderController extends BaseController
{
    public function __construct(
        private readonly EmergencyOrderService $emergencyOrderService
    ) {}

    /**
     * Get emergency orders
     */
    public function getEmergencyOrders(): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $filters = request()->only(['search']);
            $perPage = request()->get('per_page', 15);

            $orders = $this->emergencyOrderService->getEmergencyOrders($supplier, $filters, $perPage);

            return $this->paginatedResponse(
                OrderResource::collection($orders),
                'Emergency orders retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching emergency orders');
        }
    }

    /**
     * Respond to emergency
     */
    public function respondToEmergency(RespondToEmergencyRequest $request, string $id): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $order = $this->emergencyOrderService->respondToEmergency($supplier, $id, $request->validated());

            return $this->successResponse(
                new OrderResource($order),
                'Emergency response submitted successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'responding to emergency');
        }
    }
}

