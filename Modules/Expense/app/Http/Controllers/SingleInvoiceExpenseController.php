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
use Modules\Expense\Transformers\PreviousInvoiceResource;
use App\Http\Controllers\BaseController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Expense\Transformers\ExpenseResource;

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
    public function update(Request $request, string $expense): JsonResponse
    {
        $expenseModel = Expense::with(['invoiceDetails', 'items', 'expenseLines', 'attachments'])
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
            'items.*.category_id' => 'required_with:items|exists:categories,id',
            'items.*.name' => 'required_with:items|string|max:255',
            'items.*.quantity' => 'required_with:items|numeric|min:0.01',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',

            'expenses' => 'sometimes|array',
            'expenses.*.category_id' => 'required_with:expenses|exists:categories,id',
            'expenses.*.name' => 'required_with:expenses|string|max:255',
            'expenses.*.price' => 'required_with:expenses|numeric|min:0',

            'payment_type' => 'sometimes|in:full,partial,deferred',
            'payment_method' => 'sometimes|in:cash,supplier,custody',
            'paid_amount' => 'sometimes|numeric|min:0',
            'due_date' => 'sometimes|date|after:today',

            // New attachments (don't delete old ones)
            'invoice_receipt' => 'sometimes|array|max:5',
            'invoice_receipt.*' => 'file|mimes:jpg,jpeg,png,pdf|max:5120',

            // Delete specific attachments by ID
            'delete_attachments' => 'sometimes|array',
            'delete_attachments.*' => 'string|exists:expense_attachments,id',
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
                new ExpenseDetailResource($updated->fresh(['attachments'])),
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
            $query = Expense::query()
                ->where('expense_type', 'single_invoice')
                ->where('branch_manager_id', auth()->id())
                ->with(['supplier', 'invoiceDetails'])
                ->orderBy('submitted_at', 'desc');

            // Apply search filter if provided
            if ($search = $request->input('search')) {
                $search = strtolower(trim($search));
                $query->where(function ($q) use ($search) {
                    $q->whereHas('invoiceDetails', function ($sq) use ($search) {
                        $sq->whereRaw('LOWER(invoice_number) LIKE ?', ["%{$search}%"]);
                    })->orWhereHas('supplier', function ($sq) use ($search) {
                        $sq->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]);
                    });
                });
            }

            $invoices = $query->paginate(10);

            return $this->paginatedResponse(
                ExpenseDetailResource::collection($invoices),
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

    /**
     * Duplicate a previous invoice as a new draft
     * POST /api/branch-manager/expenses/single-invoice/{expense}/duplicate
     */
    public function duplicate(Request $request, string $expense): JsonResponse
    {
        try {
            $originalExpense = Expense::with([
                'invoiceDetails',
                'items',
                'expenseLines',
                'supplier',
                'attachments'
            ])
                ->where('expense_type', 'single_invoice')
                ->findOrFail($expense);

            if ($originalExpense->branch_manager_id !== auth()->id()) {
                return $this->errorResponse(
                    'Unauthorized to duplicate this expense',
                    403
                );
            }

            $validator = Validator::make($request->all(), [
                'invoice_number' => 'sometimes|string|max:100',
                'issue_date' => 'sometimes|date',
                'supplier_id' => 'sometimes|exists:suppliers,id',
                'is_draft' => 'sometimes|boolean',
                'copy_attachments' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse(
                    'Validation failed',
                    422,
                    $validator->errors()
                );
            }

            DB::beginTransaction();

            $invoiceDetails = $originalExpense->invoiceDetails->first();

            $duplicateData = [
                'supplier_id' => $request->input('supplier_id', $originalExpense->supplier_id),
                'invoice_number' => $request->input('invoice_number', $invoiceDetails->invoice_number . '_نسخة'),
                'total_amount' => $originalExpense->total_amount,
                'issue_date' => $request->input('issue_date', now()->format('Y-m-d')),
                'is_tax_invoice' => $invoiceDetails->is_tax_invoice,
                'tax_id' => $invoiceDetails->tax_id,
                'payment_type' => $invoiceDetails->payment_type,
                'payment_method' => $originalExpense->payment_method,
                'paid_amount' => $invoiceDetails->paid_amount,
                'due_date' => $invoiceDetails->due_date,
                'is_draft' => $request->input('is_draft', true),
            ];

            if ($invoiceDetails->is_tax_invoice) {
                $duplicateData['tax_invoice_details'] = [
                    'supplier_name' => $invoiceDetails->tax_supplier_name,
                    'net_amount' => $invoiceDetails->tax_net_amount,
                    'vat_amount' => $invoiceDetails->tax_vat_amount,
                    'total_amount' => $invoiceDetails->tax_total_amount,
                ];
            }

            if ($originalExpense->items->isNotEmpty()) {
                $duplicateData['items'] = $originalExpense->items->map(function ($item) {
                    return [
                        'category_id' => $item->category_id,
                        'name' => $item->name,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                    ];
                })->toArray();
            }

            if ($originalExpense->expenseLines->isNotEmpty()) {
                $duplicateData['expenses'] = $originalExpense->expenseLines->map(function ($line) {
                    return [
                        'category_id' => $line->category_id,
                        'name' => $line->name,
                        'price' => $line->price,
                    ];
                })->toArray();
            }

            $newExpense = $this->singleInvoiceService->createSingleInvoice($duplicateData);

            $copyAttachments = $request->input('copy_attachments', true);
            if ($copyAttachments && $originalExpense->attachments->isNotEmpty()) {
                foreach ($originalExpense->attachments as $attachment) {
                    $this->duplicateAttachment($newExpense, $attachment);
                }
            }

            DB::commit();

            $newExpense->load('attachments');

            return $this->createdResponse(
                new ExpenseDetailResource($newExpense),
                'Invoice duplicated successfully as a draft'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(
                'Original expense not found',
                404
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse(
                'Failed to duplicate invoice',
                500,
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Duplicate attachment file (invoice receipt)
     */
    private function duplicateAttachment(Expense $newExpense, $originalAttachment): void
    {
        try {
            if (!Storage::disk('public')->exists($originalAttachment->file_path)) {
                return;
            }

            $extension = pathinfo($originalAttachment->file_path, PATHINFO_EXTENSION);
            $newFilename = 'expense_' . $newExpense->id . '_' . time() . '_' . uniqid() . '.' . $extension;
            $newPath = 'expenses/receipts/' . $newFilename;

            Storage::disk('public')->copy(
                $originalAttachment->file_path,
                $newPath
            );

            $newExpense->attachments()->create([
                'file_path' => $newPath,
                'file_name' => $originalAttachment->file_name,
                'file_type' => $originalAttachment->file_type,
                'file_size' => $originalAttachment->file_size,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to duplicate invoice receipt: ' . $e->getMessage());
        }
    }
}
