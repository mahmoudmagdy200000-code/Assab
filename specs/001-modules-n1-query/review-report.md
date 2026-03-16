# Modules Performance & Concurrency Review Report

## 1. Metadata

| Field | Value |
|-------|--------|
| **Feature** | `001-modules-n1-query` |
| **Date range of review** | 2026-03-16 |
| **Reviewer(s)** | Technical Audit (Senior Laravel Architect / Performance Expert) |
| **Constraint** | No production code, API responses, or schema changed; report only. |

---

## 2. Scope

### In-scope modules (ModuleReviewTarget)

| Module | Primary entrypoints | Key tables / relationships |
|--------|---------------------|----------------------------|
| **Shift** | ShiftController, CashierShiftController, BranchManagerShiftController, ShiftHandoverController, ShiftVarianceController, ShiftEndController, CashierManagementController; HandoverService, ShiftService, BranchManagerShiftService; Console: AutoEndOverdueShiftsCommand, GenerateDailyReportCommand, SendShiftRemindersCommand; Job: SendShiftStartReminderJob | shifts, cashier_shifts, cashier_shift_handovers, branch_manager_shifts, shift_variance_*, shift_sales_breakdown |
| **Purchase** | PendingOrderController, GoodsReceivingController, NewOrderController, SupplierController, ReturnManagementController; PurchaseOrderService, GoodsReceiptService, OrderTrackingService, OrderDataService, ReturnManagementService; Console: CleanBulkPurchaseOrdersCommand | purchase_orders, purchase_order_items, goods_receipts, order_timelines, return_orders, branch_items, supplier_items |
| **RecurringOrder** | RecurringOrderController; RecurringOrderService; ProcessRecurringOrdersJob; ProcessRecurringOrdersCommand | recurring_orders, recurring_order_items |
| **Expense** | ExpenseController, SingleInvoiceExpenseController, GroupedInvoiceExpenseController, QuickCashExpenseController, PreApprovalRequestController, ExpenseApprovalController; ExpenseRepository, SingleInvoiceExpenseService, GroupedInvoiceExpenseService; Events/Listeners | expenses, expense_items, expense_lines, expense_timelines, grouped_invoices, invoice_details, quick_cash_*, pre_approval_requests |
| **Inventory** | InventoryController, MonthlyInventoryController, DailyQuickInventoryController, WasteDamageReportController; InventorySessionService, MonthlyInventoryService, WasteDamageReportService; MonthlyInventoryRepository, DailyInventoryScheduleRepository | inventory_sessions, inventory_items, monthly_inventories, monthly_inventory_products, waste_damage_reports |
| **Custody** | CustodyController, CustodyTransactionController, CustodyRequestController, CustodyBalanceController, CustodyHandoverController, LedgerController; CustodyBalanceService, CustodyTransactionService, CustodyRequestService, PersonalLedgerService, PdfExportService; Listeners (Expense approval, Handover, Variance) | custody_transactions, custody_requests, custody_request_timeline, personal_ledger_transactions, cashier_custody_transactions |
| **Supplier** | OrderController, OrderFulfillmentController, PendingOrderController, ReturnManagementController, DeliveryProofController; OrderService, OrderFulfillmentService, ReturnManagementService, DeliveryProofService, InventoryService, AnalyticsService | supplier_*, purchase_orders (read/write) |

### Out-of-scope

- Modules not listed above (e.g. Cashier, Notification, Aggregator, Branch, BranchManagers) except where they are called by in-scope modules.
- Frontend (Flutter); API response structures (DTOs, Resources, JSON) are not modified per constraint.
- Database schema and migrations (no new indexes or tables recommended in this report; document as future recommendations only).

---

## 3. Executive Summary

### Top critical findings

1. **Purchase: Unbounded `get()` then in-memory pagination (PurchaseOrderService)**  
   `getOrdersForReceiving()` loads all orders matching filters with `$query->get()` then filters and manually slices for pagination. Under load this can cause high memory and slow response times. **Severity: Critical.**

2. **Shift: N+1 in BranchManagerShiftResource (handover/financial summary)**  
   When returning a collection of branch manager shifts, each resource runs two separate `CashierShiftHandover::where(...)->get()` queries inside `getHandoverSummary()` and `getFinancialSummary()`. For 20 shifts this yields 40 extra queries. **Severity: Critical.**

