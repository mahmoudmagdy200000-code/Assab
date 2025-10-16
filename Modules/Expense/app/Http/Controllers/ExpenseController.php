<?php

namespace Modules\Expense\Http\Controllers;



use Modules\Expense\Models\Expense;
use Modules\Expense\Models\Category;
use Modules\Expense\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Http\Controllers\BaseController;

class ExpenseController extends BaseController
{
    /**
     * Display expenses list with summary for current month
     */
    public function index(Request $request)
    {
        $branchId = auth()->user()->branch_manager->branch_id;
        $year = $request->input('year', now()->year);
        $month = $request->input('month', now()->month);

        // Get summary statistics
        $summary = $this->getExpensesSummary($branchId, $year, $month);

        // Get recent expenses (top 10)
        $recentExpenses = Expense::forBranch($branchId)
            ->with(['supplier', 'branchManager'])
            ->recentExpenses(10)
            ->get()
            ->map(function ($expense) {
                return [
                    'id' => $expense->id,
                    'expense_name' => $expense->expense_name,
                    'expense_type' => $expense->expense_type,
                    'cost' => $expense->total_amount,
                    'date' => $expense->expense_date->format('Y-m-d'),
                    'time' => $expense->created_at->format('H:i:s'),
                    'status' => $expense->status
                ];
            });

        return response()->json([
            'summary' => $summary,
            'recent_expenses' => $recentExpenses,
            'current_year' => $year,
            'current_month' => $month
        ]);
    }

    /**
     * Get expenses summary statistics
     */
    private function getExpensesSummary($branchId, $year, $month)
    {
        $expenses = Expense::forBranch($branchId)
            ->byMonth($year, $month)
            ->get();

        return [
            'total_expenses' => $expenses->count(),
            'total_amount' => $expenses->sum('total_amount'),
            'quick_cash_amount' => $expenses->where('expense_type', 'quick_cash')->sum('total_amount'),
            'single_invoice_amount' => $expenses->where('expense_type', 'single_invoice')->sum('total_amount'),
            'grouped_invoices_amount' => $expenses->where('expense_type', 'grouped_invoices')->sum('total_amount'),
            'pre_approval_amount' => $expenses->where('expense_type', 'pre_approval')->sum('total_amount')
        ];
    }

    /**
     * Display expenses history
     */
    public function history(Request $request)
    {
        $branchId = auth()->user()->branch_manager->branch_id;
        $year = $request->input('year', now()->year);
        $month = $request->input('month', now()->month);

        $expenses = Expense::forBranch($branchId)
            ->byMonth($year, $month)
            ->with(['supplier', 'branchManager'])
            ->orderBy('expense_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($expenses);
    }

    /**
     * Get draft expenses
     */
    public function drafts(Request $request)
    {
        $branchId = auth()->user()->branch_manager->branch_id;
        $year = $request->input('year', now()->year);
        $month = $request->input('month', now()->month);

        $drafts = Expense::forBranch($branchId)
            ->byMonth($year, $month)
            ->byStatus('draft')
            ->with(['supplier', 'quickCashExpense', 'preApprovalRequest'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($drafts);
    }

    /**
     * Filter expenses
     */
    public function filter(Request $request)
    {
        $branchId = auth()->user()->branch_manager->branch_id;

        $query = Expense::forBranch($branchId)->with(['supplier', 'branchManager']);

        // Filter by type
        if ($request->has('type') && $request->type !== 'all') {
            if ($request->type === 'draft') {
                $query->byStatus('draft');
            } else {
                $query->byType($request->type);
            }
        }

        // Filter by status
        if ($request->has('status')) {
            $query->byStatus($request->status);
        }

        // Filter by date
        if ($request->has('date_filter')) {
            switch ($request->date_filter) {
                case 'last_24_hours':
                    $query->where('created_at', '>=', now()->subDay());
                    break;
                case 'last_7_days':
                    $query->where('created_at', '>=', now()->subDays(7));
                    break;
                case 'last_30_days':
                    $query->where('created_at', '>=', now()->subDays(30));
                    break;
                case 'custom':
                    if ($request->has(['start_date', 'end_date'])) {
                        $query->whereBetween('expense_date', [
                            $request->start_date,
                            $request->end_date
                        ]);
                    }
                    break;
            }
        }

        $expenses = $query->orderBy('expense_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($expenses);
    }

    /**
     * Show expense details
     */
    public function show($id)
    {
        $expense = Expense::with([
            'supplier',
            'branchManager',
            'rejectedBy',
            'approvedBy',
            'viewedBy',
            'quickCashExpense',
            'preApprovalRequest',
            'groupedInvoice',
            'invoiceDetails.supplier',
            'invoiceDetails.items.category',
            'invoiceDetails.items.subcategory',
            'invoiceDetails.expenseLines.category',
            'invoiceDetails.expenseLines.subcategory',
            'items.category',
            'items.subcategory',
            'expenseLines.category',
            'expenseLines.subcategory',
            'quickCashItems',
            'attachments',
            'timelines.user'
        ])->findOrFail($id);

        // Mark as viewed if not already viewed
        if (auth()->user()->branch_manager->hasRole('brand_owner')) {
            $expense->markAsViewed(auth()->id());
        }

        return response()->json($expense);
    }

    /**
     * Delete draft expense
     */
    public function destroy($id)
    {
        $expense = Expense::findOrFail($id);

        // Only allow deletion of drafts
        if ($expense->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft expenses can be deleted'
            ], 403);
        }

        // Check ownership
        if ($expense->branch_manager_id !== auth()->user()->branch_manager->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $expense->delete();

        return response()->json([
            'message' => 'Expense deleted successfully'
        ]);
    }

    /**
     * Submit expense for approval
     */
    public function submit($id)
    {
        $expense = Expense::findOrFail($id);

        // Check ownership
        if ($expense->branch_manager_id !== auth()->user()->branch_manager->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        // Validate expense is in draft status
        if ($expense->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft expenses can be submitted'
            ], 400);
        }

        $expense->submit();

        return response()->json([
            'message' => 'Expense submitted successfully',
            'expense' => $expense
        ]);
    }

    /**
     * Approve expense (Brand Owner only)
     */
    public function approve($id)
    {
        $expense = Expense::findOrFail($id);

        // Check if user is brand owner
        if (!auth()->user()->hasRole('brand_owner')) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        // Validate expense is pending
        if ($expense->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending expenses can be approved'
            ], 400);
        }

        $expense->approve(auth()->user()->branch_manager->id);

        return response()->json([
            'message' => 'Expense approved successfully',
            'expense' => $expense
        ]);
    }

