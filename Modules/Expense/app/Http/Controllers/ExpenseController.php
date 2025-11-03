<?php

namespace Modules\Expense\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Expense\Models\Expense;
use Modules\Expense\Services\{ExpenseApprovalService, ExpenseHelperService};
use Modules\Expense\Transformers\{ExpenseResource, ExpenseDetailResource, ExpenseTimelineResource};

/**
 * Main Expense Controller
 * General expense operations
 */
class ExpenseController extends BaseController
{
    public function __construct(
        private ExpenseApprovalService $approvalService,
        private ExpenseHelperService $helperService
    ) {}

    /**
     * Get expense summary (for dashboard)
     * GET /api/branch-manager/expenses/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $month = $request->input('month', now()->month);
        $year = $request->input('year', now()->year);

        $expenses = Expense::where('branch_manager_id', auth()->id())
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->get();

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
     * Get recent expenses (top 10)
     * GET /api/branch-manager/expenses/recent
     */
    public function recent(): JsonResponse
    {
        $expenses = Expense::where('branch_manager_id', auth()->id())
            ->with(['quickCashExpense', 'invoiceDetails', 'groupedInvoice', 'preApprovalRequest'])
            ->where('status', '!=', 'pending')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

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
        $query = Expense::where('branch_manager_id', auth()->id())
            ->with(['quickCashExpense', 'invoiceDetails', 'groupedInvoice', 'preApprovalRequest']);

        // Apply filters
        if ($request->has('type') && $request->type !== 'all') {
            $query->where('expense_type', $request->type);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $expenses = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return $this->paginatedResponse(
            ExpenseResource::collection($expenses),
            'Expenses retrieved successfully'
        );
    }

    /**
     * Get expense details
     * GET /api/branch-manager/expenses/{expense}
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
            'supplier'
        ])->findOrFail($expense);

        // Check authorization
        if ($expenseModel->branch_manager_id !== auth()->id()) {
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
    public function timeline(int $expense): JsonResponse
    {
        $expenseModel = Expense::findOrFail($expense);

        if ($expenseModel->branch_manager_id !== auth()->id()) {
            return $this->errorResponse('Unauthorized access', 403);
        }

        $timeline = $expenseModel->timelines()
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->successResponse(
            ExpenseTimelineResource::collection($timeline),
            'Expense timeline retrieved successfully'
        );
    }

    /**
     * Get draft expenses
     * GET /api/branch-manager/expenses/drafts
     */
    public function drafts(Request $request): JsonResponse
    {
        $drafts = Expense::where('branch_manager_id', auth()->id())
            ->where('status', 'draft')
            ->with(['quickCashExpense', 'invoiceDetails', 'groupedInvoice', 'preApprovalRequest'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return $this->paginatedResponse(
            ExpenseResource::collection($drafts),
            'Draft expenses retrieved successfully'
        );
    }

    /**
     * Submit expense for approval
     * POST /api/branch-manager/expenses/{expense}/submit
     */
    public function submit(int $expense): JsonResponse
    {
        try {
            $expenseModel = Expense::findOrFail($expense);

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
    public function resubmit(int $expense): JsonResponse
    {
        try {
            $expenseModel = Expense::findOrFail($expense);

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
    public function destroy(int $expense): JsonResponse
    {
        $expenseModel = Expense::findOrFail($expense);

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
        $expenses = Expense::where('branch_manager_id', auth()->id())
            ->where('expense_type', 'quick_cash')
            ->with('quickCashExpense')
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

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
        $expenses = Expense::where('branch_manager_id', auth()->id())
            ->where('expense_type', 'single_invoice')
            ->with('invoiceDetails')
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

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
        $expenses = Expense::where('branch_manager_id', auth()->id())
            ->where('expense_type', 'pre_approval')
            ->with('preApprovalRequest')
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

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
        $expenses = Expense::where('branch_manager_id', auth()->id())
            ->where('expense_type', 'grouped_invoice')
            ->with('groupedInvoice.invoiceDetails')
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

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
                'Failed to parse QR code: ' . $e->getMessage(),
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
                'Failed to parse Invoice code: ' . $e->getMessage(),
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
        $query = Expense::where('branch_manager_id', auth()->id())
            ->with([
                'quickCashExpense',
                'invoiceDetails',
                'groupedInvoice',
                'preApprovalRequest',
                'supplier'
            ]);
      $total = $query->count();


        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('reference_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn($s) => $s->where('name', 'like', "%{$search}%"))
                    ->orWhere('total_amount', 'like', "%{$search}%");
            });
        }



        if ($type = $request->input('type')) {
            if ($type !== 'all') {
                if ($type === 'draft') {
                    $query->where('status', 'draft');
                } else {
                    $query->where('expense_type', $type);
                }
            }
        }



        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // 🕒
        if ($period = $request->input('period')) {
            switch ($period) {
                case 'last_30_days':
                    $query->where('created_at', '>=', now()->subDays(30));
                    break;

                case 'last_7_days':
                    $query->where('created_at', '>=', now()->subDays(7));
                    break;

                case 'last_24_hours':
                    $query->where('created_at', '>=', now()->subDay());
                    break;
            }
        }

        // 💰ف
        if ($min = $request->input('min_amount')) {
            $query->where('total_amount', '>=', $min);
        }

        if ($max = $request->input('max_amount')) {
            $query->where('total_amount', '<=', $max);
        }

        // 🔢 Pagination
        $expenses = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return $this->paginatedResponse(
            ExpenseResource::collection($expenses),
            'Filtered expenses retrieved successfully'
        );
    }
}
