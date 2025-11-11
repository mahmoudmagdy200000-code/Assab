<?php

namespace Modules\Expense\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Modules\Expense\Models\Expense;
use Modules\Expense\Services\ExpenseApprovalService;
use Modules\Expense\Transformers\{ExpenseResource, ExpenseDetailResource, ExpenseTimelineResource};

/**
 * Expense Approval Controller
 * For Brand Owner to approve/reject expenses
 */
class ExpenseApprovalController extends BaseController
{
    public function __construct(
        private ExpenseApprovalService $approvalService
    ) {}

    /**
     * Get all pending expenses for approval
     * GET /api/brand-owner/expenses
     */
    public function index(Request $request): JsonResponse
    {
        $query = Expense::whereIn('status', ['pending', 'approved', 'rejected'])
            ->with(['quickCashExpense', 'invoiceDetails', 'groupedInvoice', 'preApprovalRequest', 'branchManager']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by branch manager
        if ($request->has('branch_manager_id')) {
            $query->where('branch_manager_id', $request->branch_manager_id);
        }

        $expenses = $query->orderBy('submitted_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return $this->paginatedResponse(
            ExpenseResource::collection($expenses),
            'Expenses retrieved successfully'
        );
    }

    /**
     * View expense details
     * GET /api/brand-owner/expenses/{expense}
     */
    public function show(string $expense): JsonResponse
    {
        $expenseModel = Expense::with([
            'quickCashExpense.items',
            'invoiceDetails',
            'groupedInvoice.invoiceDetails.items',
            'groupedInvoice.invoiceDetails.expenseLines',
            'preApprovalRequest',
            'items.category',
            'expenseLines.category',
            'attachments',
            'supplier',
            'branchManager'
        ])->findOrFail($expense);

        return $this->successResponse(
            new ExpenseDetailResource($expenseModel),
            'Expense details retrieved successfully'
        );
    }

    /**
     * Mark expense as viewed
     * POST /api/brand-owner/expenses/{expense}/view
     */
    public function markAsViewed(string $expense): JsonResponse
    {
        try {
            $expenseModel = Expense::findOrFail($expense);

            $this->approvalService->markAsViewed($expenseModel, auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Expense marked as viewed'
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Approve expense
     * POST /api/brand-owner/expenses/{expense}/approve
     */
    public function approve(string $expense): JsonResponse
    {
        try {
            $expenseModel = Expense::findOrFail($expense);

            $this->approvalService->approveExpense($expenseModel, auth()->id());

            return $this->successResponse(
                new ExpenseDetailResource($expenseModel->fresh()),
                'Expense approved successfully'
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Reject expense
     * POST /api/brand-owner/expenses/{expense}/reject
     */
    public function reject(Request $request, string $expense): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|min:10|max:500',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $validator->errors()->toArray()
            );
        }

        try {
            $expenseModel = Expense::findOrFail($expense);

            $this->approvalService->rejectExpense($expenseModel, auth()->id(), $request->reason);

            return $this->successResponse(
                new ExpenseDetailResource($expenseModel->fresh()),
                'Expense rejected successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Edit expense (Brand Owner can make corrections)
     * PUT /api/brand-owner/expenses/{expense}/edit
     */
    public function edit(Request $request, string $expense): JsonResponse
    {
        // This allows brand owner to make minor corrections
        // Implementation depends on what fields can be edited

        try {
            $expenseModel = Expense::findOrFail($expense);

            // Record the edit in timeline
            $this->approvalService->recordEdit($expenseModel, auth()->id(), $request->all());

            return $this->successResponse(
                new ExpenseDetailResource($expenseModel->fresh()),
                'Expense edited successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Get expense timeline
     * GET /api/brand-owner/expenses/{expense}/timeline
     */
    public function timeline(string $expense): JsonResponse
    {
        $expenseModel = Expense::findOrFail($expense);

        $timeline = $expenseModel->timelines()
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->successResponse(
            ExpenseTimelineResource::collection($timeline),
            'Expense timeline retrieved successfully'
        );
    }
}