    /**
     * Reject expense (Brand Owner only)
     */
    public function reject(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|max:1000'
        ]);

        $expense = Expense::findOrFail($id);

        // Check if user is brand owner
        if (!auth()->user()->branch_manager->hasRole('brand_owner')) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        // Validate expense is pending
        if ($expense->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending expenses can be rejected'
            ], 400);
        }

        $expense->reject(auth()->user()->branch_manager->id, $request->reason);

        return response()->json([
            'message' => 'Expense rejected successfully',
            'expense' => $expense
        ]);
    }

    /**
     * Resubmit rejected expense
     */
    public function resubmit($id)
    {
        $expense = Expense::findOrFail($id);

        // Check ownership
        if ($expense->branch_manager_id !== auth()->user()->branch_manager->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        // Validate expense is rejected
        if ($expense->status !== 'rejected') {
            return response()->json([
                'message' => 'Only rejected expenses can be resubmitted'
            ], 400);
        }

        $expense->resubmit();

        return response()->json([
            'message' => 'Expense resubmitted successfully',
            'expense' => $expense
        ]);
    }

    /**
     * Get categories for items/expenses
     */
    public function getCategories(Request $request)
    {
        $type = $request->input('type', 'item'); // item or expense

        $categories = Category::with('children')
            ->mainCategories()
            ->active()
            ->byType($type)
            ->orderBy('name')
            ->get();

        return response()->json($categories);
    }

    /**
     * Get suppliers list
     */
    public function getSuppliers(Request $request)
    {
        $search = $request->input('search');

        $query = Supplier::active();

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('tax_id', 'like', "%{$search}%");
            });
        }

        $suppliers = $query->orderBy('name')->get();

        return response()->json($suppliers);
    }

    /**
     * Get custody balance
     */
    public function getCustodyBalance()
    {
        $branchId = auth()->user()->branch_manager->branch_id;

        // This should be implemented based on your custody management logic
        // For now, returning a placeholder
        $balance = 5000.00; // Fetch from custody table

        return response()->json([
            'balance' => $balance
        ]);
    }

    /**
     * Parse QR code / invoice code
     */
    public function parseInvoiceCode(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        // This should implement actual QR/invoice parsing logic
        // based on Saudi Arabia's ZATCA e-invoicing standards

        // Placeholder response
        return response()->json([
            'invoice_number' => 'INV-2024-001',
            'tax_id' => '310122393500003',
            'supplier_name' => 'Sample Supplier',
            'net_amount' => 1000.00,
            'vat_amount' => 150.00,
            'total_amount' => 1150.00,
            'issue_date' => now()->format('Y-m-d')
        ]);
    }
}

