# S1-07 implementation handoff — APPROVE A

Date: 2026-10-08. Starting SHA: `217c271659d6a5c28a52efa4e2d266577138904f`. Integrated onto `sprint/01-financial-foundation` by cherry-pick on top of `c18c2f23` (2026-10-08); original commit `ea5e8d90` on `codex/s1-07-liability`.

**Status: internal liability model/services READY FOR REVIEW; S1-07 NOT ACCEPTED and NOT complete end-to-end.**

## Delivered

- Additive migration: allocation snapshots plus typed employee shares; existing cashier-only legacy fields and API contracts untouched.
- Existing actor resolution with company/branch isolation and explicit type allowlist.
- Exact complete shortage allocation; positive surplus/zero never becomes employee liability.
- Original cashier confirmation, separate employee accept/object with reason/time, separate manager final approval with explicit self-share approval.
- New allocation versions preserve previous amounts/shares/approval/objection, reset current approval, and reject stale report/amount/version decisions. Manager correction bypasses cashier reconfirmation and requires a reason.
- Transaction-required daily guard combines complete current liability approval with confirmed receipt evidence in the server-defined workday set.
- No ledger entries, payroll deduction, handover mutation, Flutter changes, or Dashboard changes.

## Calling contract (internal, not HTTP)

`ShiftLiabilityService::allocate(shiftId, serverResolvedActor, shares, expectedVersion, cashierConfirmed, reason)` consumes `{type,id,amount}` shares; `amount` is an integer number of halalas. It resolves current financial/report evidence server-side. `confirm`, `respond`, and `approve` are independent commands. Commands revalidate actor scope and current allocation version; response choices are `accepted`/`objected`.

`DailyLiabilityGuard::lockSubmittedDay(workdayId, authenticatedManager)` (which runs `assertReady` and then locks the day's liability) must run in the same database transaction as daily-submit writes, before flags/ledger effects. It is not sufficient to call it in a separate preflight transaction. The production evidence provider must lock complete scope membership and the underlying report/receipt evidence in consistent order; a receipt evidence ID must represent actual recipient confirmation for that transfer, not a request ID or approval flag.

## Explicit remaining integration

| Owner | Remaining dependency |
|---|---|
| S1-10 | Trusted signed variance from independent count/confirmed opening/pending physical cash; legacy/Admin adapters; verified company/actor identity mapping. |
| S1-11 | Current report revision source and invalidation hooks, full correction history and cashier notification, immutable receipt evidence, complete server-derived daily membership including required carry-over/incoming/outgoing transfers. |
| ~~S1-07 integration completion~~ | Superseded 2026-10-08: re-sequenced to S1-08/S1-10/S1-11 (see "Completion" below). |
| S1-08/S1-09 | MySQL locking/concurrency and all participating financial writers; independent idempotency/replay protections. |

The current aggregate is attached to a legacy cashier report, with managers supported as responsible assignees/approvers. Manager-origin reports and native Admin operations need an explicit report-identity adapter; they must not be silently omitted from a daily scope or coerced into cashier report IDs.

The default `UnavailableLiabilityEvidence` deliberately throws 409. No fake source or permissive fallback is shipped. Only tests supply synthetic evidence. Legacy daily-submit and variance routes are unchanged; their previously identified shortcomings are not claimed fixed by this internal layer. Approving this commit does not mean production rollout approval or end-to-end acceptance.

## Verification

See the latest S1-07 entry in `verification.md` for executed tests and limitations. Tests build a separate in-memory SQLite connection and small fixture tables, run the actual additive migration and services, and never migrate or erase a configured external database. Existing S1-06 tests are run as a regression check. No full-project Pint cleanup is included.

## Corrected report liability readiness

When trusted current report evidence changes from a shortage to balanced or surplus, liability readiness has no current liability requirement. Any prior allocation, approval, and employee responses remain stored as historical evidence and are not consulted as current approval. This does not bypass the daily guard's independent required receipt checks or any scope/authorization/evidence checks. If a later correction changes the report back to shortage, the old allocation cannot match the new revision/amount; a fresh allocation and manager approval are required. Manager-origin correction still does not require cashier reconfirmation.

## Completion — 2026-10-08 (internal layer closed)

Mahmoud decided on 2026-10-08 to close S1-07 as the internal liability layer and to move route integration to the tasks that own its missing evidence. Added on top of `7fd1d15c`:

- **Sales-channel check (S1-07 "separate channel validation from cash comparison").** `ShiftFinancialCalculator::salesChannelCheck(gross, cards, apps, reportedCashSales?)`: cards + apps may not exceed gross; the cash-sales channel is derived; a reported cash-sales difference is a channel (data-entry) difference and never a shortage or surplus. Cash variance comes only from `calculate()` (counted vs expected).
- **Daily-submit lock.** `DailyLiabilityGuard::lockSubmittedDay(workdayId, manager)` runs the readiness checks and records one active `shift_liability_daily_locks` row per report in the day, in the submit transaction. While a report is locked, `allocate`, `confirm` and `approve` return 409 `LIABILITY_LOCKED_BY_DAILY_SUBMIT`; `respond` stays open (an objection is evidence, BR-10). `DailyLiabilityGuard::releaseDay(workdayId, manager, reason)` is the reopen step: only the workday manager, reason required, rows released (actor, time, reason) and never deleted.
- **No allocation for balanced or surplus reports (audit A1).** `allocate` returns 409 `NO_SHORTAGE_LIABILITY` when the current trusted variance is zero or positive, so no empty allocation record is written. Older shortage allocations stay as history and readiness ignores them (`a946b1f4`).
- **Carry-over.** Locks are kept per (report, workday): a report included in two submitted days stays locked until both are reopened.
- **Real-schema verification.** `ShiftLiabilityRealSchemaTest` runs the layer from the container against the real migrated schema and models (company/brand/branch, cashiers, branch manager, Admin employee): FIN-02 (no/partial allocation), FIN-03/10 (12 + 8 confirmed, in-branch only), FIN-11 (explicit self-share; objection recorded and not blocking), FIN-04/05 (surplus/zero need no employee, allocation or approval), employee acceptance with timestamp and no overwrite, lock → reopen → correction → resubmit, and a carried-over report locked by two days.

**Integration owners (re-sequenced, Mahmoud 2026-10-08):**

| Owner | Work |
|---|---|
| S1-08 | Receipt identity linked to the receiving shift (confirmed opening = Σ confirmed receipts, D2), manager → cashier transfer command, report revision identity; atomic report + allocation in the end/submit transaction. |
| S1-10 | `counted_cash` on the legacy end routes and the persisted signed variance; real `LiabilityEvidenceSource` adapter replacing `UnavailableLiabilityEvidence`; enforcement without a flag per D4 (ships with a compatible AssabAPP release). |
| S1-11 | Allocation/confirm/respond/approve routes with company/branch scope; wire `lockSubmittedDay` into `daily-close/submit` and `releaseDay` into `daily-close/reopen`; retire the legacy `recordVariance` defaults (auto current-cashier share, unassigned `other_factors` remainder, surplus responsibility rows, shared status column). |
| S1-15 | Route-level FIN-02/03/04/05/10/11 evidence. |

MySQL locking and concurrency for these commands (lock order, gap locks, deadlock retry) are **NOT RUN** — all evidence is SQLite; they belong to S1-08/S1-11 with the real wiring.
