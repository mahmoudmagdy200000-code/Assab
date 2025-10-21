<?php

namespace Modules\Expense\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Expense\Services\GroupedInvoiceExpenseService;
use Modules\Expense\Models\Expense;
use Modules\Expense\Transformers\ExpenseDetailResource;

/**
 * Grouped Invoices Controller
 * For multiple invoices > 500 SAR total
 */
class GroupedInvoiceExpenseController extends Controller
{
    public function __construct(
        private GroupedInvoiceExpenseService $groupedInvoiceService
    ) {}

    /**
     * Create Grouped Invoice Expense
     * POST /api/branch-manager/expenses/grouped-invoice
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_type' => 'required|in:full,partial,deferred',
            'payment_method' => 'required_if:payment_type,full|in:cash,supplier,custody',
            'paid_amount' => 'required_if:payment_type,partial|numeric|min:0',
            'due_date' => 'required_if:payment_type,partial,deferred|date|after:today',

            // Invoices array
            'invoices' => 'required|array|min:1',
            'invoices.*.supplier_id' => 'required|exists:suppliers,id',
            'invoices.*.invoice_number' => 'required|string|max:100',
            'invoices.*.issue_date' => 'required|date',
            'invoices.*.is_tax_invoice' => 'required|boolean',
            'invoices.*.tax_id' => 'nullable|string|max:50',

            // Items per invoice
            'invoices.*.items' => 'sometimes|array',
            'invoices.*.items.*.category_id' => 'required_with:invoices.*.items|exists:categories,id',
            'invoices.*.items.*.name' => 'required_with:invoices.*.items|string|max:255',
            'invoices.*.items.*.quantity' => 'required_with:invoices.*.items|numeric|min:0.01',
            'invoices.*.items.*.unit_price' => 'required_with:invoices.*.items|numeric|min:0',

            // Expenses per invoice
            'invoices.*.expenses' => 'sometimes|array',
            'invoices.*.expenses.*.category_id' => 'required_with:invoices.*.expenses|exists:categories,id',
            'invoices.*.expenses.*.name' => 'required_with:invoices.*.expenses|string|max:255',
            'invoices.*.expenses.*.price' => 'required_with:invoices.*.expenses|numeric|min:0',

            // Receipt per invoice
            'invoices.*.invoice_receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',

            'is_draft' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Validate total amount > 500
        $totalAmount = $this->groupedInvoiceService->calculateTotalAmount($request->invoices);
        if ($totalAmount <= 500) {
            return response()->json([
                'success' => false,
                'message' => 'Grouped invoices total must be greater than 500 SAR',
                'total_amount' => $totalAmount,
            ], 400);
        }

        DB::beginTransaction();
        try {
            $expense = $this->groupedInvoiceService->createGroupedInvoice($request->all());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $request->is_draft
                    ? 'Grouped invoice expense saved as draft'
                    : 'Grouped invoice expense created successfully',
                'data' => new ExpenseDetailResource($expense->fresh())
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create grouped invoice expense',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
