<?php

namespace Modules\RecurringOrder\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BranchManagers\Models\BranchManager;
use Modules\RecurringOrder\Http\Requests\StoreRecurringOrderRequest;
use Modules\RecurringOrder\Http\Requests\UpdateRecurringOrderRequest;
use Modules\RecurringOrder\Models\RecurringOrder;
use Modules\RecurringOrder\Services\RecurringOrderService;
use Modules\RecurringOrder\Transformers\RecurringOrderDetailResource;
use Modules\RecurringOrder\Transformers\RecurringOrderHistoryItemResource;
use Modules\RecurringOrder\Transformers\RecurringOrderListResource;

class RecurringOrderController extends BaseController
{
    public function __construct(
        private readonly RecurringOrderService $service
    ) {}

    /**
     * 3.1.2.6.1 Recurring Orders – In Progress List
     */
    public function indexInProgress(Request $request): JsonResponse
    {
        try {
            $branchManager = $this->branchManager();
            $filters = $request->only(['search']);
            $perPage = $request->get('per_page', 15);
            $list = $this->service->getInProgressList($branchManager->branch_id, $filters, $perPage);

            return $this->paginatedResponse(
                RecurringOrderListResource::collection($list),
                'Recurring orders (in progress) retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching in progress recurring orders');
        }
    }

    /**
     * 3.1.2.6.2 Recurring Orders – Next Scheduling List
     */
    public function indexNextScheduling(Request $request): JsonResponse
    {
        try {
            $branchManager = $this->branchManager();
            $filters = $request->only(['search']);
            $perPage = $request->get('per_page', 15);
            $list = $this->service->getNextSchedulingList($branchManager->branch_id, $filters, $perPage);

            return $this->paginatedResponse(
                RecurringOrderListResource::collection($list),
                'Recurring orders (next scheduling) retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching next scheduling recurring orders');
        }
    }

    /**
     * 3.1.2.6.3 Recurring Orders – Paused List
     */
    public function indexPaused(Request $request): JsonResponse
    {
        try {
            $branchManager = $this->branchManager();
            $filters = $request->only(['search']);
            $perPage = $request->get('per_page', 15);
            $list = $this->service->getPausedList($branchManager->branch_id, $filters, $perPage);

            return $this->paginatedResponse(
                RecurringOrderListResource::collection($list),
                'Recurring orders (paused) retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching paused recurring orders');
        }
    }

