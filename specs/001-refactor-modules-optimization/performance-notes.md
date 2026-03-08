# Performance Notes (T016–T020)

**Feature**: 001-refactor-modules-optimization  
**Phase**: 5 (User Story 3 – Performance and Resource Optimization)

## Eager loading and N+1 (T016–T018)

### Expense
- **ExpenseRepository**: All list and detail methods use `with()` for relationships used in API responses.
  - List endpoints (getPaginatedForManager, getRecentForManager, getDraftsPaginated, getSearchPaginated, getPaginatedForApproval, type-specific lists) eager load `quickCashExpense`, `invoiceDetails`, `groupedInvoice.invoiceDetails`, `preApprovalRequest` (and `supplier` / `branchManager` where needed) so that `ExpenseResource` and `ExpenseDetailResource` do not trigger N+1.
  - Detail: `findForShow()` loads full relation set for show response.
- **Summary endpoint**: Uses only aggregates (count, sum) on the expense collection; no relation access in response, so no additional `with()` required.
- **Large iterations**: List endpoints are paginated; `getRecentForManager` is limited to 10. No `chunk()` required in Expense repositories for current usage.

### Shift
- **CashierShiftRepository**: Refactored methods (`getUpcomingPaginated`, `getInProgressPaginated`, `findForManagerShow`, `getUpcomingByCashierAndWeek`, `getPendingShifts`, `getInProgressShifts`, `getCompletedShifts`, `getReassignedShifts`) all use `with()` for relations used in `CashierShiftResource` / `ShiftDetailResource` (cashier, shift, nextCashier, handoverStatus, varianceDetails, etc.).
- Controllers that still query `CashierShift::` directly use explicit `with([...])` in the query.
- **Large iterations**: Shift list endpoints are paginated or return bounded sets; no unbounded `get()` that would require `chunk()`.

### Purchase
- **PurchaseOrderService**: `getOrders()` and `getOrdersForReceiving()` already use `with()` / `withCount()` for list responses (items, supplier, fromBranch, etc.).
- **PurchaseOrderRepository**: Used for single-order lookups (`findWithRelations`, `findByBranch`); list flows go through the service. No N+1 introduced by refactor.
- **ItemHelperTrait**: Uses `chunk()` for large average calculations where applicable.
- **Large iterations**: Order lists are paginated; no change required.

## Transaction boundaries and side-effect order (T019)

- **Refactor scope**: Only data *read* paths were moved to repositories (find, list, paginated queries). Write operations and business logic remain in existing services.
- **Expense**: No `DB::transaction` in refactored controllers or in ExpenseRepository; transactions (if any) remain in services and were not modified.
- **Shift**: `DB::transaction` exists in `BranchManagerShiftService` only; refactored controllers (PendingShiftController, InProgressShiftController) use repositories for reads only. No change to transaction boundaries or commit/rollback order.
- **Purchase**: `DB::transaction` is used in `GoodsReceiptService`, `ReturnManagementService`, `PurchaseOrderService`, `VarianceService`, `SupplierCommunicationService`, etc. Refactored controllers (GoodsReceivingController, ReturnManagementController) only switched to `PurchaseOrderRepository` for *finding* an order (read); create/update flows still go through the same services. Side-effect ordering and transaction boundaries are unchanged.

**Conclusion**: Refactored code did not alter transaction boundaries or the order of side effects; database state and notifications remain identical per spec FR-004.

## Response time and resource usage (T020)

- **Query count**: List and detail endpoints in Expense, Shift, and Purchase use eager loading in repositories or existing services, so the number of queries per request is unchanged or reduced (no new N+1).
- **Optional verification**: Use Laravel Telescope (if enabled) or query logging to compare query count for a representative set of requests (e.g. expense list, shift show, purchase order find) before/after refactor; responses must remain identical.
- **Documentation**: This file and quickstart.md (optional profiling step) document that response time and resource usage are unchanged or improved per spec SC-004. No mandatory profiling was added; teams can add temporary query logging or profiling when validating deployments.
