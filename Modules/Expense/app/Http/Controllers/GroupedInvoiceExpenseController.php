<?php

namespace Modules\Expense\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Expense\Services\GroupedInvoiceExpenseService;
use Modules\Expense\Models\Expense;
use Modules\Expense\Transformers\ExpenseDetailResource;

/**
 * Grouped Invoices Controller
 * For multiple invoices > 500 SAR total
 */
class GroupedInvoiceExpenseController extends BaseController
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
            'paid_amount' => 'nullable:payment_type,partial|numeric|min:0',
            'due_date' => 'required_if:payment_type,deferred|date|after:today',
            'default_supplier_id' => 'nullable|exists:suppliers,id',

            'invoices' => 'required|array|min:1',
            'invoices.*.supplier_id' => 'required|exists:suppliers,id',
            'invoices.*.invoice_number' => 'required|string|max:100',
            'invoices.*.issue_date' => 'required|date',
            'invoices.*.is_tax_invoice' => 'required|boolean',
            'invoices.*.tax_id' => 'nullable|string|max:50',

            'payment_supplier_id' => 'required_if:payment_method,supplier|exists:suppliers,id',

            'invoices.*.tax_invoice_details' => 'required_if:invoices.*.is_tax_invoice,true|array',
            'invoices.*.tax_invoice_details.supplier_name' => 'required_if:invoices.*.is_tax_invoice,true|string|max:255',
            'invoices.*.tax_invoice_details.net_amount' => 'required_if:invoices.*.is_tax_invoice,true|numeric|min:0',
            'invoices.*.tax_invoice_details.vat_amount' => 'required_if:invoices.*.is_tax_invoice,true|numeric|min:0',
            'invoices.*.tax_invoice_details.total_amount' => 'required_if:invoices.*.is_tax_invoice,true|numeric|min:0',

            'invoices.*.items' => 'sometimes|array',
            'invoices.*.items.*.category_id' => 'required_with:invoices.*.items|exists:categories,id',
            'invoices.*.items.*.name' => 'required_with:invoices.*.items|string|max:255',
            'invoices.*.items.*.quantity' => 'required_with:invoices.*.items|numeric|min:0.01',
            'invoices.*.items.*.unit_price' => 'required_with:invoices.*.items|numeric|min:0',

            'invoices.*.expenses' => 'sometimes|array',
            'invoices.*.expenses.*.category_id' => 'required_with:invoices.*.expenses|exists:categories,id',
            'invoices.*.expenses.*.name' => 'required_with:invoices.*.expenses|string|max:255',
            'invoices.*.expenses.*.price' => 'required_with:invoices.*.expenses|numeric|min:0',

            'invoices.*.invoice_receipts' => 'sometimes|array',
            'invoices.*.invoice_receipts.*' => 'file|mimes:jpg,jpeg,png,pdf|max:5120',

            'is_draft' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $validator->errors()->toArray()
            );
        }

        // Validate total amount > 500
        // $totalAmount = $this->groupedInvoiceService->calculateTotalAmount($request->invoices);
        // if ($totalAmount <= 500) {
        //     return $this->errorResponse(
        //         'Grouped invoice expenses must be greater than 500 SAR',
        //         400,
        //         ['total_amount' => $totalAmount]
        //     );
        // }

        DB::beginTransaction();
        try {
            $expense = $this->groupedInvoiceService->createGroupedInvoice($request->all());

            DB::commit();

            return $this->successResponse(
                new ExpenseDetailResource($expense),
                'Grouped invoice expense created successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse(
                'Failed to create grouped invoice expense',
                500,
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Update Grouped Invoice Expense (Draft only)
     * PUT /api/branch-manager/expenses/grouped-invoice/{expense}
     */
    public function update(Request $request, Expense $expense): JsonResponse
    {
        // Authorization check
        if ($expense->branch_manager_id !== auth()->id()) {
            return $this->errorResponse('Unauthorized access to this expense', 403);
        }

        // Status check
        if ($expense->status !== 'draft') {
            return $this->errorResponse('Only draft expenses can be updated', 400);
        }

        // Load relationships
        $expense->load(['groupedInvoice', 'invoiceDetails', 'items', 'expenseLines', 'attachments']);

        $validator = Validator::make($request->all(), [
            'payment_type' => 'sometimes|in:full,partial,deferred',
            'payment_method' => 'sometimes|in:cash,supplier,custody',
            'paid_amount' => 'sometimes|numeric|min:0',
            'due_date' => 'sometimes|date|after:today',
            'payment_supplier_id' => 'sometimes|exists:suppliers,id',
            'default_supplier_id' => 'sometimes|exists:suppliers,id',

            // Single invoice update
            'invoice_id' => 'sometimes|exists:invoice_details,id',
            'invoice_number' => 'sometimes|string|max:100',
            'issue_date' => 'sometimes|date',
            'tax_id' => 'nullable|string|max:50',
            'supplier_id' => 'sometimes|exists:suppliers,id',
            'invoice_receipts' => 'sometimes|array',
            'invoice_receipts.*' => 'file|mimes:jpg,jpeg,png,pdf|max:5120',

            // Full invoices replacement/update
            'invoices' => 'sometimes|array|min:1',
            'invoices.*.id' => 'sometimes|exists:invoice_details,id', // NEW: للتحديث
            'invoices.*.supplier_id' => 'required_with:invoices|exists:suppliers,id',
            'invoices.*.invoice_number' => 'required_with:invoices|string|max:100',
            'invoices.*.issue_date' => 'required_with:invoices|date',
            'invoices.*.is_tax_invoice' => 'required_with:invoices|boolean',
            'invoices.*.tax_id' => 'nullable|string|max:50',
            'invoices.*.tax_invoice_details' => 'sometimes|array',
            'invoices.*.items' => 'sometimes|array',
            'invoices.*.expenses' => 'sometimes|array',
            'invoices.*.invoice_receipts' => 'sometimes|array',
            'invoices.*.invoice_receipts.*' => 'file|mimes:jpg,jpeg,png,pdf|max:5120',
            'invoices.*.delete_attachments' => 'sometimes|array', // NEW: لحذف صور معينة
            'invoices.*.delete_attachments.*' => 'exists:expense_attachments,id',

            // Delete attachments (global)
            'delete_attachments' => 'sometimes|array',
            'delete_attachments.*' => 'exists:expense_attachments,id',

            'is_draft' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $validator->errors()->toArray()
            );
        }

        try {
            $updated = $this->groupedInvoiceService->updateGroupedInvoice($expense, $request->all());

            return $this->successResponse(
                new ExpenseDetailResource($updated),
                'Grouped invoice expense updated successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to update grouped invoice expense',
                500,
                ['error' => $e->getMessage()]
            );
        }
    }
}
