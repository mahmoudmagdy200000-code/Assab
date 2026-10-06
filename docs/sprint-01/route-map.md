# Financial Route Map (Sprint 01)

This map tracks the flow of requests related to the Cash Cycle (Shift Start, End, Handover, Accept/Reject, Submission) through the system.

## Transaction Boundaries and Authoritative Writers
**AS-IS Code Analysis (Not Business Target)**

- **Current Transaction Boundary (ShiftEndService):** 
  - The `endShiftOnly` method begins a transaction. It saves to `cashier_shifts`, `shift_sales_breakdown` and history. Then it commits the transaction.
  - **Premature Commit:** Immediately after the DB commit, it attempts to record the `CashierCustodyTransaction` (Cash-IN for Total Sales). If this fails, the exception is swallowed (`Log::warning`), allowing partial financial persistence (the shift ends successfully but custody is missing).
- **Current Transaction Boundary (HandoverService):**
  - Handover accept (`acceptHandoverByCashier`) wraps the status updates in a transaction and commits. Then, custody transactions for sender (Cash-OUT) and receiver (Cash-IN) are created *outside* the transaction with swallowed exceptions.
- **Authoritative Writers:** 
  - `ShiftEndService`, `HandoverService`, and `VarianceCalculationService` act as the authoritative writers for the main ledger tables.
- **Source/Ledger:** `cashier_shifts`, `cashier_shift_handovers`, `shift_variance_details`, `cashier_custody_transactions`, `personal_ledger_transactions` are the SOURCE and AUTHORITATIVE LEDGER.
- **Dashboard Projections:** `asab_shifts` and `asab_operations` are purely downstream PROJECTIONS. 

---

## 1. Start Shift (By Cashier)
- **Method:** `POST`
- **Full URI:** `/api/v1/cashier/shifts/{shift}/start`
- **Middleware:** `auth:sanctum`, `cashier`, `log.throttle`
- **Controller:** `CashierShiftController@startShift`
- **Validation:** (None specific to body)
- **Service/Logic:** Verifies shift belongs to cashier, isn't already started.
- **Event:** `ShiftStartedEvent` (triggers `CashierShiftObserver`)
- **Tables Updated (SOURCE):** `cashier_shifts`

## 2. Start Shift (By Manager for Cashier)
- **Method:** `POST`
- **Full URI:** `/api/v1/branch-manager/shifts/start-by-manager/{shiftId}`
- **Middleware:** `auth:sanctum`, `branch.manager.or.cashier`, `log.throttle`
- **Controller:** `CashierShiftController@startShiftByManager`
- **Validation:** Internal
- **Service/Logic:** Manager forces shift start.
- **Tables Updated (SOURCE):** `cashier_shifts`

## 3. End Shift Only (No Handover Yet)
- **Method:** `POST`
- **Full URI:** `/api/v1/cashier/shifts/{shift}/end`
- **Middleware:** `auth:sanctum`, `cashier`, `log.throttle`
- **Controller:** `ShiftEndController@endShiftOnly`
- **Validation:** `ShiftEndRequest` (Validates `total_sales`, `cash_collected`, `card_payments`, `aggregators`)
- **Service:** `ShiftEndService@endShiftOnly`
- **Financial Writers:**
  - `ShiftEndService` writes `shift_sales_breakdown` inside transaction.
  - `ShiftEndService` writes `cashier_custody_transactions` (Cash-IN) *outside* transaction.
- **Tables Updated (SOURCE):** `cashier_shifts`, `shift_sales_breakdown`, `cashier_shift_history`, `cashier_custody_transactions`

## 4. End Shift With Handover
- **Method:** `POST`
- **Full URI:** `/api/v1/cashier/shifts/{shift}/end-with-handover`
- **Middleware:** `auth:sanctum`, `cashier`, `log.throttle`
- **Controller:** `ShiftEndController@endShiftWithHandover`
- **Validation:** `EndShiftWithHandoverRequest`
- **Service:** `ShiftEndService@endShiftWithHandover`, `HandoverService@recordHandover`, `VarianceCalculationService@recordVariance`
- **Financial Writers:**
  - `VarianceCalculationService` writes `shift_variance_details`.
  - `HandoverService` writes `cashier_shift_handovers` and `shift_handover_status`.
- **Tables Updated (SOURCE):** `cashier_shifts`, `cashier_shift_handovers`, `shift_handover_status`, `shift_variance_details`, `shift_sales_breakdown`

## 5. Record Handover Later
- **Method:** `POST`
- **Full URI:** `/api/v1/cashier/shifts/{shift}/start-handover`
- **Middleware:** `auth:sanctum`, `cashier`, `log.throttle`
- **Controller:** `ShiftEndController@startHandover`
- **Validation:** `StartHandoverRequest`
- **Service:** `HandoverService@recordHandover`, `VarianceCalculationService@recordVariance`
- **Tables Updated (SOURCE):** `cashier_shift_handovers`, `shift_handover_status`, `shift_variance_details`

## 6. Receive Handover (Accept/Reject by Cashier)
- **Method:** `POST`
- **Full URI:** `/api/v1/cashier/shifts/{shift}/handover/accept` / `reject`
- **Middleware:** `auth:sanctum`, `cashier`, `log.throttle`
- **Controller:** `ShiftHandoverController@acceptHandover` / `rejectHandover`
- **Validation:** `RejectHandoverRequest` (for rejection)
- **Service:** `HandoverService@acceptHandoverByCashier` / `rejectHandoverByCashier`
- **Financial Writers (Accept):** 
  - `CashierCustodyService` writes `cashier_custody_transactions` (Cash-OUT for sender, Cash-IN for receiver) outside transaction.
