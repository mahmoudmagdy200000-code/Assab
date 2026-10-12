# PKG-2 / BRC-03 — expense decisions belong to the brand owner (EXP-06, TD-2)

Local only (branch `task/pkg2-brc05-brc03-local`, baseline `341a2d1`). No Dashboard, Flutter, authentication or shared-idempotency change in this package.

## Business rule

EXP-06 / TD-2: an expense is approved or rejected by the **brand owner / brand manager** (mobile flow). The dashboard **accountant and head of accounts are not expense approvers**. Completing the day's approval by the accountant and head is a precondition for some sales-cash-to-expense-custody transfers, but it does **not** give them authority over the expense itself.

## Backend behaviour (AS-IS after this change)

| Item | Behaviour |
|---|---|
| Decision transitions on an operation with `module_key = 'expenses'` — approve, reject, rejectAs (both roles), returnForReview, finalApprove, and the three bulk variants | **409 `EXPENSE_DECISION_NOT_AUTHORIZED`** (Admin envelope `error.code`; bulk: the id appears in `failed[]` with that code and the rest of the batch proceeds). Enforced in `OperationService` on the **locked row**, before any state change, audit step, event, notification or financial write. It takes precedence over `OP_NOT_PENDING` / `OP_NOT_APPROVED` / `OP_ALREADY_FINAL`. |
| Routes affected (all reach `OperationController` → `OperationService`) | `POST /api/v1/operations/{id}/approve`, `…/reject`, `…/final-approve`, `…/return-for-review`, `POST /api/v1/operations/bulk-approve`, `…/bulk-final-approve`, `…/bulk-return`, and the `/api/v1/company/me/operations/…` head aliases. |
| Read access | Unchanged: list, show, audit-trail, attachments of an `expenses` operation. The dashboard row is a **read-only approval mirror**. |
| Brand-owner decision (mobile) | Unchanged. `ExpenseApprovalService` stays the only decision writer; `ExpenseBridgeService` still closes the mirrored operation (`final-approved` / `rejected`) when the owner decides. |
| Reverse bridge (dashboard decision → legacy expense row, PRB-05) | **Retired**: the four `BridgeExpenseDecisionToLegacy` listener registrations are removed and `ExpenseFeedbackBridgeService::syncFromOperation` fails closed. Shift and purchase bridges are untouched. |
| `requestClarification` / `document` | Not decisions; unchanged. |
| `approval_stage` field | Still present (additive contract). |

## Dashboard API change (client impact — not implemented here)

* `dashboard/…/operations.ts` (lines ≈73, 109, 196, 236, 265 per the audit): **hide approve / reject / final-approve / return-for-review / bulk actions when `moduleKey = 'expenses'`** (show-only). Until then those buttons return 409 `EXPENSE_DECISION_NOT_AUTHORIZED`.
* Map the new code to a clear message (decision belongs to the brand owner).
* **Gates deployment**: do not deploy the backend change before the Dashboard stops offering these actions.

## Historical expenses — read-only statistic (data is NOT modified)

Run on a **copy / read replica**; this session has no production data (NOT_RUN against real data). Both queries are exercised against fixtures in `ExpenseDecisionAuthorityTest::test_historical_stage_statistic_queries_count_without_modifying_data`.

```sql
-- 1) every expense that is or was in a dashboard-decided stage
SELECT approval_stage, status, COUNT(*) AS expenses, SUM(total_amount) AS total_amount
FROM expenses
WHERE deleted_at IS NULL
  AND approval_stage IN ('accountant_approved','accountant_rejected','head_approved','head_rejected','returned_to_accountant')
GROUP BY approval_stage, status
ORDER BY approval_stage, status;

-- 2) in-flight and now undecidable: still pending inside the accounting cycle
SELECT COUNT(*) AS expenses, COALESCE(SUM(total_amount), 0) AS total_amount
FROM expenses
WHERE deleted_at IS NULL
  AND status = 'pending'
  AND approval_stage IN ('accountant_approved','returned_to_accountant');
```

**Open decision (Mahmoud / Mohamed):** an expense left `pending` in `accountant_approved` or `returned_to_accountant` can no longer be decided by the dashboard (this change) **and** the brand owner is refused by `ExpenseApprovalService::assertBrandOwnerMayDecide` (accounting-cycle stage, `ExpenseApprovalService` is out of scope here — BRC-02). Query 2 sizes this population. Options (not implemented): a reviewed one-off data step resetting `approval_stage` to NULL so the owner can decide, or relaxing the owner guard in BRC-02. Historical `head_approved` expenses were approved **without any custody deduction** (PRB-05) — a reconciliation question for BRC-02, not changed here.
