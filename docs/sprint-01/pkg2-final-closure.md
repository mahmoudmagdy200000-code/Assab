# PKG-2 Final Closure — A-1, G-1 completion, dead-code, D-2 status

Local working tree on `task/pkg2-brc05-brc03-local`, on top of the committed EXP-06 slice `1433d81` (which sits on BRC-03 `1a541b2` → BRC-05 `0ef5ef9` → PKG-1 `341a2d1`). Business Rules v3.2. **Not committed; not accepted.** Independent review required before any further commit.

## A-1 — Expense/Custody reports tenant isolation (P0, fixed)

`BrandOwnerReportsController` + `BrandOwnerReportsService` aggregated every report over **all** branches: a brand owner, a brand manager, and even an unlinked actor received global totals (own 100 + other tenant 7,777 = 7,877). The brand-level `BranchManager` path was scoped, but the brand-actor path (`BrandOwner`, and `BrandManager extends BrandOwner`) was not.

Fix: the controller resolves the authorised branch set server-side — `MobileBranchScopeService::visibleBranchIds()` for a brand owner/manager, the own branch for a branch-level manager — and the service confines **every** query (summary, payment methods, suppliers, ratios, branch comparisons, cash-transfer log, custody per-branch balances, quick summary, default branch, and the export file built from them) to that set **inside the DB query, before aggregation**. A client `branch_id` can only narrow within the scope; an empty scope matches nothing (`whereIn … []` → `0=1`), so an unlinked/unassigned actor fails closed. The brand-level manager's own-branch behaviour is unchanged.

Invariants met: scope in-query before aggregation; client `branch_id` narrows only; no trust of client company/brand/branch; unassigned fail closed; brand manager cannot reach unassigned brands; no leak through totals/counts/charts/details/filters/exports/metadata; decimal/halala math unchanged; branch-manager own-branch unchanged; empty/other-tenant ids are non-disclosing (zeros / empty branch list).

## G-1 — Brand manager timeline attribution (completed)

`expense_timelines.performed_by_type` was `enum('branch_manager','brand_owner','system')` — on MySQL/MariaDB a brand-manager write is rejected (SQLSTATE 1265), and on SQLite the enum is a `varchar` + CHECK that rejects it too. Additive migration `2026_10_12_120000_change_expense_timelines_performed_by_type_to_string` widens the column to `string(32)` (mirrors the proven precedent `2026_07_30_000002_extend_personal_ledger_transactions_for_expenses`). The PHP enum gains `BRAND_MANAGER`; `ExpenseApprovalService` writes `brand_manager` vs `brand_owner` from the decided role; `UnifiedTimelineResource` and `ExpenseTimelineResource` resolve a `brand_manager` row to the actual manager's name/image. Existing rows are not rewritten.

**Deploy ordering:** run the migration before deploying the code that writes `brand_manager`. On a large `expense_timelines` table the MySQL `MODIFY COLUMN` takes a brief metadata/table lock — schedule it in a window. **Rollback limitation:** `down()` restores the 3-value enum and is only safe while no row holds `brand_manager` (no historical rewrite is performed). **MariaDB:** the change is proven on SQLite (the full suite runs the migration); a disposable-MariaDB dry-run of the `MODIFY COLUMN` remains a deploy gate (no MariaDB was available here).

## Dead-code — reverse expense-decision bridge (removed)

BRC-03 retired the dashboard→legacy expense write-back. `ExpenseFeedbackBridgeService::syncFromOperation()` already failed closed with a large unreachable body; the body and its private helpers are removed and the method is now an explicit, tested fail-closed no-op (kept because `TwoWorldFeedbackLoopTest`, `ExpenseApprovalChainTest`, `ExpenseDecisionAuthorityTest` call it and assert no write). The unregistered no-op listener `BridgeExpenseDecisionToLegacy` is deleted (not in the `$listen` map; only auto-discovered as a no-op). Forward (read) bridge `SyncLegacyExpenseOperation`, the Purchase and Shift bridges, and all events consumed elsewhere are untouched.

## D-2 — Brand manager authorization model (BLOCKED — design only)

Brand-manager scope is currently resolved by `MobileBranchScopeService` via the identity map **or same-email** fall-back to a dashboard `brand-owner` role. Same-email equality is not an acceptable permanent authority model. A proper fix (explicit identity-map entity `brand_manager` + a dedicated `brand-manager` role carrying authorised `brand_ids`, with company/brand consistency and fail-closed revoke/duplicate handling) requires changing the **shared** `MobileBranchScopeService` used by cashier/branch-manager/brand-owner pickers across modules. That hits the design gate: see `pkg2-d2-brand-manager-authorization-design.md`. **Not implemented.** Until it lands, a brand manager without a same-email dashboard `brand-owner` link fails closed (no access) — safe, but not the intended authority model.

## Not in scope / unchanged

Historical `accountant_approved` / `returned_to_accountant` / `head_approved` expenses, custody balances and ledger transactions are untouched (owned by BRC-02). No mass `approval_stage` reset, no retroactive debit, no financial-history migration. SAR-decimal vs halala boundary is unchanged. Shared Authentication and Idempotency infrastructure unchanged. No Flutter / Dashboard code change.
