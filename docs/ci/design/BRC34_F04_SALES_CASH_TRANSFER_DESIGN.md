# F-04 — Sales cash → branch expense custody (FUND-01, FUND-02; AC-53…AC-56)

Labels: **AS-IS** (Candidate 3c85fd97), **IMPLEMENTED** (this package), **ASSUMPTION** (needs Mohamed/Mahmoud), **NOT IMPLEMENTED**.

## AS-IS (SOURCE_INSPECTION_ONLY + REVIEWER_EXECUTED RED, evidence/f04_red.txt: 12/15 failing)
* `POST /api/custody/transfer` compared the amount with the raw sum of **every** personal-ledger row (uncollected
  shortage claims included), deducted no reservation, checked no approval of the source day, had no idempotency.
* `isFullTransfer` with a zero balance posted zero-amount rows.
* `newCustodyBalance` was computed *after* the custody row was posted and then the amount was added again → overstated by the transfer.

## IMPLEMENTED (no migration, no schema change)
| Rule | Implementation |
|---|---|
| FUND-01 | Cash credited from a manager workday is releasable only if `ShiftReportReviewService::dayFullyFinalApproved(day)`: ≥1 active daily-lock member and **every** member report operation is `final-approved` (accountant approval alone → still held back). A credit with no `related_shift_id`, or a day that is not the manager's own, cannot prove FUND-01 and is held back (fail closed). |
| FUND-02 | Ceiling = `ShiftTransferReceiptService::availableManagerCashMinor` (executed ledger movements once; uncollected `Variance from Cashier`/liability credits excluded; open manager→cashier requests deducted once, released when executed) − cash from unapproved days. All in whole halalas. |
| Atomicity / concurrency | One transaction under `lockManagerCashOwner` (same mutex as the manager→cashier requests). Custody cash-in + personal-ledger cash-out are posted together or not at all. |
| Idempotency | Route middleware `asab.idempotency:optional,transaction,legacy` (the project's durable mechanism): same key + same payload replays the first answer, same key + different payload → 409, no key → legacy behaviour. |
| Errors | 400 `errors.code=INVALID_AMOUNT` kept; additive `reason` (`SOURCE_DAY_NOT_FULLY_APPROVED` \| `EXCEEDS_AVAILABLE_CASH`), `maxAmount` (now the transferable ceiling), `availableAmount`, `unapprovedAmount`. |
| Input | Sub-halala amounts → 422 (never rounded; AC-46 rounding rule is not approved). |
| Display bug | `newCustodyBalance` is now the balance after the transfer (was overstated by the amount). |

## ASSUMPTIONS / open decisions
1. "Source day fully approved" ≙ every member report of the manager workday is head-final (Mohamed to confirm the day-level definition).
2. A ledger credit that names no source day is **held back** (legacy rows without `related_shift_id` cannot be proven approved).
3. Debits are assumed to consume approved cash first (conservative: `transferable = available − unapprovedCredits`).
4. Mobile `transferAmount` with >2 decimals now fails (previously accepted and rounded by the DB).
5. Pre-existing regression `ng09_branch_manager_transfer_regression` pinned the old "any seeded balance is transferable" behaviour; it was changed deliberately.

## NOT RUN
* MySQL 8.4 two-connection race of two simultaneous transfers (the owner lock is implemented; SQLite has no row locks).
