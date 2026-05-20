<?php

namespace Modules\Cashier\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Modules\Cashier\Http\Requests\FilterCashierRequest;
use Modules\Cashier\Http\Requests\StoreCashierRequest;
use Modules\Cashier\Http\Requests\UpdateCashierRequest;
use Modules\Cashier\Models\Cashier;
use Modules\Cashier\Services\CashierService;
use Modules\Cashier\Transformers\CashierDetailResource;
use Modules\Cashier\Transformers\CashierResource;

class CashierController extends BaseController
{
    use AuthorizesRequests;

    public function __construct(
        private CashierService $cashierService
    ) {
        $this->middleware('branch.manager.or.cashier');
    }

    /**
     * Display a listing of cashiers
     */
    public function index(FilterCashierRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $cashiers = $this->cashierService->getCashiers(
            branchId: auth()->user()->branch_id,
            filters: $filters
        );

        return $this->paginatedResponse(
            CashierResource::collection($cashiers),
            'Cashiers retrieved successfully'
        );
    }

    /**
     * Store a newly created cashier
     */
    public function store(StoreCashierRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by'] = auth()->id();
        $data['branch_id'] = auth()->user()->branch_id;

        $cashier = $this->cashierService->createCashier($data);

        return $this->createdResponse(
            new CashierDetailResource($cashier),
            'Cashier created successfully'
        );
    }

    /**
     * Display the specified cashier
     */
    public function show(Cashier $cashier): JsonResponse
    {
        $this->authorize('view', $cashier);

        $cashierDetails = $this->cashierService->getCashierDetails($cashier->id);

        return $this->successResponse(
            new CashierDetailResource($cashierDetails),
            'Cashier details retrieved successfully'
        );
    }

    /**
     * Update the specified cashier
     */
    public function update(UpdateCashierRequest $request, Cashier $cashier): JsonResponse
    {
        $this->authorize('update', $cashier);

        $data = $request->validated();
        $updatedCashier = $this->cashierService->updateCashier($cashier, $data);

        return $this->updatedResponse(
            new CashierDetailResource($updatedCashier),
            'Cashier updated successfully'
        );
    }

    /**
     * Remove the specified cashier
     */
    public function destroy(Cashier $cashier): JsonResponse
    {
        $this->authorize('delete', $cashier);

        // Check if cashier has active shifts
        if ($cashier->hasActiveShift()) {
            return $this->errorResponse(
                'Cannot delete cashier with active or upcoming shifts',
                400
            );
        }

        $this->cashierService->deleteCashier($cashier);

        return $this->deletedResponse('Cashier deleted successfully');
    }

    /**
     * Activate the specified cashier
     */
    public function activate(Cashier $cashier): JsonResponse
    {
        $this->authorize('update', $cashier);

        if ($cashier->isActive()) {
            return $this->errorResponse('Cashier is already active', 400);
        }

        $this->cashierService->activateCashier($cashier);

        return $this->updatedResponse(
            new CashierResource($cashier->fresh()),
            'Cashier activated successfully'
        );
    }

    /**
     * Deactivate the specified cashier
     */
    public function deactivate(Cashier $cashier): JsonResponse
    {
        $this->authorize('update', $cashier);

        if ($cashier->isDeactivated()) {
            return $this->errorResponse('Cashier is already deactivated', 400);
        }

        // Check if cashier has active shifts
        if ($cashier->hasActiveShift()) {
            return $this->errorResponse(
                'Cannot deactivate cashier with active shifts. Please end all shifts first.',
                400
            );
        }

        $this->cashierService->deactivateCashier($cashier);

        return $this->updatedResponse(
            new CashierResource($cashier->fresh()),
            'Cashier deactivated successfully'
        );
    }
}
