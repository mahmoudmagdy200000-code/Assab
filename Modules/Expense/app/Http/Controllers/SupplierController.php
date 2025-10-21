<?php

namespace Modules\Expense\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Expense\Services\ExpenseHelperService;
use Modules\Expense\Transformers\SupplierResource;

/**
 * Supplier Controller
 */
class SupplierController extends Controller
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

        return response()->json([
            'success' => true,
            'message' => 'Suppliers retrieved successfully',
            'data' => $suppliers
        ]);
    }

    /**
     * Get supplier by ID
     * GET /api/branch-manager/expenses/suppliers/{supplier}
     */
    public function show(int $supplier): JsonResponse
    {
        $supplierModel = \Modules\Expense\Models\Supplier::findOrFail($supplier);

        return response()->json([
            'success' => true,
            'data' => new SupplierResource($supplierModel)
        ]);
    }
}
