<?php

namespace Modules\Expense\Http\Controllers;

use App\Http\Controllers\BaseController;
use App\Http\Resources\UnifiedTimelineResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Custody\Services\CustodyBalanceService;
use Modules\Expense\Enums\ExpenseStatus;
use Modules\Expense\Enums\ExpenseTimelineAction;
use Modules\Expense\Enums\ExpenseTimelinePerformedByType;
use Modules\Expense\Enums\ExpenseTimelineStatus;
use Modules\Expense\Enums\PaymentMethod;
use Modules\Expense\Repositories\ExpenseRepository;
use Modules\Expense\Services\ExpenseApprovalService;
use Modules\Expense\Services\ExpenseHelperService;
use Modules\Expense\Transformers\ExpenseDetailResource;
use Modules\Expense\Transformers\ExpenseResource;

/**
 * Main Expense Controller
 * HTTP only: delegates to ExpenseRepository and services.
 */
class ExpenseController extends BaseController
{
    public function __construct(
        private CustodyBalanceService $custodyBalanceService,
        private ExpenseApprovalService $approvalService,
        private ExpenseHelperService $helperService,
        private ExpenseRepository $expenseRepository
    ) {}

    /**
     * Get expense summary (for dashboard)
     * GET /api/branch-manager/expenses/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);

        $user = auth()->user();
        if ($user instanceof \Modules\BrandOwner\Models\BrandOwner) {
            $expenses = $this->expenseRepository->getSummaryForBrandOwner($month, $year);
        } else {
            $expenses = $this->expenseRepository->getSummaryForManager(auth()->id(), $month, $year);
        }

        $summary = [
            'date_filter' => [
                'month' => $month,
                'year' => $year,
            ],
            'total_expenses' => $expenses->count(),
            'total_amount' => (float) $expenses->sum('total_amount'),
            'quick_cash_total' => (float) $expenses->where('expense_type', 'quick_cash')->sum('total_amount'),
            'single_invoice_total' => (float) $expenses->where('expense_type', 'single_invoice')->sum('total_amount'),
            'grouped_invoice_total' => (float) $expenses->where('expense_type', 'grouped_invoice')->sum('total_amount'),
            'pre_approval_total' => (float) $expenses->where('expense_type', 'pre_approval')->sum('total_amount'),
        ];

        return $this->successResponse(
            $summary,
            'Expense summary retrieved successfully'
        );
    }

    /**
     * Get custody balance for the authenticated branch manager.
     * Used when payment method is "custody" to display available balance.
     * GET /api/branch-manager/expenses/custody-balance
     */
    public function custodyBalance(): JsonResponse
    {
        $managerId = (string) auth()->id();
        $branchId = auth()->user()->branch_id ?? null;

        return $this->successResponse([
            // Spendable = branch custody (brand-owner granted) + personal
            // ledger (sales cash in hand) — the payment sheet reads `balance`.
            'balance' => $this->custodyBalanceService->getAvailableExpenseBalance($managerId, $branchId),
            'branch_custody_balance' => $this->custodyBalanceService->getCustodyBalance($managerId, $branchId),
            'personal_ledger_balance' => $this->custodyBalanceService->getPersonalLedgerBalance($managerId),
        ], 'Custody balance retrieved successfully');
    }

    /**
     * Get expense & timeline enums (statuses, actions, payment methods).
     * GET /api/branch-manager/expenses/enums
     */
    public function enums(): JsonResponse
    {
        $data = [
            'expense_status' => ExpenseStatus::forApi(),
            'timeline_action' => ExpenseTimelineAction::forApi(),
            'timeline_status' => ExpenseTimelineStatus::forApi(),
            'timeline_performed_by_type' => ExpenseTimelinePerformedByType::forApi(),
            'payment_method' => PaymentMethod::forApi(),
        ];

        return $this->successResponse($data, 'Enums retrieved successfully');
    }

