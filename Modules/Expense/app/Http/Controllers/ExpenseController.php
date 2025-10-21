<?php

namespace Modules\Expense\Http\Controllers;

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
class ExpenseController extends Controller
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

        return response()->json([
            'success' => true,
            'data' => $summary
        ]);
    }

    /**
     * Get recent expenses (top 10)
     * GET /api/branch-manager/expenses/recent
     */
    public function recent(): JsonResponse
    {
        $expenses = Expense::where('branch_manager_id', auth()->id())
            ->with(['quickCashExpense', 'invoiceDetails', 'groupedInvoice', 'preApprovalRequest'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Recent expenses retrieved successfully',
            'data' => ExpenseResource::collection($expenses)
        ]);
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
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this expense'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Expense details retrieved successfully',
            'data' => new ExpenseDetailResource($expenseModel)
        ]);
    }

    /**
     * Get expense timeline
     * GET /api/branch-manager/expenses/{expense}/timeline
     */
    public function timeline(int $expense): JsonResponse
    {
        $expenseModel = Expense::findOrFail($expense);

        if ($expenseModel->branch_manager_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        $timeline = $expenseModel->timelines()
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Expense timeline retrieved successfully',
            'data' => ExpenseTimelineResource::collection($timeline)
        ]);
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
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Draft expenses retrieved successfully',
            'data' => ExpenseResource::collection($drafts)
        ]);
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
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $this->approvalService->submitExpense($expenseModel);

            return response()->json([
                'success' => true,
                'message' => 'Expense submitted for approval successfully',
                'data' => new ExpenseDetailResource($expenseModel->fresh())
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
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
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $this->approvalService->resubmitExpense($expenseModel);

            return response()->json([
                'success' => true,
                'message' => 'Expense resubmitted successfully',
                'data' => new ExpenseDetailResource($expenseModel->fresh())
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
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
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        if ($expenseModel->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft expenses can be deleted'
            ], 400);
        }

        try {
            $expenseModel->delete();

            return response()->json([
                'success' => true,
                'message' => 'Expense deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete expense',
                'error' => $e->getMessage()
            ], 500);
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
            ->get();

        return response()->json([
            'success' => true,
            'data' => ExpenseResource::collection($expenses)
        ]);
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
            ->get();

        return response()->json([
            'success' => true,
            'data' => ExpenseResource::collection($expenses)
        ]);
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
            ->get();

        return response()->json([
            'success' => true,
            'data' => ExpenseResource::collection($expenses)
        ]);
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
            ->get();

        return response()->json([
            'success' => true,
            'data' => ExpenseResource::collection($expenses)
        ]);
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

            return response()->json([
                'success' => true,
                'message' => 'QR code parsed successfully',
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to parse QR code',
                'error' => $e->getMessage()
            ], 400);
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

            return response()->json([
                'success' => true,
                'message' => 'Invoice code parsed successfully',
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to parse invoice code',
                'error' => $e->getMessage()
            ], 400);
        }
    }
}