3. **Expense: N+1 in ExpenseDetailResource for grouped_invoice**  
   When showing a grouped-invoice expense, the resource accesses `$inv->supplier` and `$item->category` / `$line->category` inside loops. `findForShow()` does not eager load `groupedInvoice.invoiceDetails.supplier` or `*.items.category` / `*.expenseLines.category`, causing N+1 on expense detail API. **Severity: High.**

4. **Custody: Unbounded balance calculation (CustodyBalanceService)**  
   `getCustodyBalance()` and `getCurrentBalance()` load all transactions for a branch manager with `->get()` and sum in PHP. For managers with large history this is unbounded and could be replaced with a single aggregated query. **Severity: High.**

5. **RecurringOrder: ProcessRecurringOrdersJob not idempotent**  
   The job creates a purchase order and updates the recurring order without a idempotency key or guard. A retry (e.g. after timeout) can create duplicate orders. **Severity: High.**

### Overall risk level

**Medium–High.** Critical and high findings are concentrated in a few modules and flows. Addressing the Purchase in-memory pagination, Shift N+1 in resources, and Custody balance queries would materially reduce performance and stability risk without changing API contracts.

---

## 4. Findings by Module

### 4.1 Shift

**Summary of main concerns:** N+1 in BranchManagerShiftResource (two queries per shift in list); unbounded `get()` in HandoverService and ShiftService; optional pagination in ShiftRequestsService falls back to unbounded get; transaction usage in BranchManagerShiftService is scoped and reasonable. Shift module uses cache in BranchManagerShiftService and one controller for handover/close data (300s TTL).

| ID | Type | Severity | Location | Description | Evidence | Recommended actions (future) |
|----|------|----------|----------|-------------|----------|-----------------------------|
| SHIFT-001 | N+1 | Critical | `Modules/Shift/app/Transformers/BranchManagerShiftResource.php` – `getHandoverSummary()`, `getFinancialSummary()` | Each BranchManagerShift resource runs two independent queries to `CashierShiftHandover` (with whereHas on cashierShift/shift). When the API returns a collection of branch manager shifts (e.g. list by date range), this results in 2N extra queries. | Two separate `CashierShiftHandover::where(...)->with([...])->get()` calls per resource (lines ~106–114 and ~249–258). | Eager load handover summary and financial aggregates at query time (e.g. in BranchManagerShiftService/Repository) and pass as attributes or a single relation; or compute via subqueries/DB aggregate and attach to each shift so the resource does not query. |
| SHIFT-002 | Unbounded get | High | `Modules/Shift/app/Services/HandoverService.php` – ~line 931 | Handover summary statistics load all matching cashier shifts with `$query->get()` (no limit/pagination). Filtering by date/branch can still return large sets. | `$shifts = $query->get();` then in-memory stats and `$shifts->sortByDesc('handed_over_at')->take(10)`. | Use pagination for the main list or a dedicated stats query (counts/sums) and limit “recent handovers” via query (e.g. `orderByDesc()->limit(10)->get()`). |
| SHIFT-003 | Unbounded get | Medium | `Modules/Shift/app/Services/ShiftService.php` – ~159, ~229 | `getShiftsByDateRange()` and similar methods return `$query->get()` with no limit. | Two methods return `return $query->get();`. | Add optional pagination or a hard limit for reporting endpoints. |
| SHIFT-004 | Optional pagination | Medium | `Modules/Shift/app/Services/ShiftRequestsService.php` – ~73, 123, 156 | When `$perPage` is null, the service returns `$query->get()` (unbounded). | `return $perPage ? $query->paginate($perPage) : $query->get();`. | Require pagination for list endpoints or enforce a max limit when `perPage` is null. |
| SHIFT-005 | Transaction scope | Low | `Modules/Shift/app/Services/BranchManagerShiftService.php` – ~115, ~358 | `DB::transaction` used for handover and cashier breakdown updates. No explicit lock; scope is reasonable. | Two transaction blocks. | Document; consider shortening transaction hold time if external calls exist inside. |

---

### 4.2 Purchase

**Summary of main concerns:** Critical in-memory pagination in getOrdersForReceiving; heavy `get()` usage in OrderDataService; redundant item load in OrderTrackingService; high transaction density (PurchaseOrderService, GoodsReceiptService, ReturnManagementService, VarianceService, SupplierCommunicationService, CleanBulkPurchaseOrdersCommand) with no explicit row locking. PriceComparisonService uses cache for distance data.

