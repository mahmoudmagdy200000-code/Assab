<?php

namespace Modules\Supplier\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\Supplier\Http\Requests\RecurringOrders\ModifyScheduleRequest;
use Modules\Supplier\Services\RecurringOrderService;
use Modules\Supplier\Transformers\OrderResource;

class RecurringOrderController extends BaseController
{
    public function __construct(
        private readonly RecurringOrderService $recurringOrderService
    ) {}

    /**
     * Get recurring orders
     */
    public function getRecurringOrders(): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $filters = request()->only(['search']);
            $perPage = request()->get('per_page', 15);

            $orders = $this->recurringOrderService->getRecurringOrders($supplier, $filters, $perPage);

            return $this->paginatedResponse(
                OrderResource::collection($orders),
                'Recurring orders retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching recurring orders');
        }
    }

    /**
     * Modify schedule
     */
    public function modifySchedule(ModifyScheduleRequest $request, string $id): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $order = $this->recurringOrderService->modifySchedule($supplier, $id, $request->validated());

            return $this->successResponse(
                new OrderResource($order),
                'Schedule modified successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'modifying schedule');
        }
    }
}