- **Tables Updated (SOURCE):** `cashier_shift_handovers`, `shift_handover_status`, `cashier_custody_transactions`

## 7. Manager Handoffs (Approve/Reject)
- **Method:** `POST`
- **Full URI:** `/api/v1/branch-manager/workday/handoffs/approve` / `reject`
- **Middleware:** `auth:sanctum`, `branch.manager`, `log.throttle`
- **Controller:** `BranchManagerShiftController@approveHandoff` / `rejectHandoff`
- **Validation:** `RejectHandoffRequest`
- **Service:** `HandoverService@approveHandover` / `rejectHandover`
- **Financial Writers (Approve):**
  - Event `HandoverApproved` triggers `PersonalLedgerTransaction` writes.
  - `CashierCustodyService` writes `cashier_custody_transactions` (Cash-OUT for cashier).
- **Tables Updated (SOURCE):** `cashier_shift_handovers`, `shift_handover_status`, `cashier_custody_transactions`, `personal_ledger_transactions`

## 8. Handover Rejection Mapping
### AS-IS Behavior:
`HandoverService::revertCashierShiftAfterHandoverRejection()` performs destructive rollbacks:
- **Hard Deletes:** `CashierCustodyTransaction`, `PersonalLedgerTransaction`, `ShiftSalesBreakdown`, `ShiftVarianceAlert`, `ShiftVarianceDetail`, `CashierShiftHandover`, `ShiftHandoverStatus`.
- **Resets:** Sets `status` to `IN_PROGRESS`, zeroes out `total_sales`, `net_sales`, `vat_amount`, `cash_collected`, `card_payments`, `closing_balance`, `expected_balance`, `variance`. Nulls `handed_over_at`, `handover_notes`, `actual_end_time`, `pos_receipt`.
- **Ledger Impact:** Completely destroys previous ledger history related to the end-shift actions.

### TO-BE Target:
- **BR-15, BR-16, BR-17, BR-24 Requirements:** Previous submissions and variance details MUST be preserved. Deletions must be replaced with forward-additive revisions.

## 9. Resubmit / Edit Handover After Rejection
- **Method:** `POST`
- **Full URI:** `/api/v1/cashier/shifts/{shift}/handover/edit`
- **Middleware:** `auth:sanctum`, `cashier`, `log.throttle`
- **Controller:** `ShiftHandoverController@editHandoverAfterRejection`
- **Validation:** `EditHandoverRequest`
- **Service:** `HandoverService@recordHandoverEdit`
- **Tables Updated (SOURCE):** `cashier_shift_handovers`, `shift_handover_status`

## 10. Final Daily Close / Daily Submit
- **Method:** `POST`
- **Full URI:** `/api/v1/branch-manager/workday/daily-close/submit`
- **Middleware:** `auth:sanctum`, `branch.manager`, `log.throttle`
- **Controller:** `BranchManagerShiftController@submitDailyReport`
- **Validation:** Internal check for pending handovers
- **Service:** `BranchManagerShiftController` triggers event.
- **Event:** `DailyReportSubmittedEvent`
- **Legacy/Admin Bridge:** `BridgeManagerDailyClose` listens to the event and mints a `module_key='sales'` operation in `asab_operations` (PROJECTION).
- **Tables Updated:** `asab_operations`

---

## Dashboard Consumer Mapping
Using `artifacts/mockup-sandbox/src/api/queries/shifts.ts`, the Dashboard application (React) accesses data via Admin/Company endpoints, completely separated from the Mobile Backend (`/api/v1/cashier/shifts`).

- `useShiftsLive()` -> `GET /accountant/shifts/live` (Response: `ShiftsLiveResponse` wrapping `asab_shifts`)
- `useShiftsHistory()` -> `GET /accountant/shifts/history` (Response: `Page<Shift>`)
- `useCloseShift()` -> `POST /company/me/shifts/{shiftId}/close` (Sends halalas: `cashActualHalalas`, `cardTotalHalalas`, `aggregatorTotalsHalalas`) -> Invalidates `operations` query hook.
- `useOpenShift()` -> `POST /company/me/branch/shifts/open`

## Mobile Compatibility Evidence
The Mobile app (Flutter in `AssabAPP`) consumes the REST API natively using floating points (`decimal`).
- **Endpoints:** Uses `/api/v1/cashier/shifts/{shift}/end` etc.
- **JSON Payload Fields Used:** `total_sales`, `net_sales`, `vat_amount`, `cash_collected`, `card_payments`, `aggregators` (list with `amount`, `aggregator_id`), `variance` (object with `reason`, `supporting_files`).
- Mobile operates entirely on `cashier_shifts` and does not interact with `asab_operations`.

## Business Target vs Current Code (Findings)
- **BR-01–03, BR-05–06:** Custody and variance currently write to the database *outside* the main commit transaction with swallowed exceptions, leading to potential data orphans.
- **BR-15–17, BR-24–25:** Rejection currently performs HARD DELETES on the ledger and variance tables. This violates the business rule to maintain full immutable history of submissions.
