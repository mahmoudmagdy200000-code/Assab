# FU-02 — Immutable expense branch/brand ownership (DESIGN ONLY, not implemented)

Status: **CONFIRMED conditional (P1) · design-only.** The triggering operation exists; the fix is a schema + data-model change touching the already-reviewed G-2 scoping and requires a history backfill, so it is gated. No code or migration for this shipped.

## The gap (verified)
- `expenses` stores `branch_manager_id` only (no own branch/brand/company column). Tenant is resolved as `expenses.branch_manager_id → branch_managers.branch_id → branches.asab_brand_id/asab_restaurant_id/asab_company_id`.
- `branch_managers.branch_id` is **mutable**: `Modules/Admin/app/Services/ManagerBranchSyncService::sync()` does `$manager->forceFill(['branch_id' => $branchId])->save()` as part of the supported dashboard "assign manager to branch" flow (meeting 2026-08-03).
- Therefore a historic expense's tenant **follows its manager** on reassignment. Demonstrated (cross-brand, same company) in `ExpenseReportsHistoricalOwnershipTest::test_gap_manager_reassignment_reattributes_historic_expense_across_brands`: after A1→A2 repoint, brand A1's owner loses the 100 and brand A2's owner gains it.
- Scope: this affects A-1 reports, the G-2 `ExpenseRepository` scoping, and every pre-existing per-manager expense query — all resolve via the manager's current branch. It is a pre-existing property, not introduced by A-1.
- Realistic blast radius: cross-**brand within a company** (different brand owners). Cross-**company** additionally needs the dashboard to allow assigning a manager to another company's branch; the dashboard scopes users to their company, so that is likely blocked there — to be confirmed in the dashboard authorization review.

## Why not fixed in this slice
The correct fix changes the expense tenant model and must backfill history:
1. **Schema (additive):** add nullable `asab_brand_id` (and/or `branch_id`, `asab_company_id`) to `expenses`, stamped at creation from the manager's branch at that moment — an immutable ownership snapshot.
2. **Writers:** set the snapshot on expense creation (QuickCash/Invoice/Grouped/PreApproval services).
3. **Readers:** scope A-1 reports and the G-2 `ExpenseRepository` by the snapshot instead of the manager's current branch. This modifies **already-reviewed G-2 code**.
4. **Backfill (history):** existing rows have no snapshot; backfilling from the manager's *current* branch is **wrong for already-moved managers** and unrecoverable where the old branch is lost. A correct backfill needs an audit source for each expense's original branch, or an explicit business decision to accept current-branch attribution for pre-existing rows. This is exactly the "no history migration without approval" boundary.

All four cross the authorized minimal-local scope (schema, core scoping change, history backfill), so they are a **release-blocking design decision** for Mahmoud + Mohamed, not an in-scope patch.

## Interim posture
Until this lands, manager reassignment via `ManagerBranchSyncService` moves historic expense attribution with the manager. Operationally, treat a cross-brand/cross-company manager reassignment as a reporting-impacting event. The G-2 **decision** authority (who may approve/reject) is unaffected — this is about which brand owner's **reports** a historic expense appears in.

## Tests to add with the fix
- Expense keeps its original brand after its manager is reassigned to another brand (snapshot-based scope).
- No cross-company exposure after reassignment.
- Backfill correctness per the approved policy.
