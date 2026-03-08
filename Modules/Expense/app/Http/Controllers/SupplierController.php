<?php

namespace Modules\Expense\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Expense\Repositories\SupplierRepository;
use Modules\Expense\Services\ExpenseHelperService;
use Modules\Expense\Transformers\SupplierResource;

/**
 * Supplier Controller
 * HTTP only: delegates data access to repository/service, returns same response contract.
 */
class SupplierController extends BaseController
{
    public function __construct(
        private ExpenseHelperService $helperService,
        private SupplierRepository $supplierRepository
    ) {}

    /**
     * Get all suppliers
     * GET /api/branch-manager/expenses/suppliers
     */
    public function index(Request $request): JsonResponse
    {
        $suppliers = $this->helperService->getSuppliers($request->input('search'));

        return $this->successResponse(
            $suppliers,
            'Suppliers retrieved successfully',
        );
    }

    /**
     * Get supplier by ID
     * GET /api/branch-manager/expenses/suppliers/{supplier}
     */
    public function show(string $supplier): JsonResponse
    {
        $supplierModel = $this->supplierRepository->findOrFail($supplier);

        return $this->successResponse(
            new SupplierResource($supplierModel),
            'Supplier retrieved successfully'
        );
    }
}
