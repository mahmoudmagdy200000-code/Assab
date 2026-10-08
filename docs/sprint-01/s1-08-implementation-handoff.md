# S1-08 Phase 1 and Phase 2 implementation handoff

**Status:** Ready for review / Not Accepted. This is implementer evidence for Phases 1 and 2; it is not sprint acceptance.

**Phase 1 starting baseline:** `c018fa01caaf439a5d5718d63b99fcefe6611835`.
**Phase 2 starting baseline:** `9fc8ebacf99d23a1b43d27101420be65e4f5e5f0` on `sprint/01-financial-foundation`.

## Implemented boundary

- `ShiftReportAggregate` and `ShiftReportRevision` provide a stable UUID and monotonic revision number for cashier and branch-manager report identities. Revisions are created with report writes, handover creation/edit, and manager close/correction writes. They do not store a snapshot or correction history.
- `BranchManagerCashTransfer` stores source manager workday, destination cashier and receiving shift, requested amount, creating manager, bound report revision, and pending/confirmed state. `requestManagerCashTransfer` creates only this request; it does not create a receipt or financial movement.
- `CashierShiftHandoverReceipt` is written only by `ShiftTransferReceiptService` after actual recipient confirmation. It identifies one typed source request, exact amount, source report revision, confirming actor/time, and either the actual receiving cashier shift/cashier or the addressed manager's workday/identity. Model updates/deletes are rejected. One receipt per source request is enforced in the database.
- Confirmation amount must equal the current request. A mismatch returns `HANDOVER_AMOUNT_MISMATCH_CORRECTION_REQUIRED` with no receipt/effects. An intentional smaller transfer uses reject → correct → exact confirm; shift-history evidence records old/new amounts, attempted amount, actor, time, reason, report revision, and submitted variance evidence. `actual_shortage` does not create a liability allocation.
- Cashier-to-cashier confirmation writes both custody rows. Addressed manager confirmation of cashier-to-manager handover writes cashier `Handover Sent` debit and manager `Total Sales` credit, both linked to the receipt. Manager-to-cashier confirmation writes the manager ledger debit and recipient custody credit. Only the exact confirmed value moves.
- Manager review of a cashier-to-cashier request is audit-only; it leaves the request pending for the named cashier. It cannot create receipt evidence or prevent recipient confirmation.
- The confirmed opening projection is calculated from receipts for that exact receiving cashier shift. Configured float, source closing balance, request amount, and manager approval are not receipt evidence.
- `ShiftEndedEvent` is dispatched after the report transaction commits so the Admin bridge remains a projection rather than a second authority inside the report transaction.

## Transactions and lock order

Report-ending writes include the report, sales breakdown replacement, revision identity, required report custody declaration, variance rows where applicable, and history in the enclosing transaction. Receipt confirmation uses one transaction and a consistent hierarchy:

1. Manager transfer: lock and re-read the manager workday, then the destination cashier shift, then lock and re-read the transfer request.
2. Cashier handover: resolve the candidate destination, lock and re-read both source and destination cashier shifts in ascending shift-ID order, then lock and re-read the handover.
3. Cashier-to-manager receipt: lock and re-read the addressed manager workday, then source cashier shift, then handover request.
4. Validate current report revision, exact amount, request state, addressed recipient, and branch from the locked rows.
5. Insert receipt, required custody/ledger effects, resulting request and cashier opening projection if applicable, then audit history.

Manager close/correction follows manager workday → cashier shifts in ascending shift-ID order. Cashier close locks its cashier shift; its manager-statistics mirror is non-authoritative and runs after commit, after the cashier lock is released. Required receipt, custody, ledger, state, opening, and audit writes remain inside the financial transaction.

Required database write failures propagate and roll the entire confirmation back. Report aggregate revision increments lock the aggregate and use an expected revision check. File uploads touched by the cashier close/handover paths are staged before the financial locks.

## Migration

Additive migrations: `2026_10_08_000003_create_shift_report_transfer_receipts.php` and `2026_10_08_000004_add_manager_recipient_receipt_fields.php`.

