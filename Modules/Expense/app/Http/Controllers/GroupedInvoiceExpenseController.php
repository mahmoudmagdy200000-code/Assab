<?php

namespace Modules\Expense\Http\Controllers;

use App\Http\Controllers\BaseController;
use Faker\Provider\Base;
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
            'payment_method' => 'required_if:payment_type,in:full,partial|in:cash,supplier,custody',
            'supplier_id' => 'required_if:payment_method,supplier|exists:suppliers,id',
            'paid_amount' => 'required_if:payment_type,partial|numeric|min:0',
            'due_date' => 'required_if:payment_type,in:partial,deferred|date|after:today',
            'custody_balance' => 'required_if:payment_method,custody|numeric|min:0',

            // QR Code data (optional)
            'qr_code_data' => 'sometimes|string',

            // Invoices array
            'invoices' => 'required|array|min:1',
            'invoices.*.supplier_id' => 'required|exists:suppliers,id',
            'invoices.*.invoice_number' => 'required|string|max:100',
            'invoices.*.issue_date' => 'required|date',
            'invoices.*.is_tax_invoice' => 'required|boolean',
            'invoices.*.tax_id' => 'nullable|string|max:50',
            'invoices.*.qr_code_scanned' => 'sometimes|boolean',

            // Tax Invoice Details
            'invoices.*.tax_invoice_details' => 'required_if:invoices.*.is_tax_invoice,true|array',
            'invoices.*.tax_invoice_details.supplier_id' => 'required_if:invoices.*.is_tax_invoice,true|exists:suppliers,id',
            'invoices.*.tax_invoice_details.net_amount' => 'required_if:invoices.*.is_tax_invoice,true|numeric|min:0',
            'invoices.*.tax_invoice_details.vat_amount' => 'required_if:invoices.*.is_tax_invoice,true|numeric|min:0',
            'invoices.*.tax_invoice_details.total_amount' => 'required_if:invoices.*.is_tax_invoice,true|numeric|min:0',

            // Items per invoice with categories
            'invoices.*.items' => 'sometimes|array',
            'invoices.*.items.*.category_id' => 'required_with:invoices.*.items|exists:categories,id',
            'invoices.*.items.*.subcategory_id' => 'sometimes|exists:subcategories,id',
            'invoices.*.items.*.name' => 'required_with:invoices.*.items|string|max:255',
            'invoices.*.items.*.quantity' => 'required_with:invoices.*.items|numeric|min:0.01',
            'invoices.*.items.*.unit_price' => 'required_with:invoices.*.items|numeric|min:0',

            // Expenses per invoice with categories
            'invoices.*.expenses' => 'sometimes|array',
            'invoices.*.expenses.*.category_id' => 'required_with:invoices.*.expenses|exists:categories,id',
            'invoices.*.expenses.*.subcategory_id' => 'sometimes|exists:subcategories,id',
            'invoices.*.expenses.*.name' => 'required_with:invoices.*.expenses|string|max:255',
            'invoices.*.expenses.*.price' => 'required_with:invoices.*.expenses|numeric|min:0',

            // Receipts per invoice
            'invoices.*.invoice_receipts' => 'sometimes|array',
            'invoices.*.invoice_receipts.*' => 'file|mimes:jpg,jpeg,png,pdf|max:5120',

            'is_draft' => 'sometimes|boolean',
            'template_id' => 'sometimes|exists:expense_templates,id', // For reusing previous invoices
        ]);

        // Add custom validation for custody balance
        if ($request->payment_method === 'custody') {
            $totalAmount = $this->groupedInvoiceService->calculateTotalAmount($request->invoices);
            if ($totalAmount > $request->custody_balance) {
                return $this->errorResponse(
                    'Insufficient custody balance',
                    422,
                    ['custody_balance' => ['The total amount exceeds available custody balance']]
                );
            }
        }

        if ($validator->fails()) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $validator->errors()->toArray()
            );
        }

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
            return $this->errorResponse($e->getMessage());
        }
    }
}
