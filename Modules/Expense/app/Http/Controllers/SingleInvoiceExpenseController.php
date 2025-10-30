<?php

namespace Modules\Expense\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Expense\Services\SingleInvoiceExpenseService;
use Modules\Expense\Models\Expense;
use Modules\Expense\Transformers\ExpenseDetailResource;
use App\Http\Controllers\BaseController;

/**
 * Single Invoice Expense Controller
 * For expenses > 500 SAR
 */
class SingleInvoiceExpenseController extends BaseController
{
    public function __construct(
        private SingleInvoiceExpenseService $singleInvoiceService
    ) {}

    /**
     * Create Single Invoice Expense
     * POST /api/branch-manager/expenses/single-invoice
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'supplier_id' => 'required|exists:suppliers,id',
            'invoice_number' => 'required|string|max:100',
            'total_amount' => 'required|numeric|min:0',
            'issue_date' => 'required|date',
            'is_tax_invoice' => 'required|boolean',
            // 'tax_id' => 'nullable|string|max:50',
            'tax_invoice_details' => 'required_if:is_tax_invoice,true|array',
            'tax_invoice_details.supplier_name' => 'required_if:is_tax_invoice,true|string|max:255',
            'tax_invoice_details.net_amount' => 'required_if:is_tax_invoice,true|numeric|min:0',
            'tax_invoice_details.vat_amount' => 'required_if:is_tax_invoice,true|numeric|min:0',
            'tax_invoice_details.total_amount' => 'required_if:is_tax_invoice,true|numeric|min:0',


            // Invoice items (purchases)
            'items' => 'sometimes|array',
            'items.*.category_id' => 'required_with:items|exists:categories,id',
            'items.*.name' => 'required_with:items|string|max:255',
            'items.*.quantity' => 'required_with:items|numeric|min:0.01',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',

            // Expense lines
            'expenses' => 'sometimes|array',
            'expenses.*.category_id' => 'required_with:expenses|exists:categories,id',
            'expenses.*.name' => 'required_with:expenses|string|max:255',
            'expenses.*.price' => 'required_with:expenses|numeric|min:0',

            // Payment method
            'payment_type' => 'required|in:full,partial,deferred',
            'payment_method' => 'required_if:payment_type,full|in:cash,supplier,custody',
            'paid_amount' => 'required_if:payment_type,partial|numeric|min:0',
            'due_date' => 'required_if:payment_type,partial,deferred|date|after:today',

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

        // Validate total amount > 500
        $totalAmount = $this->singleInvoiceService->calculateTotalAmount($request->all());
        if ($totalAmount <= 500) {
            return $this->errorResponse(
                'Single invoice expenses must be greater than 500 SAR',
                400,
                ['total_amount' => $totalAmount]

            );
        }

        // Check custody balance if payment method is custody
        if ($request->payment_method === 'custody') {
            // TODO: Implement custody balance check
        }

        DB::beginTransaction();
        try {
            $expense = $this->singleInvoiceService->createSingleInvoice($request->all());

            DB::commit();

            return $this->createdResponse(
                new ExpenseDetailResource($expense),
                'Single invoice expense created successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse(
                'Failed to create single invoice expense',
                500,
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Update Single Invoice Expense (Draft only)
     * PUT /api/branch-manager/expenses/single-invoice/{expense}
     */
    public function update(Request $request, int $expense): JsonResponse
    {
        $expenseModel = Expense::with(['invoiceDetails', 'items', 'expenseLines'])
            ->findOrFail($expense);

        // Check authorization
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
            'supplier_id' => 'sometimes|exists:suppliers,id',
            'invoice_number' => 'sometimes|string|max:100',
            'issue_date' => 'sometimes|date',
            'is_tax_invoice' => 'sometimes|boolean',
            'tax_id' => 'nullable|string|max:50',
            'tax_invoice_details' => 'sometimes|array',
            'tax_invoice_details.supplier_name' => 'sometimes|string|max:255',
            'tax_invoice_details.net_amount' => 'sometimes|numeric|min:0',
            'tax_invoice_details.vat_amount' => 'sometimes|numeric|min:0',
            'tax_invoice_details.total_amount' => 'sometimes|numeric|min:0',

            'items' => 'sometimes|array',
            'expenses' => 'sometimes|array',
            'payment_type' => 'sometimes|in:full,partial,deferred',
            'payment_method' => 'sometimes|in:cash,supplier,custody',
            'paid_amount' => 'sometimes|numeric|min:0',
            'due_date' => 'sometimes|date|after:today',
            'invoice_receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $validator->errors()
            );
        }

        // Validate total amount > 500 if items/expenses changed
        if (isset($request->items) || isset($request->expenses)) {
            $data = array_merge($expenseModel->toArray(), $request->all());
            $totalAmount = $this->singleInvoiceService->calculateTotalAmount($data);

            if ($totalAmount <= 500) {
                return $this->errorResponse(
                    'Single invoice expenses must be greater than 500 SAR',
                    400,
                    ['total_amount' => $totalAmount]
                );
            }
        }

        DB::beginTransaction();
        try {
            $updated = $this->singleInvoiceService->updateSingleInvoice($expenseModel, $request->all());

            DB::commit();

            return $this->successResponse(
                new ExpenseDetailResource($updated->fresh()),
                'Single invoice expense updated successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse(
                'Failed to update single invoice expense',
                500,
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Get previously submitted invoices for reuse
     * GET /api/branch-manager/expenses/single-invoice/previous
     */
    public function getPreviousInvoices(Request $request): JsonResponse
    {
        try {
            $invoices = $this->singleInvoiceService->getPreviousInvoices(
                auth()->id(),
                $request->input('search')
            );

            // ✅ هنا نستخدم paginatedResponse لأن الـ service بترجع Paginator
            return $this->paginatedResponse(
                $invoices,
                'Previous invoices retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve previous invoices',
                500,
                ['error' => $e->getMessage()]
            );
        }
    }
}