| ID | Type | Severity | Location | Description | Evidence | Recommended actions (future) |
|----|------|----------|----------|-------------|----------|-----------------------------|
| PUR-001 | Unbounded get + in-memory pagination | Critical | `Modules/Purchase/app/Services/PurchaseOrderService.php` – ~377 | Orders are loaded with `$query->get()` then filtered by `items_count > 0` and manually sliced for pagination. Entire result set is loaded into memory. | `$orders = $query->get()->filter(...);` then `$items = $transformedOrders->slice(($currentPage - 1) * $perPage, $perPage)`. | Implement server-side pagination: use `paginate($perPage)` (or cursor) and move the “has items” filter into the query (e.g. whereHas('items')) so the database returns only the current page. |
| PUR-002 | Heavy get() usage | High | `Modules/Purchase/app/Services/OrderDataService.php` – ~202, 247, 253, 333, 357, 525, 629, 636, 807 | Multiple places use `->get()` on inventories, supplier products, or branch items without pagination or limit. | e.g. `$inventories = $inventoryQuery->get();`, `$supplierProducts = $supplierProducts->get();`, `$supplierItems = $query->get();`. | Add pagination or a safe limit for list/transfer flows; consider chunking for bulk exports. |
| PUR-003 | N+1 / redundant load | Medium | `Modules/Purchase/app/Services/OrderTrackingService.php` – ~551 | After loading a receipt, items are re-loaded with a separate query and set on the model: `$latestReceipt->setRelation('items', GoodsReceiptItem::where(...)->get())`. | Single receipt; not N+1 per row but redundant if receipt was loaded with `with('items')`. | Eager load `items` when fetching the receipt so this setRelation is unnecessary. |
| PUR-004 | Transaction density | Low | `Modules/Purchase/app/Services/PurchaseOrderService.php`, `GoodsReceiptService.php` | Many `DB::transaction` blocks (create order, confirm items, receive goods, etc.). No `lockForUpdate` found; deadlock risk is moderate. | 10+ transaction usages in PurchaseOrderService; 4 in GoodsReceiptService. | Keep transactions short; avoid doing heavy loops or external HTTP inside; document order of operations to avoid cross-module deadlocks. |

---

### 4.3 RecurringOrder

**Summary of main concerns:** ProcessRecurringOrdersJob is not idempotent (retry can create duplicate POs); job loads up to 50 due orders in one run; RecurringOrderService uses `get()` in bounded contexts. No caching on main paths.

| ID | Type | Severity | Location | Description | Evidence | Recommended actions (future) |
|----|------|----------|----------|-------------|----------|-----------------------------|
| REC-001 | Idempotency | High | `Modules/RecurringOrder/app/Jobs/ProcessRecurringOrdersJob.php` | Job creates a purchase order and updates the recurring order. On retry (e.g. after timeout or failure after createOrder), the same recurring order can be processed again and create a duplicate PO. | `$purchaseOrderService->createOrder($data);` then `$recurring->update([...]);`; no idempotency key or “already processed” check. | Make processing idempotent: e.g. set a “processing” state with a unique key, or store `last_generated_order_id` and skip if already generated for this `next_run_at` window; or use a idempotency key passed to createOrder. |
| REC-002 | Unbounded get (capped) | Medium | `Modules/RecurringOrder/app/Jobs/ProcessRecurringOrdersJob.php` – ~48 | Due orders are loaded with `->limit(50)->get()`. Capped but no chunking; 50 orders processed in one job run. | `->orderBy('next_run_at')->limit(50)->get();`. | Acceptable for now; document. For scale, consider processing in smaller chunks or dispatching one job per recurring order. |
| REC-003 | get() in service | Low | `Modules/RecurringOrder/app/Services/RecurringOrderService.php` – ~206, 477, 481 | Some paths use `->get()` for items or branch items (e.g. for validation or pricing). | Used in context of a single recurring order or bounded set. | Ensure callers never pass unbounded filters; add a limit where applicable. |

---

### 4.4 Expense

**Summary of main concerns:** N+1 in ExpenseDetailResource for grouped_invoice (supplier/category not eager loaded); lazy supplier/payment-supplier access in resource; repository get() for summary/recent is bounded by filters. No caching on expense data paths.

