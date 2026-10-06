# Shift Route Map (Sprint 01)

This map tracks the flow of requests related to the Cash Cycle (Shift Start, End, Handover, Accept/Reject, Submission) through the system.

## 1. Start Shift (By Cashier)
- **Route:** `POST /api/v1/cashier/shifts/{shift}/start`
- **Middleware:** `auth:sanctum`, `cashier`, `log.throttle`
- **Controller:** `CashierShiftController@startShift`
- **Service/Logic:** Verifies shift belongs to cashier, isn't already started, and changes status to `IN_PROGRESS`.
- **Observer/Event:** `CashierShiftObserver` triggers `ShiftStartedEvent`.
- **Tables Updated:** `asab_cashier_shifts` (status)
- **Response:** JSON with `ShiftDetailResource`.

## 2. Start Shift (By Manager for Cashier)
- **Route:** `POST /api/v1/shifts/start-by-manager/{shiftId}`
- **Middleware:** `auth:sanctum`
- **Controller:** `CashierShiftController@startShiftByManager`
- **Tables Updated:** `asab_cashier_shifts`

## 3. End Shift Only (No Handover Yet)
- **Route:** `POST /api/v1/shifts/{shift}/end`
- **Middleware:** `auth:sanctum`
- **Validation:** Request variables (`total_sales`, `cash_collected`, `card_payments`, `aggregators`, `variance.*`).
- **Controller:** `ShiftEndController@endShiftOnly`
- **Service:** `ShiftEndService@endShiftOnly`, `VarianceCalculationService@recordVariance`
- **Observer/Event:** `CashierShiftObserver` triggers `ShiftEndedEvent`. Handled by `ShiftEndedListener`.
- **Tables Updated:** `asab_cashier_shifts` (status `ENDED`, totals), `asab_sales_breakdowns`, `asab_shift_variances`
- **Response:** JSON with `ShiftDetailResource` and variance summaries.

## 4. End Shift With Handover
- **Route:** `POST /api/v1/shifts/{shift}/end-with-handover`
- **Middleware:** `auth:sanctum`
- **Controller:** `ShiftEndController@endShiftWithHandover`
- **Service:** `ShiftEndService@endShiftWithHandover`, `VarianceCalculationService@recordVariance`
- **Observer/Event:** `CashierShiftObserver` triggers `ShiftEndedEvent`.
- **Tables Updated:** `asab_cashier_shifts`, `asab_shift_handovers` (status `pending`), `asab_shift_variances`

## 5. Record Handover Later (If Ended Without Handover)
- **Route:** `POST /api/v1/shifts/{shift}/start-handover`
- **Controller:** `ShiftEndController@startHandover`
- **Service:** `HandoverService@recordHandover`, `VarianceCalculationService@recordVariance`
- **Tables Updated:** `asab_shift_handovers`, `asab_shift_variances`

## 6. Receive Handover (Accept/Reject by Cashier)
- **Route (Accept):** `POST /api/v1/cashier/shifts/{shift}/handover/accept`
- **Route (Reject):** `POST /api/v1/cashier/shifts/{shift}/handover/reject`
- **Controller:** `ShiftHandoverController@acceptHandover` / `rejectHandover`
- **Tables Updated:** `asab_shift_handovers` (status -> `accepted` or `rejected`), `asab_cashier_shifts` (reopens on reject)

## 7. Manager Handoffs (Approve/Reject)
- **Route (Approve):** `POST /api/v1/branch-manager/workday/handoffs/approve`
- **Route (Reject):** `POST /api/v1/branch-manager/workday/handoffs/reject`
- **Controller:** `BranchManagerShiftController@approveHandoff` / `rejectHandoff`
- **Tables Updated:** `asab_shift_handovers`

## 8. Resubmit / Edit Handover After Rejection
- **Route:** `POST /api/v1/cashier/shifts/{shift}/handover/edit`
- **Controller:** `ShiftHandoverController@editHandoverAfterRejection`
- **Tables Updated:** `asab_shift_handovers`

## 9. Final Daily Close / Daily Submit
- **Route (Submit):** `POST /api/v1/branch-manager/workday/daily-close/submit`
- **Controller:** `BranchManagerShiftController@submitDailyReport`
- **Event:** `DailyReportSubmittedEvent`
- **Legacy/Admin Bridge:** `BridgeManagerDailyClose` listens to the event and mints a `module_key='sales'` operation. This connects the mobile cash cycle to the dashboard accountant inbox (`asab_operations`).
- **Tables Updated:** Finalizes daily ledgers, locks shifts, `asab_operations` created.

## Transaction Boundaries and Authoritative Writers
- **Authoritative:** The `asab_cashier_shifts` (Cashier shift execution), `asab_shift_handovers` (Transfers of responsibility), and `asab_shift_variances` tables are the ultimate source of truth.
- **Derived Mirrors (Dashboard Consumers):** The dashboard accountants read from `asab_operations` (the inbox) and related pivot tables bridged by the Admin namespace. 
- Manager approvals lock the local shifts, and `BridgeManagerDailyClose` pushes the state up to the dashboard.
