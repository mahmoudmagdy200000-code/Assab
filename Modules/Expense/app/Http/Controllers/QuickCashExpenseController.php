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
use App\Http\Controllers\BaseController;

/**
 * Quick Cash Expense Controller
 * For expenses < 500 SAR
 */
class QuickCashExpenseController extends BaseController
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

            'items' => 'nullable|array',
            'items.*.title' => 'nullable|string|max:255',
            'items.*.amount' => 'nullable|numeric|min:0',

            'has_vat' => 'required|boolean',
            'vat_amount' => 'nullable|numeric|min:0',
            'net_amount' => 'nullable|numeric|min:0',
            'vat_total_amount' => 'nullable|numeric|min:0',

            'invoice_number' => 'nullable|string|max:100',
            'payment_method' => 'required|in:cash,supplier,custody',
            'supplier_id' => 'required_if:payment_method,supplier|exists:suppliers,id',

            'invoice_receipt' => 'sometimes|array|max:5',
            'invoice_receipt.*' => 'file|mimes:jpg,jpeg,png,pdf|max:5120',

            'is_draft' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $validator->errors()
            );
        }

        // ✅ Validate items total equals total_amount
        $itemsTotal = collect($request->items ?? [])->sum('amount');
        if (!empty($request->items) && abs($itemsTotal - $request->total_amount) > 0.01) {
            return $this->errorResponse(
                'Items total must equal total amount',
                400
            );
        }

        // ✅ Check custody balance if payment method is custody
        if ($request->payment_method === 'custody') {
            $custodyBalance = $this->quickCashService->getCustodyBalance(auth()->id());
            if ($custodyBalance < $request->total_amount) {
                return $this->errorResponse(
                    'Insufficient custody balance',
                    400,
                    ['custody_balance' => $custodyBalance]
                );
            }
        }

        DB::beginTransaction();
        try {
            $expense = $this->quickCashService->createQuickCashExpense($request->all());
            DB::commit();

            return $this->createdResponse(
                new ExpenseDetailResource($expense),
                'Quick cash expense created successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Update Draft Quick Cash Expense
     */
    public function update(Request $request, string $expense): JsonResponse
    {
        $expenseModel = Expense::with('quickCashExpense')->findOrFail($expense);

        if ($expenseModel->branch_manager_id !== auth()->id()) {
            return $this->errorResponse(
                'Unauthorized access to this expense',
                403
            );
        }

        if ($expenseModel->status !== 'draft') {
            return $this->errorResponse(
                'Only draft expenses can be updated',
                400
            );
        }

        $validator = Validator::make($request->all(), [
            'expense_date' => 'sometimes|date',
            'expense_name' => 'sometimes|string|max:255',
            'total_amount' => 'sometimes|numeric|min:0|max:500',

            'items' => 'sometimes|array|min:1',
            'items.*.title' => 'required_with:items|string|max:255',
            'items.*.amount' => 'required_with:items|numeric|min:0',

            'vat_total_amount' => 'nullable|numeric|min:0',
            'net_amount' => 'nullable|numeric|min:0',
            'vat_amount' => 'nullable|numeric|min:0',
            'has_vat' => 'sometimes|boolean',
            'invoice_number' => 'nullable|string|max:100',
            'payment_method' => 'sometimes|in:cash,supplier,custody',
            'supplier_id' => 'required_if:payment_method,supplier|exists:suppliers,id',
            'invoice_receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $validator->errors()
            );
        }

        if (isset($request->items)) {
            $itemsTotal = collect($request->items)->sum('amount');
            $totalAmount = $request->total_amount ?? $expenseModel->total_amount;
            if (abs($itemsTotal - $totalAmount) > 0.01) {
                return $this->errorResponse(
                    'Items total must equal total amount',
                    400
                );
            }
        }

        DB::beginTransaction();
        try {
            $updated = $this->quickCashService->updateQuickCashExpense($expenseModel, $request->all());
            DB::commit();

            return $this->successResponse(
                new ExpenseDetailResource($updated->fresh()),
                'Quick cash expense updated successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Calculate VAT (API)
     */
    public function calculateVAT(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'total_amount' => 'required|numeric|min:0',
            'has_vat' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $validator->errors()
            );
        }

        // ✅ الآن ترسل رقم فقط، والخدمة تتعامل معه بمرونة
        $calculation = $this->quickCashService->calculateVAT(
            $request->total_amount,
            $request->input('has_vat', true)
        );

        return $this->successResponse(
            $calculation,
            'VAT calculated successfully',
        );
    }
}
