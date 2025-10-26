<?php

namespace Modules\Expense\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Expense\Services\ExpenseHelperService;
use Modules\Expense\Transformers\SupplierResource;

/**
 * Supplier Controller
 */
class SupplierController extends BaseController
{
    public function __construct(
        private ExpenseHelperService $helperService
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
    public function show(int $supplier): JsonResponse
    {
        $supplierModel = \Modules\Expense\Models\Supplier::findOrFail($supplier);

        return $this->successResponse(
            new SupplierResource($supplierModel),
            'Supplier retrieved successfully'
        );
    }
}
