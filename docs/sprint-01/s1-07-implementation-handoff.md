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

`DailyLiabilityGuard::assertReady(workdayId, authenticatedManager)` must run in the same database transaction as daily-submit writes, before flags/ledger effects. It is not sufficient to call it in a separate preflight transaction. The production evidence provider must lock complete scope membership and the underlying report/receipt evidence in consistent order; a receipt evidence ID must represent actual recipient confirmation for that transfer, not a request ID or approval flag.

## Explicit remaining integration

| Owner | Remaining dependency |
|---|---|
| S1-10 | Trusted signed variance from independent count/confirmed opening/pending physical cash; legacy/Admin adapters; verified company/actor identity mapping. |
| S1-11 | Current report revision source and invalidation hooks, full correction history and cashier notification, immutable receipt evidence, complete server-derived daily membership including required carry-over/incoming/outgoing transfers. |
| S1-07 integration completion | Connect commands to authenticated/scoped routes; connect guard to the actual daily-submit transaction once sources exist. Replace the fail-closed provider with the verified adapter. Test the real HTTP flow. |
| S1-08/S1-09 | MySQL locking/concurrency and all participating financial writers; independent idempotency/replay protections. |

The current aggregate is attached to a legacy cashier report, with managers supported as responsible assignees/approvers. Manager-origin reports and native Admin operations need an explicit report-identity adapter; they must not be silently omitted from a daily scope or coerced into cashier report IDs.

The default `UnavailableLiabilityEvidence` deliberately throws 409. No fake source or permissive fallback is shipped. Only tests supply synthetic evidence. Legacy daily-submit and variance routes are unchanged; their previously identified shortcomings are not claimed fixed by this internal layer. Approving this commit does not mean production rollout approval or end-to-end acceptance.

## Verification

See the latest S1-07 entry in `verification.md` for executed tests and limitations. Tests build a separate in-memory SQLite connection and small fixture tables, run the actual additive migration and services, and never migrate or erase a configured external database. Existing S1-06 tests are run as a regression check. No full-project Pint cleanup is included.

## Corrected report liability readiness

When trusted current report evidence changes from a shortage to balanced or surplus, liability readiness has no current liability requirement. Any prior allocation, approval, and employee responses remain stored as historical evidence and are not consulted as current approval. This does not bypass the daily guard's independent required receipt checks or any scope/authorization/evidence checks. If a later correction changes the report back to shortage, the old allocation cannot match the new revision/amount; a fresh allocation and manager approval are required. Manager-origin correction still does not require cashier reconfirmation.
