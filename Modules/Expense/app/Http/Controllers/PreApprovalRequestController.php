<?php

namespace Modules\Expense\Http\Controllers;

use App\Http\Controllers\BaseController;
use Faker\Provider\Base;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Expense\Services\PreApprovalRequestService;
use Modules\Expense\Models\Expense;
use Modules\Expense\Transformers\ExpenseDetailResource;

/**
 * Pre-Approval Request Controller
 * For expenses > 500 SAR (requires approval before purchase)
 */
class PreApprovalRequestController extends BaseController
{
    public function __construct(
        private PreApprovalRequestService $preApprovalService
    ) {}

    /**
     * Create Pre-Approval Request
     * POST /api/branch-manager/expenses/pre-approval
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'purpose' => 'required|string|max:500',
            'estimated_amount' => 'required|numeric|min:500',
            'payment_method' => 'required|in:cash,supplier,custody',
            'supplier_id' => 'required_if:payment_method,supplier|exists:suppliers,id',
            'priority' => 'required|in:high,medium,low',

            // Items list
            'items' => 'sometimes|array',
            'items.*.category_id' => 'required_with:items|exists:categories,id',
            'items.*.description' => 'required_with:items|string|max:255',
            'items.*.quantity' => 'required_with:items|numeric|min:0.01',
            'items.*.rate' => 'required_with:items|numeric|min:0',

            // Expenses list
            'expenses' => 'sometimes|array',
            'expenses.*.category_id' => 'required_with:expenses|exists:categories,id',
            'expenses.*.description' => 'required_with:expenses|string|max:255',
            'expenses.*.price' => 'required_with:expenses|numeric|min:0',

            'attachments' => 'sometimes|array',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png|max:5120',

            'is_draft' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $validator->errors()->toArray()
            );
        }

        // Validate estimated amount >= 500
        if ($request->estimated_amount < 500) {
            return $this->errorResponse('Pre-approval requests must be for amounts >= 500 SAR', 400);
        }

        DB::beginTransaction();
        try {
            $expense = $this->preApprovalService->createPreApprovalRequest($request->all());

            DB::commit();

            return $this->successResponse(
                new ExpenseDetailResource($expense),
                $request->is_draft
                    ? 'Pre-approval request saved as draft'
                    : 'Pre-approval request created successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse(
                'Failed to create pre-approval request: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Update Pre-Approval Request (Draft only)
     * PUT /api/branch-manager/expenses/pre-approval/{expense}
     */
    public function update(Request $request, int $expense): JsonResponse
    {
        $expenseModel = Expense::with(['preApprovalRequest', 'items', 'expenseLines'])
            ->findOrFail($expense);

        // Check authorization
        if ($expenseModel->branch_manager_id !== auth()->id()) {
            return $this->errorResponse('Unauthorized to update this pre-approval request', 403);
        }

        if ($expenseModel->status !== 'draft') {
            return $this->errorResponse('Only draft pre-approval requests can be updated', 400);
        }

        $validator = Validator::make($request->all(), [
            'purpose' => 'sometimes|string|max:500',
            'estimated_amount' => 'sometimes|numeric|min:500',
            'payment_method' => 'sometimes|in:cash,supplier,custody',
            'supplier_id' => 'sometimes|exists:suppliers,id',
            'priority' => 'sometimes|in:high,medium,low',
            'items' => 'sometimes|array',
            'expenses' => 'sometimes|array',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $validator->errors()->toArray()
            );
        }

        // Validate estimated amount >= 500 if changed
        if (isset($request->estimated_amount) && $request->estimated_amount < 500) {
            return $this->errorResponse('Pre-approval requests must be for amounts >= 500 SAR', 400);
        }

        DB::beginTransaction();
        try {
            $updated = $this->preApprovalService->updatePreApprovalRequest($expenseModel, $request->all());

            DB::commit();

            return $this->successResponse(
                new ExpenseDetailResource($updated),
                'Pre-approval request updated successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse(
                'Failed to update pre-approval request: ' . $e->getMessage(),
                500
            );
        }
    }
}
