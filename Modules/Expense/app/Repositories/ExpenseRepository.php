<?php

namespace Modules\Expense\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Modules\Expense\Models\Expense;

/**
 * Repository for Expense data access.
 * Keeps query logic out of controllers; response shape unchanged.
 */
class ExpenseRepository
{
    /**
     * Find expense by ID or throw ModelNotFoundException.
     */
    public function findOrFail(string $id): Expense
    {
        return Expense::findOrFail($id);
    }

    /**
     * For attachment controller: expense with attachments and invoice details.
     */
    public function findWithAttachmentsAndInvoiceDetails(string $id): Expense
    {
        return Expense::with(['attachments', 'invoiceDetails'])->findOrFail($id);
    }

    /**
     * For attachment index: expense with attachments and invoice details (grouped).
     */
    public function findWithAttachmentsForIndex(string $id): Expense
    {
        return Expense::with(['attachments', 'invoiceDetails'])->findOrFail($id);
    }

    /**
     * Summary: approved expenses by branch_manager for month/year.
     *
     * @param  string|int  $managerId  Branch manager ID (UUID string or int)
     */
    public function getSummaryForManager(string|int $managerId, int $month, int $year): Collection
    {
        return Expense::where('branch_manager_id', $managerId)
            ->where('status', 'approved')
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->get();
    }