| ID | Type | Severity | Location | Description | Evidence | Recommended actions (future) |
|----|------|----------|----------|-------------|----------|-----------------------------|
| EXP-001 | N+1 (grouped invoice detail) | High | `Modules/Expense/app/Transformers/ExpenseDetailResource.php` – `getGroupedInvoiceDetails()`, `getSingleInvoiceDetails()`, etc. | For grouped_invoice, the resource maps over `$grouped->invoiceDetails` and accesses `$inv->supplier->name`, and over `$invoice->items` / `$invoice->expenseLines` with `$item->category?->name`. `findForShow()` does not load `groupedInvoice.invoiceDetails.supplier` or `*.items.category` / `*.expenseLines.category`. | Lines 151, 163–164, 176–177, 181–182, 191–199; repository `findForShow()` has `groupedInvoice.invoiceDetails.items` and `expenseLines` but not supplier or category on nested relations. | Eager load in `ExpenseRepository::findForShow()`: e.g. `groupedInvoice.invoiceDetails.supplier`, `groupedInvoice.invoiceDetails.items.category`, `groupedInvoice.invoiceDetails.expenseLines.category`. Same for single_invoice paths if used in detail. |
| EXP-002 | Lazy relation in resource | Medium | `Modules/Expense/app/Transformers/ExpenseDetailResource.php` – base `supplier`, `getPaymentSupplier()` | `supplier` and payment supplier are accessed without `whenLoaded`. If show endpoint does not always load `supplier`, this can trigger extra queries. | `'supplier' => $this->when($this->supplier_id, [...])` uses `$this->supplier`; getPaymentSupplier uses nested relations. | Ensure findForShow (and any list that uses this resource) always loads `supplier` and the type-specific relations used in getPaymentSupplier. |
| EXP-003 | get() in repository | Low | `Modules/Expense/app/Repositories/ExpenseRepository.php` – ~51, 64 | `getSummaryForManager` and `getRecentForManager` use `->get()` (recent is limited to 10). Summary has no limit. | `->get();` for month/year summary. | Add a reasonable limit or document that summary is scoped by month/year (bounded). |

---

### 4.5 Inventory

**Summary of main concerns:** Many DB::transaction blocks in InventorySessionService (9), MonthlyInventoryService, WasteDamageReportService, DailyInventoryScheduleService; get() usage in MonthlyInventoryService, WasteDamageProductService, InventoryTaskListService (some bounded by inventory/report). No caching on main paths.

| ID | Type | Severity | Location | Description | Evidence | Recommended actions (future) |
|----|------|----------|----------|-------------|----------|-----------------------------|
| INV-001 | Many DB::transaction blocks | Medium | `Modules/Inventory/app/Services/InventorySessionService.php` | Multiple operations (create session, add item, approve, reject, end session, etc.) each wrap logic in `DB::transaction`. Long or nested flows could hold connections. | 9 transaction blocks in one service. | Keep closures minimal; avoid external HTTP or heavy loops inside; document. |
| INV-002 | get() in services | Medium | `Modules/Inventory/app/Services/MonthlyInventoryService.php` – ~262, 281, 798, 805; `WasteDamageProductService.php` – ~51, 61, 69; `InventoryTaskListService.php` – ~61, 89, 113 | Various methods use `->get()` for products or items. Some are scoped by inventory/report (bounded), others could grow. | Multiple `->get()` calls in MonthlyInventoryService, WasteDamageProductService, InventoryTaskListService. | Ensure all list-like queries are either paginated or explicitly limited; add indexes on filters used (e.g. inventory_id, report_id) if not present. |
| INV-003 | Timeline load in controller | Low | `Modules/Inventory/app/Http/Controllers/DailyQuickInventoryController.php` – ~573 | Single session timelines loaded with `$session->timelines()->orderBy(...)->get()`. One extra query per request (acceptable). | Single session. | Optionally eager load `timelines` when fetching the session for this action to reduce to one query. |

---

### 4.6 Custody

**Summary of main concerns:** Unbounded transaction loads for balance and trends (CustodyBalanceService, CashierCustodyService); ledger/export use get() without pagination; DB::transaction in CustodyHandoverController (4 blocks), CustodyTransactionService, CustodyRequestService. Listeners (CreateCustodyTransactionFromExpenseApproval, CreatePersonalLedgerTransactionFromHandover, CreateCustodyLedgerEntriesForVariance) may create duplicate entries on event replay. No caching on balance/transaction paths.

