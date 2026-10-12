<?php

namespace Modules\Expense\Http\Controllers;

use App\Http\Controllers\BaseController;
use App\Http\Resources\UnifiedTimelineResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Expense\Enums\ExpenseDecisionRole;
use Modules\Expense\Models\Expense;
use Modules\Expense\Repositories\ExpenseRepository;
use Modules\Expense\Services\ExpenseApprovalService;
use Modules\Expense\Services\ExpenseBrandScopeService;
use Modules\Expense\Transformers\ExpenseDetailResource;
use Modules\Expense\Transformers\ExpenseResource;

/**
 * Expense Approval Controller
 * For Brand Owner / Brand Manager to approve/reject expenses. Data access via ExpenseRepository.
 *
 * Every lookup is confined to the branches the authenticated actor is assigned to
 * (ExpenseBrandScopeService). An out-of-scope id is a uniform 404 — indistinguishable
 * from a non-existent one — and returns BEFORE anything is written.
 */
class ExpenseApprovalController extends BaseController
{
    public function __construct(
        private ExpenseApprovalService $approvalService,
        private ExpenseRepository $expenseRepository,
        private ExpenseBrandScopeService $scope
    ) {}

    /** @return string[] branch ids the authenticated brand actor may access (empty = fail closed) */
    private function branchScope(): array
    {
        $actor = auth()->user();

        return $actor instanceof BrandOwner ? $this->scope->branchIdsFor($actor) : [];
    }

    /** The expense if it is inside the actor's scope, else null (callers answer 404). */
    private function scopedExpense(string $id): ?Expense
    {
        return $this->expenseRepository->findInBranchScope($id, $this->branchScope());
    }

    private function expenseNotFound(): JsonResponse
    {
        return $this->errorResponse('Expense not found', 404);
    }

    /**
     * Get all pending expenses for approval
     * GET /api/brand-owner/expenses
     */
    public function index(Request $request): JsonResponse
    {
        $expenses = $this->expenseRepository->getPaginatedForApprovalInBranchScope($request, $this->branchScope());

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
        $expenseModel = $this->expenseRepository->findForShowInBranchScope($expense, $this->branchScope());

        if (! $expenseModel) {
            return $this->expenseNotFound();
        }

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
        $expenseModel = $this->scopedExpense($expense);

        if (! $expenseModel) {
            return $this->expenseNotFound();
        }

        try {
            $this->approvalService->markAsViewed($expenseModel, auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Expense marked as viewed',
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Approve expense
     * POST /api/brand-owner/expenses/{expense}/approve
     * POST /api/branch-manager/expenses/{expense}/approve
     */
    public function approve(string $expense): JsonResponse
    {
        if (! (auth()->user() instanceof \Modules\BrandOwner\Models\BrandOwner)) {
            return $this->errorResponse('Only brand owners can approve expenses', 403);
        }

        $expenseModel = $this->scopedExpense($expense);

        if (! $expenseModel) {
            return $this->expenseNotFound();
        }

        try {
            $this->approvalService->approveExpense(
                $expenseModel,
                auth()->id(),
                auth()->user()->name,
                ExpenseDecisionRole::forActor(auth()->user())
            );

            return $this->successResponse(
                new ExpenseDetailResource($expenseModel->fresh()),
                'Expense approved successfully'
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Reject expense
     * POST /api/brand-owner/expenses/{expense}/reject
     */
    public function reject(Request $request, string $expense): JsonResponse
    {
        if (! (auth()->user() instanceof \Modules\BrandOwner\Models\BrandOwner)) {
            return $this->errorResponse('Only brand owners can reject expenses', 403);
        }

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

        $expenseModel = $this->scopedExpense($expense);

        if (! $expenseModel) {
            return $this->expenseNotFound();
        }

        try {
            $this->approvalService->rejectExpense(
                $expenseModel,
                auth()->id(),
                $request->reason,
                auth()->user()->name,
                ExpenseDecisionRole::forActor(auth()->user())
            );

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

        $expenseModel = $this->scopedExpense($expense);

        if (! $expenseModel) {
            return $this->expenseNotFound();
        }

        try {
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
        $expenseModel = $this->scopedExpense($expense);

        if (! $expenseModel) {
            return $this->expenseNotFound();
        }

        $timeline = $expenseModel->timelines()
            ->orderBy('created_at', 'asc')
            ->get();

        return $this->successResponse(
            UnifiedTimelineResource::collection($timeline),
            'Expense timeline retrieved successfully'
        );
    }
}