Migration 000003 creates stable report identity, manager-to-cashier request, and receipt structures; adds the handover revision FK and receipt-effect references/uniqueness. Migration 000004 minimally adds typed manager receiver/workday/confirmer FKs and makes cashier-only receipt fields nullable. No applied migration is edited. Rollback of 000004 refuses to run after manager receipt evidence exists. MySQL migration execution and production table sizes have not been checked; nullability changes and new FKs/indexes may require an approved online-DDL or maintenance plan.

## Deferred work

- **S1-09:** generic idempotency keys, canonical payload hashes, replay response recovery, and retry guarantees.
- **S1-10:** trusted physical cash count, counted-cash route integration, signed-variance adapter, and real `LiabilityEvidenceSource`.
- **S1-11:** full immutable report revisions/correction history, public liability routes, daily-submit/reopen enforcement, and complete server-derived daily transfer membership. S8-01 uses only existing shift history for transfer correction evidence.

No S1-10 count/receipt evidence or liability allocation is inferred from `actual_shortage`. A cashier-to-manager receipt is created only when the addressed manager confirms the exact amount. Manager review of cashier-to-cashier handover remains non-final. The manager-to-cashier command remains a service primitive; no new public endpoint is added.

## Validation limits

PHP syntax and changed-file Pint are run on the changed PHP files. Focused database tests are intended for SQLite; SQLite can verify transaction rollback and schema behavior but does not establish MySQL locking, concurrency, deadlock behavior, or production DDL impact. A full project test result is reported separately in `verification.md` only if actually run.

## Phase 2 authority and transaction boundary

The Admin/native `Shift` close is a separate authority from the mobile `CashierShift` close. `ShiftCloseService::close` locks and re-reads the native shift before deriving expected cash/variance. `OperationService::finalApprove` locks/re-reads the approved operation and, for `module_key=shifts`, includes the final operation state, approval step, required `EmployeeMovement` allocation, and native shift close in one transaction. Allocation failure leaves the operation approved, shift pending review, and no final step/movement. Final-approval bridge listeners run after commit and are projection-only; their failures are logged.

Cashier report close keeps report totals, breakdown, report revision, required declaration custody, variance details, and history in the existing transaction. Observer-emitted start/close bridges and manager-statistics mirrors now run after commit and are non-authoritative projections. They cannot hold a cashier row while acquiring a manager-shift row. A projection error is logged without converting the committed close to a reported financial failure.

Manager review of cashier-to-cashier handover locks/re-reads the cashier shift, handover and status, then writes review history only; the named recipient cashier still confirms. For cashier-to-manager handover, addressed-manager confirmation follows `BranchManagerShift → CashierShift → handover`, and the sole receipt writer atomically stores manager receipt identity, cashier debit, manager `Total Sales` credit, state, and audit. `VarianceRecorded` remains a synchronous, required legacy variance projection within the caller transaction; it is separate from transfer receipt evidence.

## Phase 2 lock orders and test evidence

- Mobile close and variance liability approval: `CashierShift → report aggregate/revision or handover/status/detail → required effect/history rows`.
- Cashier confirmation: source and destination `CashierShift` rows in deterministic ascending ID order → handover.
- Manager transfer: `BranchManagerShift → destination CashierShift → transfer`.
- Manager close/correction: `BranchManagerShift → CashierShift` rows in ascending ID order → request/transfer.
- Admin close: native `Shift → newly created Operation/ApprovalStep` (the operation is not visible before close commits).
- Admin final approval: `Operation → native Shift → EmployeeMovement/ApprovalStep`.

Current focused verification: **33 tests / 134 assertions / 0 failures** across receipt/transfer, cashier close, handover variance, manager ledger, and native shift close/final approval files. A PHP 8.4.26 SQLite run verifies rollback and locked re-read behavior only. It does not prove MySQL lock ordering, deadlock safety, production transaction behavior, or migration/online-DDL safety. Broader regression and changed-file style/syntax checks are recorded in `verification.md` only after they are executed.

S1-08 remains ready for review, not accepted. S1-09 replay/idempotency, S1-10 trusted counted-cash evidence, and S1-11 route/history/daily-submit integration remain deferred. MySQL concurrency and deployment DDL review remain open follow-ups.