| ID | Type | Severity | Location | Description | Evidence | Recommended actions (future) |
|----|------|----------|----------|-------------|----------|-----------------------------|
| CUST-001 | Unbounded get (balance) | High | `Modules/Custody/app/Services/CustodyBalanceService.php` – getCustodyBalance (~22), getCurrentBalance (~121, 132, 164), getTrendsTransactions (~157, 164) | Balance and trends load all transactions for a branch manager (and optional branch) with `->get()` and sum/aggregate in PHP. | `CustodyTransaction::where(...)->get();` and `PersonalLedgerTransaction::where(...)->get();` then foreach sum. | Replace with a single aggregated query (e.g. `selectRaw('SUM(CASE WHEN is_cash_in THEN amount ELSE -amount END)')` or equivalent) so the database returns one row per balance. Same for trends: aggregate in SQL where possible. |
| CUST-002 | Unbounded get (ledger/export) | Medium | `Modules/Custody/app/Services/CustodyBalanceService.php` – ~429–430, 456; `CashierCustodyService.php` – ~162, 183, 219 | Transaction and request lists for ledger/export use `->get()` without pagination. | `$transactions = $transactionsQuery->orderBy(...)->get();` and similar. | Add pagination or a safe limit for ledger/export endpoints; for PDF export consider chunked reads. |
| CUST-003 | Transaction in controller | Low | `Modules/Custody/app/Http/Controllers/CustodyHandoverController.php` – ~63, 116, 197, 312 | Handover actions wrap in `DB::transaction`. Scope is request-scoped; no lock. | Four transaction blocks. | Keep transactions short; ensure no long-running or external calls inside. |

---

### 4.7 Supplier

**Summary of main concerns:** High transaction density across OrderService, OrderFulfillmentService, ReturnManagementService, DeliveryProofService, CommunicationService, InventoryService; no explicit row locking. Single-order get() in OrderService is bounded. No caching on main order/fulfillment paths.

| ID | Type | Severity | Location | Description | Evidence | Recommended actions (future) |
|----|------|----------|----------|-------------|----------|-----------------------------|
| SUP-001 | Transaction density | Medium | `Modules/Supplier/app/Services/OrderService.php`, `OrderFulfillmentService.php`, `ReturnManagementService.php`, `DeliveryProofService.php`, `CommunicationService.php`, `InventoryService.php` | Many operations use `DB::transaction`. No explicit row locking found. | Multiple transaction blocks across services. | Document ordering and avoid long-held transactions; ensure no cross-module lock ordering that could deadlock with Purchase/Custody. |
| SUP-002 | get() in services | Low | `Modules/Supplier/app/Services/OrderService.php` – ~184 | One path loads order relations with `->get()` in a single-order context. | Bounded to one order. | Keep relations eager loaded where possible to avoid N+1 in resources. |

---

## 5. Cross-Cutting Concerns

### 5.1 N+1 and slow query patterns

- **Resources/Transformers running queries:** The most impactful pattern is resources that execute their own queries per collection item (e.g. **BranchManagerShiftResource**). Similar patterns should be audited elsewhere: any transformer that runs `Model::where(...)->get()` inside `toArray()` will cause N+1 when the resource is used in a collection.
- **Missing eager load for nested relations:** **ExpenseDetailResource** (grouped_invoice) shows that even when the repository loads `groupedInvoice.invoiceDetails` and `items`/`expenseLines`, missing nested loads (supplier, category) cause N+1. When adding new relation usage in resources, ensure the corresponding `findForShow()` / list query includes those relations.
- **Recommendation:** Establish a rule: “Resources must not perform database queries; all data must be eager loaded or passed in.” For aggregates (e.g. handover summary), compute in the service/repository and attach to the model or DTO.

### 5.2 Locking and connection pool

