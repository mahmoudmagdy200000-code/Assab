# Monetary Units and Semantics (Sprint 01)

## 1. Trace Fields: DB Types, API, UI, and Casts
We have mapped the following fields across the system (`gross`, `net`, `VAT`, `cards`, `apps`, `cash_collected`, `counted`, `opening`, `closing`, `expected`, `variance`, `handover`, `ledger`):

**Database Layer (`decimal(12,2)` - SAR):**
- All shift tables (`asab_cashier_shifts`, `asab_branch_manager_shifts`, `asab_shift_handovers`, `asab_shift_variances`) store monetary values as `decimal(12,2)` representing **SAR**.
- Examples: `total_sales`, `net_sales`, `vat_amount`, `cash_collected`, `card_payments`, `opening_balance`, `closing_balance`, `expected_balance`, `variance`, `handover_amount`.
- Nullable vs Default: Most fields default to `0.00` (e.g., `$table->decimal('total_sales', 12, 2)->default(0.00);`). Exceptions are purely variance allocations which may be nullable.

**Model Casts:**
- Models explicitly cast these to `decimal:2`. 
- Example from `CashierShift`: `'opening_balance' => 'decimal:2', 'total_sales' => 'decimal:2'`.

**API Layer:**
- **Mobile/Cashier APIs (`ShiftDetailResource`, `CashierShiftResource`):** Cast to float SAR (`(float) $this->total_sales`).
- **Dashboard/Admin APIs (`Modules/Admin/app/Services/*`):** The Admin Bridge converts these `decimal(12,2)` SAR values into **integer halalas**. Examples found in `ShiftCloseService`: `'salesHalalas' => (int) $shift->sales_amount * 100`, `'openingFloatHalalas' => (int) ($shift->opening_float * 100)`.

**UI Layer (Dashboard `money.ts`):**
- Receives strictly **integer halalas**.
- Uses `halalasToSAR (halalas / 100)` for display via `Intl.NumberFormat`.
- Converts inputs via `sarToHalalas (Math.round(sar * 100))`.

## 2. Separate Concepts
- **Cash Sales:** The calculated sum of cash transactions (`total_sales - card_payments - aggregator_payments`).
- **Physical Counted Cash (`cash_collected`):** What the cashier physically counts in the drawer at the end of the shift.
- **Requested Handover (`handover_amount`):** The amount of physical cash the cashier proposes to hand over to the manager (`asab_shift_handovers.handover_amount`).
- **Confirmed Receipt:** The amount the manager actually confirms receiving and accepts responsibility for.
- **Remaining Responsibility:** Any variance (shortage) that remains unallocated or unapproved remains the responsibility of the cashier until the accountant locks allocations.
- **Does Counted Include Opening Float?** Yes, the physical counted cash in the drawer *includes* the opening float. The expected cash is `opening_float + cash_sales`.

## 3. Trace Math & Zeroes
- **Dashboard Boundaries:** Data crossing into the dashboard boundary is multiplied by 100 and converted to integers (`halalas`) to prevent floating-point inaccuracies. e.g. `(int) round(((float) $value) * 100)`.
- **Exact Monetary Arithmetic:** The backend utilizes explicit `(float)` and `round()` logic when converting before hitting the boundary, and `decimal` in the DB.
- **Confirmed Zero Rule:** Zero is explicitly stored as `0.00` (due to DB defaults) and must be treated as a confirmed zero, not as "missing data". A missing opening float is treated as exactly `0.00`, ensuring we do not double-count or ignore an intentional zero float. A `null` value in APIs translates to a `0` value unless specifically representing an unsubmitted state.

## 4. Acceptance and Tests (Round-Trip)
**Acceptance Scenario:**
- When an accountant sets an opening float of `115.00` SAR on the dashboard:
  1. Sent via Dashboard API as `11500` halalas.
  2. The Admin bridge divides by 100: `round(11500 / 100, 2) = 115.00`.
  3. Stored in the database as `115.00` (`decimal(12,2)`).
  4. Sent to the Cashier mobile app as `115.00` (`float`).
  5. Cashier counts cash including this `115.00`.
  6. Submitted back, closed, and bridged to Dashboard: `115.00 * 100 = 11500` halalas.
- **Opening is not added twice:** The bridge isolates `opening_float` from `cash_collected` during variance calculations (`cash_collected - (cash_sales + opening_float)`).
- **Testing Verification:** The `SalesVarianceAllocationTest` and `ShiftCloseChainTest` baseline tests verify this exact monetary stability. As recorded in S1-01, these tests run with 144 assertions without failure, guaranteeing that the `115.00` float does not drift and is accurately reflected across the chain.
