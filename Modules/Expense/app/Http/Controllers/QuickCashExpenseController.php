<?php

namespace Modules\Expense\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Expense\Services\QuickCashExpenseService;
use Modules\Expense\Models\Expense;
use Modules\Expense\Transformers\ExpenseDetailResource;

/**
 * Quick Cash Expense Controller
 * For expenses < 500 SAR
 */
class QuickCashExpenseController extends Controller
{
    public function __construct(
        private QuickCashExpenseService $quickCashService
    ) {}

    /**
     * Create Quick Cash Expense
     * POST /api/branch-manager/expenses/quick-cash
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'expense_date' => 'required|date',
            'expense_name' => 'required|string|max:255',
            'total_amount' => 'required|numeric|min:0|max:500',
            'items' => 'required|array|min:1',
            'items.*.title' => 'required|string|max:255',
            'items.*.amount' => 'required|numeric|min:0',
            'has_vat' => 'required|boolean',
            'invoice_number' => 'nullable|string|max:100',
            'payment_method' => 'required|in:cash,supplier,custody',
            'supplier_id' => 'required_if:payment_method,supplier|exists:suppliers,id',
            'invoice_receipt' => 'sometimes|array|max:5', 
            'invoice_receipt.*' => 'file|mimes:jpg,jpeg,png,pdf|max:5120',

            'is_draft' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Validate items total equals total_amount
        $itemsTotal = collect($request->items)->sum('amount');
        if (abs($itemsTotal - $request->total_amount) > 0.01) {
            return response()->json([
                'success' => false,
                'message' => 'Items total must equal total amount',
                'details' => [
                    'items_total' => $itemsTotal,
                    'declared_total' => $request->total_amount,
                ]
            ], 400);
        }

        // Check custody balance if payment method is custody
        if ($request->payment_method === 'custody') {
            $custodyBalance = $this->quickCashService->getCustodyBalance(auth()->id());
            if ($custodyBalance < $request->total_amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient custody balance',
                    'details' => [
                        'custody_balance' => $custodyBalance,
                        'required_amount' => $request->total_amount,
                    ]
                ], 400);
            }
        }

        DB::beginTransaction();
        try {
            $expense = $this->quickCashService->createQuickCashExpense($request->all());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $request->is_draft
                    ? 'Quick cash expense saved as draft'
                    : 'Quick cash expense created successfully',
                'data' => new ExpenseDetailResource($expense->fresh())
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create quick cash expense',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update Quick Cash Expense (Draft only)
     * PUT /api/branch-manager/expenses/quick-cash/{expense}
     */
    public function update(Request $request, int $expense): JsonResponse
    {
        $expenseModel = Expense::with('quickCashExpense')->findOrFail($expense);

        // Check authorization
        if ($expenseModel->branch_manager_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this expense'
            ], 403);
        }

        if ($expenseModel->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft expenses can be updated',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'expense_date' => 'sometimes|date',
            'expense_name' => 'sometimes|string|max:255',
            'total_amount' => 'sometimes|numeric|min:0|max:500',
            'items' => 'sometimes|array|min:1',
            'items.*.title' => 'required_with:items|string|max:255',
            'items.*.amount' => 'required_with:items|numeric|min:0',
            'has_vat' => 'sometimes|boolean',
            'invoice_number' => 'nullable|string|max:100',
            'payment_method' => 'sometimes|in:cash,supplier,custody',
            'supplier_id' => 'required_if:payment_method,supplier|exists:suppliers,id',
            'invoice_receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Validate items total if provided
        if (isset($request->items)) {
            $itemsTotal = collect($request->items)->sum('amount');
            $totalAmount = $request->total_amount ?? $expenseModel->total_amount;

            if (abs($itemsTotal - $totalAmount) > 0.01) {
                return response()->json([
                    'success' => false,
                    'message' => 'Items total must equal total amount',
                ], 400);
            }
        }

        DB::beginTransaction();
        try {
            $updated = $this->quickCashService->updateQuickCashExpense($expenseModel, $request->all());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Quick cash expense updated successfully',
                'data' => new ExpenseDetailResource($updated->fresh())
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update quick cash expense',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete Draft Quick Cash Expense
     * DELETE /api/branch-manager/expenses/quick-cash/{expense}
     */
    public function destroy(int $expense): JsonResponse
    {
        $expenseModel = Expense::findOrFail($expense);

        // Check authorization
        if ($expenseModel->branch_manager_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this expense'
            ], 403);
        }

        if ($expenseModel->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft expenses can be deleted',
            ], 400);
        }

        try {
            $expenseModel->delete();

            return response()->json([
                'success' => true,
                'message' => 'Quick cash expense deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete expense',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate VAT for Quick Cash
     * POST /api/branch-manager/expenses/quick-cash/calculate-vat
     */
    public function calculateVAT(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'total_amount' => 'required|numeric|min:0',
            'has_vat' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $calculation = $this->quickCashService->calculateVAT(
            $request->total_amount,
            $request->input('has_vat', true)
        );

        return response()->json([
            'success' => true,
            'data' => $calculation
        ]);
    }
}
