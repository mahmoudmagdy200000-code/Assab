<?php

namespace Modules\Expense\Http\Controllers;

use App\Http\Controllers\BaseController;
use App\Http\Resources\UnifiedTimelineResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Expense\Repositories\ExpenseRepository;
use Modules\Expense\Services\ExpenseApprovalService;
use Modules\Expense\Transformers\ExpenseDetailResource;
use Modules\Expense\Transformers\ExpenseResource;

/**
 * Expense Approval Controller
 * For Brand Owner to approve/reject expenses. Data access via ExpenseRepository.
 */
class ExpenseApprovalController extends BaseController
{
    public function __construct(
        private ExpenseApprovalService $approvalService,
        private ExpenseRepository $expenseRepository
    ) {}

    /**
     * Get all pending expenses for approval
     * GET /api/brand-owner/expenses
     */
    public function index(Request $request): JsonResponse
    {
        $expenses = $this->expenseRepository->getPaginatedForApproval($request);

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
        $expenseModel = $this->expenseRepository->findForShow($expense);

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
            $expenseModel = $this->expenseRepository->findOrFail($expense);

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
            $expenseModel = $this->expenseRepository->findOrFail($expense);

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
            $expenseModel = $this->expenseRepository->findOrFail($expense);

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
            $expenseModel = $this->expenseRepository->findOrFail($expense);

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
        $expenseModel = $this->expenseRepository->findOrFail($expense);

        $timeline = $expenseModel->timelines()
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->successResponse(
            UnifiedTimelineResource::collection($timeline),
            'Expense timeline retrieved successfully'
        );
    }
}