- **No `lockForUpdate` / `sharedLock`** was found in the scanned modules. Locking risk is therefore mainly from **long-held transactions** and **connection pool exhaustion** under concurrency.
- **Transaction usage (concrete examples):**
  - **Purchase:** PurchaseOrderService (createOrder ~431, 525; confirmItems ~837, 885; receive/approve/reject flows ~785, 995, 1027, 1094, 1136, 1179, 1212, 1255, 1293, 1336); GoodsReceiptService (receive ~134, 545, 618, 969); ReturnManagementService (60, 154); VarianceService (83, 97); SupplierCommunicationService (23); CleanBulkPurchaseOrdersCommand (59). No explicit lock ordering documented; cross-module calls (e.g. RecurringOrder → Purchase) could hold connections across service boundaries.
  - **Inventory:** InventorySessionService (9 transaction blocks: create session, add/update item, approve, reject, end session, etc. at ~137, 266, 305, 348, 379, 414, 498, 545, 585); MonthlyInventoryService (multiple at ~115, 291, 324, 341, 391, 420, 459, 496, 527); WasteDamageReportService (102, 148, 205, 305); DailyInventoryScheduleService (23, 98).
  - **Custody:** CustodyHandoverController (63, 116, 197, 312); CustodyTransactionService (60, 80, 98, 115); CustodyRequestService (19).
  - **Supplier:** OrderService (180, 228, 272, 300, 338, 402, 461, 506, 560); OrderFulfillmentService (41, 133, 213, 287, 356); ReturnManagementService (76, 109, 136); DeliveryProofService (22, 71); CommunicationService (136); InventoryService (45).
  - **Shift:** BranchManagerShiftService (115, 358) for handover and cashier breakdown updates.
- **Connection pool:** ProcessRecurringOrdersJob processes up to 50 recurring orders in one run; each processOne() calls PurchaseOrderService::createOrder() inside a transaction. Under load, multiple job workers could hold many connections. CleanBulkPurchaseOrdersCommand runs transactions in a loop (chunked). Long-running exports (e.g. Custody PDF, Inventory monthly export) can hold a connection for the duration of the request.

### 5.3 Idempotency and retry behavior

- **Jobs audited (in-scope modules):** ProcessRecurringOrdersJob (RecurringOrder), SendShiftStartReminderJob (Shift). ProcessRecurringOrdersJob creates a purchase order then updates the recurring order; on retry (e.g. after timeout or failure after createOrder) the same recurring order can be processed again and create a duplicate PO. SendShiftStartReminderJob sends notifications; duplicate send on retry is a business/UX concern but not a data-duplication risk.
- **Listeners audited:** CreateCustodyTransactionFromExpenseApproval, CreatePersonalLedgerTransactionFromHandover, CreateCustodyLedgerEntriesForVariance (Custody); UpdateRecurringOrderWhenPurchaseOrderEnded (RecurringOrder); SendOrderNotification, SendReturnApprovedNotification (Purchase). Custody listeners create ledger/transaction rows; if the same event is re-dispatched or replayed, duplicate entries could be created unless guarded by a unique key or idempotency check. UpdateRecurringOrderWhenPurchaseOrderEnded updates a recurring order (likely idempotent if keyed by order). Purchase notification listeners are side-effect only.
- **Recommendation:** For any job or listener that creates orders, transactions, or ledger entries, add idempotency (e.g. unique constraint + upsert, or idempotency key table) so that retries do not duplicate data.

### 5.4 Caching and consistency

- **Caching observed:** Shift uses Cache::remember (300s TTL) in BranchManagerShiftService for handover lists and daily-close summary, and in BranchManagerShiftController for one endpoint; tags used where applicable. Purchase uses Cache::remember in PriceComparisonService for distance data (configurable TTL). Expense, Inventory, Custody, Supplier, RecurringOrder do not use Cache::remember on main data paths in the audited code. Balance and trend calculations in Custody are computed on every request.
- **Recommendation:** If caching is extended to balance/summary data, invalidate on write (e.g. when creating custody transactions or expense approvals) and use a short TTL to avoid stale reads.

### 5.5 Pagination and large datasets

- **Purchase:** In-memory pagination in `PurchaseOrderService::getOrdersForReceiving()` is the most critical; it should be replaced with database pagination.
- **Shift:** HandoverService and ShiftService have unbounded `get()` for list/stats; ShiftRequestsService allows null perPage and falls back to `get()`.
- **Custody:** Balance and ledger flows load all transactions; add pagination or aggregation.
- **Recommendation:** For any list or report endpoint, prefer `paginate()` or `cursorPaginate()` and avoid `get()` unless the result set is explicitly bounded (e.g. by a limit or a narrow filter).

