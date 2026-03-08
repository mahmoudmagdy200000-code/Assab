# Data Model: Recent Expenses (No Schema Change)

This feature does not introduce new entities or schema changes. It changes which rows are returned by the existing Expense model.

## Existing Entity: Expense

- **Purpose**: A single expense record owned by a branch manager.
- **Key attributes**: id (UUID), branch_manager_id, expense_type, status, total_amount, date/time (or created_at), net_amount, vat_amount, payment_method, supplier_id, submitted_at, approved_at, rejected_at, etc.
- **Relationships**: branchManager, quickCashExpense, invoiceDetails, groupedInvoice, preApprovalRequest (eager-loaded for list/recent responses).
- **Relevant for recent**: Filter by `branch_manager_id` = authenticated user; order by `created_at` desc; limit 10; **no filter on status** (all statuses included).

## Query Change

- **Before**: `Expense::where('branch_manager_id', auth()->id())->where('status', '!=', 'pending')->orderBy('created_at', 'desc')->limit(10)->get()`.
- **After**: `Expense::where('branch_manager_id', auth()->id())->orderBy('created_at', 'desc')->limit(10)->get()` (with same eager loads: quickCashExpense, invoiceDetails, groupedInvoice, preApprovalRequest).

No new tables, migrations, or entity definitions required.