    /**
     * 3.1.2.6.1.1 Add Recurring Orders
     */
    public function store(StoreRecurringOrderRequest $request): JsonResponse
    {
        try {
            $branchManager = $this->branchManager();
            $order = $this->service->create($branchManager, $request->validated());

            return $this->createdResponse(
                new RecurringOrderDetailResource($order),
                'Recurring order created and activated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'creating recurring order');
        }
    }

    /**
     * 3.1.2.6.1.2 / 3.1.2.6.2.1 / 3.1.2.6.3.1 View Recurring Order Details
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $branchManager = $this->branchManager();
            $order = $this->service->getDetails($id, $branchManager->branch_id);
            if (! $order) {
                return $this->notFoundResponse('Recurring order not found');
            }
            $order->setAttribute('include_item_availability', true);
            $history = $this->service->getHistory($order);
            $order->setAttribute('history_data', [
                'total_completed_orders' => $history['total_completed_orders'],
                'total_canceled_orders' => $history['total_canceled_orders'],
                'list' => RecurringOrderHistoryItemResource::collection($history['list'])->resolve(),
            ]);

            return $this->successResponse(
                new RecurringOrderDetailResource($order),
                'Recurring order details retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching recurring order details');
        }
    }

    /**
     * Pause recurring order
     */
    public function pause(string $id): JsonResponse
    {
        try {
            $order = $this->findForBranch($id);
            if (! $order) {
                return $this->notFoundResponse('Recurring order not found');
            }
            $updated = $this->service->pause($order);

            return $this->successResponse(
                new RecurringOrderDetailResource($updated),
                'Recurring order paused successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'pausing recurring order');
        }
    }

    /**
     * Resume recurring order (from paused)
     */
    public function resume(string $id): JsonResponse
    {
        try {
            $order = $this->findForBranch($id);
            if (! $order) {
                return $this->notFoundResponse('Recurring order not found');
            }
            $updated = $this->service->resume($order);

            return $this->successResponse(
                new RecurringOrderDetailResource($updated),
                'Recurring order resumed successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'resuming recurring order');
        }
    }

    /**
     * Update recurring order
     */
    public function update(UpdateRecurringOrderRequest $request, string $id): JsonResponse
    {
        try {
            $order = $this->findForBranch($id);
            if (! $order) {
                return $this->notFoundResponse('Recurring order not found');
            }
            $updated = $this->service->update($order, $request->validated());

            return $this->successResponse(
                new RecurringOrderDetailResource($updated),
                'Recurring order updated successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'updating recurring order');
        }
    }

    /**
     * Delete recurring order permanently
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $order = $this->findForBranch($id);
            if (! $order) {
                return $this->notFoundResponse('Recurring order not found');
            }
            $this->service->delete($order);

            return $this->deletedResponse('Recurring order deleted successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'deleting recurring order');
        }
    }

    /**
     * List suppliers for Add Recurring Order (name, image, status online/offline)
     */
    public function suppliers(Request $request): JsonResponse
    {
        try {
            $query = \Modules\Supplier\Models\Supplier::query()->active();
            if ($request->filled('search')) {
                $query->where('name', 'like', '%'.$request->search.'%')
                    ->orWhere('company_name', 'like', '%'.$request->search.'%');
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            $perPage = $request->get('per_page', 20);
            $suppliers = $query->orderBy('name')->paginate($perPage);
            $items = $suppliers->getCollection()->map(function ($s) {
                $image = $s->image ? asset('storage/'.$s->image) : null;

                return [
                    'id' => $s->id,
                    'name' => $s->name ?? $s->company_name,
                    'image' => $image,
                    'status' => $s->status ?? 'offline',
                ];
            })->values();
            $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
                $items,
                $suppliers->total(),
                $perPage,
                $suppliers->currentPage(),
                ['path' => $suppliers->path()]
            );

            return $this->paginatedResponse($paginator, 'Suppliers retrieved successfully');
        } catch (\Throwable $e) {
            return $this->handleException($e, 'fetching suppliers');
        }
    }

    /**
     * List purchasing officers for Add Recurring Order (name, image, status)
     */
    public function purchasingOfficers(Request $request): JsonResponse
    {
        try {
            $branchManager = $this->branchManager();
            $query = BranchManager::query()
                ->where('id', '!=', $branchManager->id)
                ->active();
            if ($request->filled('search')) {
                $query->where('name', 'like', '%'.$request->search.'%')
                    ->orWhere('email', 'like', '%'.$request->search.'%');
            }
            $perPage = $request->get('per_page', 20);
            $officers = $query->orderBy('name')->paginate($perPage);
            $items = $officers->getCollection()->map(function ($o) {
                $image = $o->image ? asset('storage/'.$o->image) : null;

                return [
                    'id' => $o->id,
                    'name' => $o->name,
                    'image' => $image,
                    'status' => $o->is_active ? 'online' : 'offline',
                ];
            })->values();
            $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
                $items,
                $officers->total(),
                $perPage,
                $officers->currentPage(),
                ['path' => $officers->path()]
            );

            return $this->paginatedResponse($paginator, 'Purchasing officers retrieved successfully');
        } catch (\Throwable $e) {
            return $this->handleException($e, 'fetching purchasing officers');
        }
    }

    private function branchManager(): BranchManager
    {
        $user = auth()->user();
        if (! $user instanceof BranchManager) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Branch Manager authentication required.');
        }

        return $user;
    }

    private function findForBranch(string $id): ?RecurringOrder
    {
        $branchManager = $this->branchManager();

        return RecurringOrder::where('id', $id)
            ->where('branch_id', $branchManager->branch_id)
            ->first();
    }
}
