# S1-08 Phase 1 implementation handoff

**Status:** Ready for review / Not Accepted. This is implementer evidence for the bounded Phase 1 only; it is not sprint acceptance.

**Starting baseline:** `c018fa01caaf439a5d5718d63b99fcefe6611835` on `sprint/01-financial-foundation`.

## Implemented boundary

- `ShiftReportAggregate` and `ShiftReportRevision` provide a stable UUID and monotonic revision number for cashier and branch-manager report identities. Revisions are created with report writes, handover creation/edit, and manager close/correction writes. They do not store a snapshot or correction history.
- `BranchManagerCashTransfer` stores source manager workday, destination cashier and receiving shift, requested amount, creating manager, bound report revision, and pending/confirmed state. `requestManagerCashTransfer` creates only this request; it does not create a receipt or financial movement.
- `CashierShiftHandoverReceipt` is written only by `ShiftTransferReceiptService` after recipient confirmation. The service enforces exactly one typed source request. It identifies that source, the actual receiving cashier shift/cashier, exact confirmed amount, report revision, confirming cashier, and time. Model updates/deletes are rejected. One receipt per source request is enforced in the database.
- Cashier-to-cashier confirmation writes both custody rows; manager-to-cashier confirmation writes the manager ledger debit and recipient custody credit. Receipt-linked uniqueness prevents duplicate effects for each receipt. Only confirmed value is posted (e.g. request 61.50, confirmation 61.00 posts 61.00).
- The confirmed opening projection is calculated from receipts for that exact receiving cashier shift. Configured float, source closing balance, request amount, and manager approval are not receipt evidence.
- `ShiftEndedEvent` is dispatched after the report transaction commits so the Admin bridge remains a projection rather than a second authority inside the report transaction.

## Transactions and lock order

Report-ending writes include the report, sales breakdown replacement, revision identity, required report custody declaration, variance rows where applicable, and history in the enclosing transaction. Receipt confirmation uses one transaction and a consistent hierarchy:

1. Manager transfer: lock and re-read the manager workday, then the destination cashier shift, then lock and re-read the transfer request.
2. Cashier handover: resolve the candidate destination, lock and re-read both source and destination cashier shifts in ascending shift-ID order, then lock and re-read the handover.
3. Validate current report revision, request state, recipient, and branch from the locked rows.
4. Insert receipt, required custody/ledger effects, resulting request and opening projection, then audit history.

Manager close/correction follows manager workday → cashier shifts in ascending shift-ID order. Cashier close locks its cashier shift; its manager-statistics mirror is non-authoritative and runs after commit, after the cashier lock is released. Required receipt, custody, ledger, state, opening, and audit writes remain inside the financial transaction.

Required database write failures propagate and roll the entire confirmation back. Report aggregate revision increments lock the aggregate and use an expected revision check. File uploads touched by the cashier close/handover paths are staged before the financial locks.

## Migration

New additive migration: `Modules/Shift/database/migrations/2026_10_08_000003_create_shift_report_transfer_receipts.php`.

It creates `shift_report_aggregates`, `shift_report_revisions`, `branch_manager_cash_transfers`, and `cashier_shift_handover_receipts`; adds nullable `report_revision_id` to existing cashier handovers; and adds nullable `receipt_id` plus receipt-effect uniqueness to existing custody and personal-ledger tables. It does not edit an applied migration. Rollback removes only these new tables/columns/indexes. MySQL migration execution and production table sizes have not been checked; adding indexes/constraints to transactional tables may require an approved online-DDL or maintenance plan.

## Deferred work

- **S1-09:** generic idempotency keys, canonical payload hashes, replay response recovery, and retry guarantees.
- **S1-10:** trusted physical cash count, counted-cash route integration, signed-variance adapter, and real `LiabilityEvidenceSource`.
- **S1-11:** full immutable report/correction history, public liability routes, daily-submit/reopen enforcement, and complete server-derived daily transfer membership.

No S1-10 count/receipt evidence is inferred. No S1-11 history or route work is included. The current manager approval route records manager approval/status and does not create a receipt. The manager-to-cashier command is a service primitive only; no new public endpoint is added.

## Validation limits

PHP syntax and changed-file Pint are run on the changed PHP files. Focused database tests are intended for SQLite; SQLite can verify transaction rollback and schema behavior but does not establish MySQL locking, concurrency, deadlock behavior, or production DDL impact. A full project test result is reported separately in `verification.md` only if actually run.
