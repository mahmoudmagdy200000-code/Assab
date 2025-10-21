<?php

namespace Modules\Expense\Http\Controllers;

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
class ExpenseApprovalController extends Controller
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

        return response()->json([
            'success' => true,
            'message' => 'Expenses retrieved successfully',
            'data' => ExpenseResource::collection($expenses),
            'meta' => [
                'current_page' => $expenses->currentPage(),
                'total' => $expenses->total(),
                'per_page' => $expenses->perPage(),
            ]
        ]);
    }

    /**
     * View expense details
     * GET /api/brand-owner/expenses/{expense}
     */
    public function show(int $expense): JsonResponse
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

        return response()->json([
            'success' => true,
            'message' => 'Expense details retrieved successfully',
            'data' => new ExpenseDetailResource($expenseModel)
        ]);
    }

    /**
     * Mark expense as viewed
     * POST /api/brand-owner/expenses/{expense}/view
     */
    public function markAsViewed(int $expense): JsonResponse
    {
        try {
            $expenseModel = Expense::findOrFail($expense);

            $this->approvalService->markAsViewed($expenseModel, auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Expense marked as viewed'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark as viewed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve expense
     * POST /api/brand-owner/expenses/{expense}/approve
     */
    public function approve(int $expense): JsonResponse
    {
        try {
            $expenseModel = Expense::findOrFail($expense);

            $this->approvalService->approveExpense($expenseModel, auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Expense approved successfully',
                'data' => [
                    'expense' => new ExpenseDetailResource($expenseModel->fresh()),
                    'approval' => [
                        'approved_by' => auth()->user()->name,
                        'approved_at' => now()->format('Y-m-d H:i:s'),
                    ]
                ]
            ]);
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
    public function reject(Request $request, int $expense): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|min:10|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $expenseModel = Expense::findOrFail($expense);

            $this->approvalService->rejectExpense($expenseModel, auth()->id(), $request->reason);

            return response()->json([
                'success' => true,
                'message' => 'Expense rejected successfully',
                'data' => [
                    'expense' => new ExpenseDetailResource($expenseModel->fresh()),
                    'rejection' => [
                        'rejected_by' => auth()->user()->name,
                        'rejected_at' => now()->format('Y-m-d H:i:s'),
                        'reason' => $request->reason,
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Edit expense (Brand Owner can make corrections)
     * PUT /api/brand-owner/expenses/{expense}/edit
     */
    public function edit(Request $request, int $expense): JsonResponse
    {
        // This allows brand owner to make minor corrections
        // Implementation depends on what fields can be edited

        try {
            $expenseModel = Expense::findOrFail($expense);

            // Record the edit in timeline
            $this->approvalService->recordEdit($expenseModel, auth()->id(), $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Expense edited successfully',
                'data' => new ExpenseDetailResource($expenseModel->fresh())
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to edit expense',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get expense timeline
     * GET /api/brand-owner/expenses/{expense}/timeline
     */
    public function timeline(int $expense): JsonResponse
    {
        $expenseModel = Expense::findOrFail($expense);

        $timeline = $expenseModel->timelines()
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Expense timeline retrieved successfully',
            'data' => ExpenseTimelineResource::collection($timeline)
        ]);
    }
}