    /**
     * Recent expenses (non-pending), limit 10.
     *
     * @param  string|int  $managerId  Branch manager ID (UUID string or int)
     */
    public function getRecentForManager(string|int $managerId, int $limit = 10): Collection
    {
        return Expense::where('branch_manager_id', $managerId)
            ->with(['quickCashExpense', 'invoiceDetails', 'groupedInvoice.invoiceDetails', 'preApprovalRequest'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Expenses whose IMMUTABLE originating BRAND is in scope (brand owner /
     * brand manager scope).
     *
     * Every brand-actor read goes through here. FU-02 / R-01: scoping keys on
     * the expense's own origin_asab_brand_id captured at creation, matched
     * against the actor's CURRENTLY authorised brand ids — NOT on any branch's
     * present brand association (which an admin can repoint) and NOT on the
     * manager's current branch_id. So a historic expense stays with the brand
     * that owned it at creation even after its branch is re-tagged to another
     * brand or its manager moves. An empty scope matches nothing; a legacy row
     * with a NULL origin brand is not attributed to any brand (fail closed),
     * never guessed from a current association.
     *
     * @param  string[]  $brandIds  authorised brand ids, resolved server-side (ExpenseBrandScopeService), never from the request
     */
    private function inBrandScope(array $brandIds): Builder
    {
        $query = Expense::query();

        if ($brandIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('origin_asab_brand_id', $brandIds);
    }

    /**
     * One expense inside the brand scope, or null (out-of-scope and non-existent are indistinguishable).
     *
     * @param  string[]  $brandIds
     */
    public function findInBrandScope(string $id, array $brandIds): ?Expense
    {
        return $this->inBrandScope($brandIds)->whereKey($id)->first();
    }

    /**
     * Brand actor view of a single expense with every relation the detail resource needs.
     *
     * @param  string[]  $brandIds
     */
    public function findForShowInBrandScope(string $id, array $brandIds): ?Expense
    {
        return $this->inBrandScope($brandIds)->with([
            'quickCashExpense.items.category',
            'invoiceDetails.supplier',
            'invoiceDetails.items.category',
            'invoiceDetails.expenseLines.category',
            'groupedInvoice.invoiceDetails.supplier',
            'groupedInvoice.invoiceDetails.items.category',
            'groupedInvoice.invoiceDetails.expenseLines.category',
            'preApprovalRequest',
            'items.category',
            'expenseLines.category',
            'attachments',
            'supplier',
            'branchManager',
            'timelines' => fn ($q) => $q->orderBy('created_at', 'asc'),
        ])->whereKey($id)->first();
    }

    /**
     * Recent expenses (non-draft) for a brand actor, limit 10.
     *
     * @param  string[]  $brandIds
     */
    public function getRecentInBrandScope(array $brandIds, int $limit = 10): Collection
    {
        return $this->inBrandScope($brandIds)
            ->whereIn('status', ['pending', 'approved', 'rejected'])
            ->with(['quickCashExpense', 'invoiceDetails', 'groupedInvoice.invoiceDetails', 'preApprovalRequest', 'branchManager'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Brand actor summary: approved expenses inside the scope for month/year.
     *
     * @param  string[]  $brandIds
     */
    public function getSummaryInBrandScope(array $brandIds, int $month, int $year): Collection
    {
        return $this->inBrandScope($brandIds)
            ->where('status', 'approved')
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->get();
    }

    /**
     * Paginated index with optional type, status, date filters.
     *
     * @param  string|int  $managerId  Branch manager ID (UUID string or int)
     */
    public function getPaginatedForManager(string|int $managerId, Request $request): LengthAwarePaginator
    {
        $query = Expense::where('branch_manager_id', $managerId)
            ->with(['quickCashExpense', 'invoiceDetails', 'groupedInvoice.invoiceDetails', 'preApprovalRequest']);

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

        return $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));
    }

    /**
     * Single expense with full relations for show.
     * Eager loads nested supplier and category to avoid N+1 in ExpenseDetailResource (grouped/single invoice).
     */
    public function findForShow(string $id): Expense
    {
        return Expense::with([
            'quickCashExpense.items.category',
            'invoiceDetails.supplier',
            'invoiceDetails.items.category',
            'invoiceDetails.expenseLines.category',
            'groupedInvoice.invoiceDetails.supplier',
            'groupedInvoice.invoiceDetails.items.category',
            'groupedInvoice.invoiceDetails.expenseLines.category',
            'preApprovalRequest',
            'items.category',
            'expenseLines.category',
            'attachments',
            'supplier',
            'branchManager',
            'timelines' => fn ($q) => $q->orderBy('created_at', 'asc'),
        ])->findOrFail($id);
    }

    /**
     * Drafts paginated.
     *
     * @param  string|int  $managerId  Branch manager ID (UUID string or int)
     */
    public function getDraftsPaginated(string|int $managerId, int $perPage = 20): LengthAwarePaginator
    {
        return Expense::where('branch_manager_id', $managerId)
            ->where('status', 'draft')
            ->with(['quickCashExpense', 'invoiceDetails', 'groupedInvoice.invoiceDetails', 'preApprovalRequest'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Quick cash list paginated.
     *
     * @param  string|int  $managerId  Branch manager ID (UUID string or int)
     */
    public function getQuickCashPaginated(string|int $managerId, int $perPage = 20): LengthAwarePaginator
    {
        return Expense::where('branch_manager_id', $managerId)
            ->where('expense_type', 'quick_cash')
            ->with('quickCashExpense')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Single invoice list paginated.
     *
     * @param  string|int  $managerId  Branch manager ID (UUID string or int)
     */
    public function getSingleInvoicePaginated(string|int $managerId, int $perPage = 20): LengthAwarePaginator
    {
        return Expense::where('branch_manager_id', $managerId)
            ->where('expense_type', 'single_invoice')
            ->with('invoiceDetails')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Pre-approval list paginated.
     *
     * @param  string|int  $managerId  Branch manager ID (UUID string or int)
     */
    public function getPreApprovalPaginated(string|int $managerId, int $perPage = 20): LengthAwarePaginator
    {
        return Expense::where('branch_manager_id', $managerId)
            ->where('expense_type', 'pre_approval')
            ->with('preApprovalRequest')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Grouped invoice list paginated.
     *
     * @param  string|int  $managerId  Branch manager ID (UUID string or int)
     */
    public function getGroupedInvoicePaginated(string|int $managerId, int $perPage = 20): LengthAwarePaginator
    {
        return Expense::where('branch_manager_id', $managerId)
            ->where('expense_type', 'grouped_invoice')
            ->with('groupedInvoice.invoiceDetails')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Search/filter paginated (same logic as controller search).
     *
     * @param  string|int  $managerId  Branch manager ID (UUID string or int)
     */
    public function getSearchPaginated(string|int $managerId, Request $request): LengthAwarePaginator
    {
        $query = Expense::where('branch_manager_id', $managerId)
            ->with([
                'quickCashExpense',
                'invoiceDetails',
                'groupedInvoice.invoiceDetails',
                'preApprovalRequest',
                'supplier',
            ]);
        $this->applySearchFilters($query, $request);

        return $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));
    }

    /**
     * Apply search/filter query constraints (type, status, period, amount).
     */
    private function applySearchFilters(Builder $query, Request $request): void
    {
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('reference_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$search}%"))
                    ->orWhere('total_amount', 'like', "%{$search}%");
            });
        }
        if (($type = $request->input('type')) && $type !== 'all') {
            if ($type === 'draft') {
                $query->where('status', 'draft');
            } else {
                $query->where('expense_type', $type);
            }
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($period = $request->input('period')) {
            $periodMap = [
                'last_30_days' => now()->subDays(30),
                'last_7_days' => now()->subDays(7),
                'last_24_hours' => now()->subDay(),
            ];
            if (isset($periodMap[$period])) {
                $query->where('created_at', '>=', $periodMap[$period]);
            }
        }
        if ($min = $request->input('min_amount')) {
            $query->where('total_amount', '>=', $min);
        }
        if ($max = $request->input('max_amount')) {
            $query->where('total_amount', '<=', $max);
        }
    }

    /**
     * Brand actor approval list: pending/approved/rejected inside the scope, with relations.
     * A client-supplied `branch_manager_id` can only narrow the scope, never widen it.
     *
     * @param  string[]  $brandIds
     */
    public function getPaginatedForApprovalInBrandScope(Request $request, array $brandIds): LengthAwarePaginator
    {
        $query = $this->inBrandScope($brandIds)
            ->whereIn('status', ['pending', 'approved', 'rejected'])
            ->with(['quickCashExpense', 'invoiceDetails', 'groupedInvoice.invoiceDetails', 'preApprovalRequest', 'branchManager']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('branch_manager_id')) {
            $query->where('branch_manager_id', $request->branch_manager_id);
        }

        return $query->orderBy('submitted_at', 'desc')
            ->paginate($request->input('per_page', 20));
    }
}
