# F-01 — Expense custody lifecycle (EXP-01…EXP-08, CASH-17, AC-27…AC-31, AC-39)

Status labels: **AS-IS** (what the repository did at the Candidate `3c85fd97`), **IMPLEMENTED** (this package),
**ASSUMPTION** (needs Mahmoud/Mohamed confirmation), **NOT IMPLEMENTED**.

## 1. AS-IS defect (SOURCE_INSPECTION_ONLY + REVIEWER_EXECUTED RED)

* The only writer was `CreateCustodyTransactionFromExpenseApproval` (queued, `afterCommit`, bound to
  `ExpenseApprovedEvent` and also auto-discovered). It posted `min(amount, max(branchBalance, 0))` as a
  `custody_transactions` "Expenses Deduction" and the **remainder as a `personal_ledger_transactions`
  "Expenses Deduction"** — i.e. an expense larger than the custody silently debited the manager's sales cash.
* Nothing happened at submission, nothing at rejection.
* `CustodyBalanceService::getAvailableExpenseBalance` = custody **+ personal ledger**, and the four controllers
  used it as the "Insufficient custody balance" pre-check, so custody 300 + sales cash 400 let an expense of 500 pass.
* RED evidence: `scratchpad/cand/out/evidence/f01_red.txt` (24 tests, 16 failures on the unmodified Candidate).

## 2. IMPLEMENTED design (no migration, no schema change)

| Rule | Implementation |
|---|---|
| EXP-01 / CASH-17 source | `ExpenseCustodyService` writes **only** `custody_transactions` (branch custody of the expense's manager + origin branch). The personal ledger is never read or written for an expense. `getAvailableExpenseBalance` now returns the branch custody only. |
| EXP-02 deduct at submission | `reserve()` is called inside the submission unit of work: non-draft create (quick-cash, single-invoice, grouped-invoice, pre-approval), draft→pending through any `update`, `POST /{id}/submit`, `POST /{id}/resubmit`. One cash-out row `type='Expenses Deduction'`, `is_cash_in=false`, `related_expense_id`. |
| EXP-02 approval does not deduct again | `approveExpense` calls the same idempotent `reserve()`; when the expense is already charged it posts nothing. |
| EXP-03 rejection reverses once | `reverse()` posts one cash-in row of the same type/manager/branch for the **outstanding** amount, in the same transaction as the status change and the audit row. |
| EXP-04 keep evidence | original + reversal rows are both kept; the rejected expense and its reason are kept; nothing re-adds the balance when cash is recovered outside the app. |
| EXP-05 insufficient custody | `reserve()` throws `InsufficientExpenseCustodyException` (HTTP 400, message `Insufficient custody balance`, `errors.custody_balance`) → nothing is written, the draft stays a draft, a blocked create persists nothing. Compared in integer halalas. |
| EXP-07 first valid decision | approve/reject/resubmit/submit lock the expense row (`SELECT … FOR UPDATE` on MySQL) and re-check the status on the fresh row. The manager row is locked while custody is read + charged, serializing concurrent submissions against the same custody. SQLite has no row locks (single writer); the true two-connection race is **NOT RUN** (MySQL gate). |
| EXP-08 corrected resubmission | rejection reversed the first charge, so `reserve()` at resubmit charges the **current** `total_amount` once; a retried resubmit is refused by the status check. The previous cycle (timeline rows, rejection evidence, both movements) is preserved. |
| Idempotency | outstanding = Σ(cash-out) − Σ(cash-in) of the expense's rows. `reserve()` is a no-op if outstanding > 0, `reverse()` a no-op if outstanding = 0 → retries cannot duplicate a financial effect. |
| Atomicity | create/update services wrap the whole persistence in `DB::transaction`; the approval service wraps each decision. Events (notifications, ASAB mirror) fire after the decision transaction. |
| Legacy listener | `CreateCustodyTransactionFromExpenseApproval` is **deleted** and its mapping removed (it would also have been auto-discovered). |
| Reports | `CustodyBalanceService::sumCustodyAndExpenses` (branch trends) now nets the reversing cash-in rows against the deductions. |

## 3. Cut-over behavior (ASSUMPTION — flagged)

An expense that was submitted **before** this change and is still `pending` was never charged. Approving it
charges the branch custody once at approval (same idempotent `reserve()`); if the custody does not cover it,
the approval is refused with `Insufficient custody balance` instead of spilling into sales cash. Rejecting it
posts no reversal (nothing to reverse). Legacy `approved` expenses keep their old rows untouched.

## 4. Open decisions / limitations (not guessed)

1. **Which payment methods are "paid from custody"**: scope kept as in the legacy listener —
   `payment_method = 'custody'` only. `cash` / `supplier` expenses never touch custody. EXP-01 says a cash
   expense must not come from the cashier drawer or sales cash; whether `cash` should be charged to the
   branch custody too is a business question for Mohamed.
2. **Pre-approval requests** (`expense_type = pre_approval`, amount = `estimated_amount`) are charged like any
   other custody-paid expense (AS-IS also charged them at approval). Whether a *request for permission* should
   reserve custody is a business question.
3. **Custody scope** = (manager, origin branch) as `getCustodyBalance($manager, $branch)` already defined it; the
   branch-wide pot (`getBranchCustodyBalance`) sums every manager of the branch. Single manager per branch is assumed.
4. **Corrected amount (AC-30)**: the repository has **no endpoint that edits a rejected expense** (updates are
   draft-only). The service charges the corrected amount whenever the record carries one at resubmission; the
   test applies the correction on the record. An API path to correct a rejected expense is NOT IMPLEMENTED
   (product decision) → AC-30 is PARTIAL by design.
5. **Client display**: the reversal row is a `custody_transactions` row of type `Expenses Deduction` with
   `isCashIn = true` (the ENUM allows no new type without a migration, which is not authorized). Clients that list
   by type show it as `+amount` linked to the same expense id.
6. **`/custody-balance` field `balance`** now equals the branch custody (it used to add sales cash). This is the
   required EXP-01/CASH-17 semantic and is called out as a deliberate contract value change.
7. **Concurrency (AC-31 simultaneous)**: row locking is implemented but only provable on MySQL 8.4 → `NOT_RUN`.