    /**
     * Get recent expenses (top 10)
     * GET /api/branch-manager/expenses/recent
     */
    public function recent(): JsonResponse
    {
        $user = auth()->user();

        if ($user instanceof \Modules\BrandOwner\Models\BrandOwner) {
            $expenses = $this->expenseRepository->getRecentForBrandOwner(10);
        } else {
            $expenses = $this->expenseRepository->getRecentForManager(auth()->id(), 10);
        }

        return $this->successResponse(
            ExpenseResource::collection($expenses),
            'Recent expenses retrieved successfully'
        );
    }

    /**
     * Get all expenses with filters
     * GET /api/branch-manager/expenses
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        // Brand Owner: list non-draft expenses across all managers (drafts excluded by contract)
        if ($user instanceof \Modules\BrandOwner\Models\BrandOwner) {
            $expenses = $this->expenseRepository->getPaginatedForApproval($request);
        } else {
            $expenses = $this->expenseRepository->getPaginatedForManager(auth()->id(), $request);
        }

        return $this->paginatedResponse(
            ExpenseResource::collection($expenses),
            'Expenses retrieved successfully'
        );
    }

    /**
     * Get expense details
     * GET /api/branch-manager/expenses/{expense}
     */
    public function show(string $expense): JsonResponse
    {
        $expenseModel = $this->expenseRepository->findForShow($expense);
        $user = auth()->user();

        // Brand Owner may view any non-draft expense
        if ($user instanceof \Modules\BrandOwner\Models\BrandOwner) {
            if ($expenseModel->status === 'draft') {
                return $this->errorResponse('Draft expenses are not accessible to brand owners', 403);
            }
        } elseif ($expenseModel->branch_manager_id !== auth()->id()) {
            return $this->errorResponse('Unauthorized access', 403);
        }

        return $this->successResponse(
            new ExpenseDetailResource($expenseModel),
            'Expense details retrieved successfully'
        );
    }

    /**
     * Get expense timeline
     * GET /api/branch-manager/expenses/{expense}/timeline
     */
    public function timeline(string $expense): JsonResponse
    {
        $expenseModel = $this->expenseRepository->findOrFail($expense);

        if ($expenseModel->branch_manager_id !== auth()->id()) {
            return $this->errorResponse('Unauthorized access', 403);
        }

        $timeline = $expenseModel->timelines()
            ->orderBy('created_at', 'asc')
            ->get();

        return $this->successResponse(
            UnifiedTimelineResource::collection($timeline),
            'Expense timeline retrieved successfully'
        );
    }

    /**
     * Get draft expenses
     * GET /api/branch-manager/expenses/drafts
     */
    public function drafts(Request $request): JsonResponse
    {
        $drafts = $this->expenseRepository->getDraftsPaginated(
            auth()->id(),
            (int) $request->input('per_page', 20)
        );

        return $this->paginatedResponse(
            ExpenseResource::collection($drafts),
            'Draft expenses retrieved successfully'
        );
    }