### 5.6 Isolation levels and race condition risks

- No explicit database isolation level configuration was found in the in-scope modules (no `DB::statement('SET TRANSACTION ISOLATION LEVEL ...')` or equivalent). The application relies on the default isolation level of the database driver (typically READ COMMITTED for MySQL/PostgreSQL). Race conditions are possible where two requests or jobs update the same entity (e.g. recurring order status, custody balance) without optimistic locking or explicit locking; the audit did not find version columns or lockForUpdate usage. NFR tests reference concurrent transaction isolation; production code does not set isolation explicitly.
- **Recommendation:** Document the default isolation level in use; for critical balance or order-state updates, consider optimistic locking (version column) or a short lock where appropriate in a future feature.

---

## 6. Recommended Roadmap

Implement in follow-up features; do not change production behavior in this branch.

1. **P0 – Purchase in-memory pagination (PUR-001)**  
   Refactor `PurchaseOrderService::getOrdersForReceiving()` to use database pagination and move “has items” into the query. No API response shape change.

2. **P0 – Shift BranchManagerShift N+1 (SHIFT-001)**  
   Move handover summary and financial summary out of BranchManagerShiftResource into the service/repository (eager aggregate or subquery) and pass as attributes or a single relation. No API response shape change.

3. **P1 – Expense detail N+1 (EXP-001)**  
   Extend `ExpenseRepository::findForShow()` with `groupedInvoice.invoiceDetails.supplier`, `groupedInvoice.invoiceDetails.items.category`, `groupedInvoice.invoiceDetails.expenseLines.category` (and equivalent for single_invoice/pre_approval where needed). No resource/output change.

4. **P1 – Custody balance aggregation (CUST-001)**  
   Replace “load all transactions and sum in PHP” with a single aggregated query (or a small set of queries) for balance and, where possible, for trends. No API response shape change.

5. **P1 – ProcessRecurringOrdersJob idempotency (REC-001)**  
   Add idempotency (e.g. processing lock or “last generated for this run” guard) so retries do not create duplicate purchase orders.

6. **P2 – Unbounded get() reduction**  
   Address SHIFT-002, SHIFT-003, SHIFT-004, PUR-002, CUST-002: add pagination or safe limits. Same for INV-002 where applicable.

7. **P2 – Transaction and job documentation**  
   Document transaction boundaries and job retry behavior; add a short “concurrency and idempotency” section to the team wiki or spec.

8. **P3 – Index and slow-query review**  
   In a separate, index-only change: add indexes on frequently filtered/sorted columns (e.g. branch_id + created_at, branch_manager_id + transaction_date) where profiling shows slow queries. No schema change in this report; to be validated with query logs.

---

## 7. Appendices

### 7.1 Verification (review deliverable — documentation only)

- Per the plan and spec (FR-001, FR-008), **this review deliverable** is limited to analysis, documentation, and recommendations. The **report and spec artifacts** under `specs/001-modules-n1-query/` are the only required outputs for the review feature branch.
- **To verify the review branch:** Ensure that only documentation and spec-related files (e.g. under `specs/001-modules-n1-query/`) were modified; no changes to Controllers, Services, Repositories, Models, Migrations, API routes, or configuration that affect production HTTP responses, business logic, or database schema. This can be confirmed via `git diff` against the production branch.
- If implementation work to address findings is done in a separate branch or later, this report remains the record of findings at review time; the roadmap and recommended actions are for future features.

### 7.2 Raw query patterns (examples)

- **N+1 (Shift):** For each of N BranchManagerShift resources, 2 queries of the form:  
  `SELECT * FROM cashier_shift_handovers WHERE ... AND EXISTS (SELECT * FROM cashier_shifts ...)`
- **Unbounded (Purchase):**  
  `SELECT * FROM purchase_orders WHERE branch_id = ? AND ... ORDER BY created_at DESC` (no LIMIT), then PHP filter and slice.
- **Balance (Custody):**  
  `SELECT * FROM custody_transactions WHERE branch_manager_id = ? [AND branch_id = ?]` (no LIMIT), then PHP sum.

### 7.3 Future recommendations (not applied)

All “Recommended actions” in the findings tables and the roadmap above are **future recommendations** only. They must be implemented in later features with appropriate testing and deployment steps. No code or schema changes have been applied in this audit.