    /**
     * Submit expense for approval
     * POST /api/branch-manager/expenses/{expense}/submit
     */
    public function submit(string $expense): JsonResponse
    {
        try {
            $expenseModel = $this->expenseRepository->findOrFail($expense);

            if ($expenseModel->branch_manager_id !== auth()->id()) {
                return $this->errorResponse('Unauthorized', 403);
            }

            $this->approvalService->submitExpense($expenseModel);

            return $this->successResponse(
                new ExpenseDetailResource($expenseModel->fresh()),
                'Expense submitted for approval successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Resubmit rejected expense
     * POST /api/branch-manager/expenses/{expense}/resubmit
     */
    public function resubmit(string $expense): JsonResponse
    {
        try {
            $expenseModel = $this->expenseRepository->findOrFail($expense);

            if ($expenseModel->branch_manager_id !== auth()->id()) {
                return $this->errorResponse('Unauthorized', 403);
            }

            $this->approvalService->resubmitExpense($expenseModel);

            return $this->successResponse(
                new ExpenseDetailResource($expenseModel->fresh()),
                'Expense resubmitted for approval successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Delete draft expense
     * DELETE /api/branch-manager/expenses/{expense}
     */
    public function destroy(string $expense): JsonResponse
    {
        $expenseModel = $this->expenseRepository->findOrFail($expense);

        if ($expenseModel->branch_manager_id !== auth()->id()) {
            return $this->errorResponse('Unauthorized', 403);
        }

        if ($expenseModel->status !== 'draft') {
            return $this->errorResponse('Only draft expenses can be deleted', 400);
        }

        try {
            $expenseModel->delete();

            return $this->successResponse(
                null,
                'Expense deleted successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Get Quick Cash expenses list
     * GET /api/branch-manager/expenses/quick-cash
     */
    public function quickCashList(Request $request): JsonResponse
    {
        $expenses = $this->expenseRepository->getQuickCashPaginated(
            auth()->id(),
            (int) $request->input('per_page', 20)
        );

        return $this->paginatedResponse(
            ExpenseResource::collection($expenses),
            'Quick Cash expenses retrieved successfully'
        );
    }

    /**
     * Get Single Invoice expenses list
     * GET /api/branch-manager/expenses/single-invoice
     */
    public function singleInvoiceList(Request $request): JsonResponse
    {
        $expenses = $this->expenseRepository->getSingleInvoicePaginated(
            auth()->id(),
            (int) $request->input('per_page', 20)
        );

        return $this->paginatedResponse(
            ExpenseResource::collection($expenses),
            'Single Invoice expenses retrieved successfully'
        );
    }

    /**
     * Get Pre-Approval expenses list
     * GET /api/branch-manager/expenses/pre-approval
     */
    public function preApprovalList(Request $request): JsonResponse
    {
        $expenses = $this->expenseRepository->getPreApprovalPaginated(
            auth()->id(),
            (int) $request->input('per_page', 20)
        );

        return $this->paginatedResponse(
            ExpenseResource::collection($expenses),
            'Pre-Approval expenses retrieved successfully'
        );
    }

    /**
     * Get Grouped Invoice expenses list
     * GET /api/branch-manager/expenses/grouped-invoice
     */
    public function groupedInvoiceList(Request $request): JsonResponse
    {
        $expenses = $this->expenseRepository->getGroupedInvoicePaginated(
            auth()->id(),
            (int) $request->input('per_page', 20)
        );

        return $this->paginatedResponse(
            ExpenseResource::collection($expenses),
            'Grouped Invoice expenses retrieved successfully'
        );
    }

    /**
     * Scan QR Code
     * POST /api/branch-manager/expenses/scan-qr
     */
    public function scanQRCode(Request $request): JsonResponse
    {
        $request->validate([
            'qr_code' => 'required|string',
        ]);

        try {
            $data = $this->helperService->parseQRCode($request->qr_code);

            return $this->successResponse(
                $data,
                'QR code parsed successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to parse QR code: '.$e->getMessage(),
                400
            );
        }
    }

    /**
     * Scan Invoice Code
     * POST /api/branch-manager/expenses/scan-invoice
     */
    public function scanInvoiceCode(Request $request): JsonResponse
    {
        $request->validate([
            'invoice_code' => 'required|string',
        ]);

        try {
            $data = $this->helperService->scanInvoiceCode($request->invoice_code);

            return $this->successResponse(
                $data,
                'Invoice code parsed successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to parse Invoice code: '.$e->getMessage(),
                400
            );
        }
    }

    /**
     * Search & filter expenses
     * GET /api/branch-manager/expenses/search
     */
    public function search(Request $request): JsonResponse
    {
        $expenses = $this->expenseRepository->getSearchPaginated(auth()->id(), $request);

        return $this->paginatedResponse(
            ExpenseResource::collection($expenses),
            'Filtered expenses retrieved successfully'
        );
    }
}
