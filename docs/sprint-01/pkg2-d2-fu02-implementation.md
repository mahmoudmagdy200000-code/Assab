# PKG-2 — D-2 + FU-02 implementation

Local, uncommitted, on `task/pkg2-brc05-brc03-local` @ `e57751b`. Business Rules v3.2. Implements the two confirmed P1 findings as code+tests (superseding their design-only status). No commit/push/merge; no Dashboard/Flutter/historical-financial-data change.

## D-2 — explicit brand-manager authorization

**Model.** A brand manager is authorised only by an explicit, per-manager delegation: an `AsabIdentityMap` row of entity `brand_manager` (unique per manager) → dashboard `AsabUser` → an `AsabUserRole(role_key = 'brand-manager')` whose `brand_ids` belong to that user's company. The mobile `BrandManager extends BrandOwner`, so `MobileBranchScopeService::visibleBranchIds()` now tests `instanceof BrandManager` **before** `BrandOwner` and resolves managers through `brandIdsForManager()` — which has **no** same-email/owner fallback.

| Invariant | Where / how |
|---|---|
| D2-INV-01 explicit identity | `brandIdsForManager`: resolves via `ENTITY_BRAND_MANAGER` identity map only; e-mail match grants nothing. |
| D2-INV-02 explicit brand set | brands come from the manager's own `brand-manager` role `brand_ids`, never an owner's set. |
| D2-INV-03 owner delegation | `BrandManagerAssignmentService` grants/changes/revokes; the WHO-may-grant rule is left to the owner (admin command only, no public endpoint). |
| D2-INV-04 immediate revocation | resolution is live per request; `revoke()` deletes map+role → next request resolves `[]`. |
| D2-INV-05 tenant isolation | company-consistency filter: only brands with `company_id == user.company_id`. |
| D2-INV-06 fail closed | missing map / missing role / empty set / cross-company → `[]`; unique index blocks duplicate maps. |
| D2-INV-07 owner compatibility | BrandOwner / BranchManager / Cashier paths untouched. |
| D2-INV-08 consistent model | lists, details, timeline and decisions all go through the same `MobileBranchScopeService` → `ExpenseBrandScopeService` → `ExpenseRepository` scope; no UUID bypass. |

**Provisioning & audit.** `BrandManagerAssignmentService::grant/revoke` write the identity map + role and append an immutable row to `asab_brand_manager_assignment_audit` (grant/change/revoke, actor, brand set, timestamp) — never overwritten when the set changes. Admin command `asab:brand-manager grant|revoke`. Legacy managers relying on the retired same-email fallback are surfaced (read-only) by `asab:pkg2-census` and fail closed until granted.

## FU-02 — immutable historical expense ownership

**Model.** `expenses` gains `origin_branch_id`, `origin_asab_brand_id`, `origin_asab_company_id` (additive, nullable, no FK so the snapshot survives branch changes). The `Expense` model `creating` hook stamps them once from the branch_manager's branch at creation. They are **not fillable** (server-derived only) and are never restamped on update.

**Writer coverage (R-04, accurate claim).** The `creating` hook fires for every **Eloquent** create — `Expense::create(...)` / `new Expense; save()` — which is how *all* current writers build an expense (QuickCash / SingleInvoice / GroupedInvoice / PreApproval / drafts / resubmits / factory / seeders). An audit of the codebase found **no raw `DB::table('expenses')->insert(...)` or query-builder bulk insert** anywhere (the only `DB::table('expenses')` uses are the read-only census SELECTs), so no current writer bypasses the stamp. The limitation is stated honestly: a *future* raw query-builder insert would not fire the hook, and `$fillable` protects against mass-assignment, not against a direct DB write. If such a writer is ever added it must stamp origin explicitly (or be routed through the model). This is a maintenance invariant, not a proven present gap.

**Unresolved / partial origin (policy).** If the branch_manager's branch is not linked to the dashboard hierarchy at creation (no `asab_brand_id`, no restaurant), the hook still stamps `origin_branch_id` but leaves `origin_asab_brand_id` / `origin_asab_company_id` NULL. Such a row is **brand-invisible** (brand readers key on origin brand) and fail-closed. Creation is deliberately **not** hard-rejected for an unresolved origin: legitimate unbranded / not-yet-linked branches must still be able to record expenses, and blocking them would be a functional regression. Instead these rows are surfaced by `asab:pkg2-census` (`fu02_expenses_partial_origin` / `fu02_expenses_without_origin`) for an explicit, separately-approved remediation. Whether to additionally hard-reject unresolved-origin creation is a business decision deferred to Mahmoud/Mohamed, not taken unilaterally here.

| Invariant | Where / how |
|---|---|
| FU02-INV-01 original ownership | `creating` hook captures branch/brand/company at creation. |
| FU02-INV-02 immutable history | hook fires on `creating` only; updates never change origin. |
| FU02-INV-03 correct reporting (R-01) | brand actors scope by the immutable `origin_asab_brand_id` (against their current authorised brands); branch-level managers scope by `origin_branch_id` (own branch). Per-branch comparison still GROUPS by `origin_branch_id` within that scope. |
| FU02-INV-04 cross-company isolation | origin company stored; authorised branch set is per-company, so a cross-company origin is never in scope. |
| FU02-INV-05 no financial rewrite | only metadata columns added; amounts/status/approvals/custody/ledger untouched. |
| FU02-INV-06 source of truth | reads scope by origin, not the manager's current branch. |
| FU02-INV-07 immutable across lifecycle | reject/approve/correct/resubmit/attach never restamp (hook is create-only). |

**Readers changed (R-01 — immutable origin BRAND is the authority):** every brand-actor expense reader now keys on `origin_asab_brand_id` against the actor's current authorised brands, resolved via `ExpenseBrandScopeService::brandIdsFor` → `MobileBranchScopeService::visibleBrandIds`:
- `ExpenseRepository::inBrandScope` (list / detail / recent / summary / approval / decision) — renamed from `inBranchScope`; filters `origin_asab_brand_id`.
- `BrandOwnerReportsService` (details, branch comparisons, exports, quick summary) — brand actors keyed on origin brand; branch-level managers keyed on `origin_branch_id` (own branch). Custody stays branch-current.
- `BrandOwnerHomeService` (dashboard summaries + trend charts) — was still keyed on the manager's **current** branch (`whereHas(branchManager.branch_id)`), the worst form of the FU-02 defect; now keyed on origin brand (+ selected-branch `origin_branch_id` narrowing).

This closes the branch-RE-TAG gap R-01 identified: moving a branch to another brand (changing `branches.asab_brand_id`) can no longer transfer that branch's historic expenses to the new brand nor hide them from the original owner, because the authority is each expense's immutable origin brand, not any branch's current brand association. Custody queries stay branch-current (operational cash, not expense ownership).

**Known follow-up (out of this pass):** `Modules\BrandOwner\Services\Financial\FinancialDataService::expenseQuery()` / `supplierObligations()` still key expenses on the manager's current branch (`whereHas(branchManager.branch_id)`). It is a distinct financial-statements surface with its own single-branch authorization model, so it is flagged for a separate scoped pass rather than changed here (SOURCE_INSPECTION_ONLY).

**Concurrency.** The origin is captured at the instant the expense row is inserted (the `creating` hook reads the manager's branch then). That is the linearisation point: an expense belongs to the branch its manager had when the creation transaction committed. `ManagerBranchSyncService`'s lock order was left unchanged (no cross-table lock added, to avoid a lock-order inversion); a move committed after the insert cannot alter an already-stamped origin.

**Legacy / backfill.** Rows created before the migration have NULL origin and are fail-closed (not attributed to any brand). They are **not** backfilled from the manager's current branch (that would reinstate the defect). `asab:pkg2-census` inventories them for an explicit, separately-approved backfill. On a fresh DB (incl. the test suite) every expense is created through the hook, so no NULL-origin rows arise.

## Acceptance matrix → tests

| Case | Test |
|---|---|
| D2-01 assigned manager accesses brand A | `ExpenseTenantIsolationTest`, `BrandManagerAuthorizationCharacterizationTest::test_explicitly_assigned_manager_resolves_only_delegated_brand` |
| D2-02 same-email, no assignment → nothing | `…::test_same_email_match_to_owner_role_grants_nothing` |
| D2-03 assigned A requests B → denied | `ExpenseTenantIsolationTest` (cross-brand), `ExpenseCombinedAuthorizationTest::combined02` |
| D2-04 A+B → only A+B | `…::test_multi_brand_delegation_resolves_exactly_the_delegated_set` |
| D2-05 revoked → denied next request | `…::test_revoked_assignment_denies_on_next_resolution`, `ExpenseCombinedAuthorizationTest::combined03` |
| D2-06 missing/ambiguous → fail closed | `…::test_unlinked_brand_manager_fails_closed` |
| D2-07 cross-company brand → dropped | `…::test_cross_company_brand_in_role_is_filtered_out`; `BrandManagerAssignmentServiceTest::test_grant_refuses_cross_company_brand` |
| D2-08 real owner unchanged | `…::test_brand_owner_resolution_is_unchanged` |
| D2-09 authorized manager approves | `ExpenseTenantIsolationTest` manager approve/reject |
| D2-10 unauthorized manager by UUID → denied, zero writes | `ExpenseCombinedAuthorizationTest::combined02` |
| FU02-01/03/04 move A1→A2 keeps origin | `ExpenseReportsHistoricalOwnershipTest::test_manager_reassignment_does_not_move_historic_expense_ownership` |
| FU02-02 real-ish sync (forceFill as sync does) | same + `test_origin_columns_are_stamped_at_creation_and_not_restamped_on_update` |
| FU02-05 no financial rewrite | origin columns only; zero-write assertions in isolation/combined tests |
| FU02-06 soft-deleted manager | `ExpenseReportsHistoricalOwnershipTest::test_soft_deleted_manager_expense_groups_under_its_real_branch` |
| FU02-07 reject/resubmit immutable | `…::test_origin_columns_are_stamped_at_creation_and_not_restamped_on_update` |
| FU02-09 CSV export origin isolation | `ExpenseExportSecurityTest` |
| FU02-10 ambiguous legacy → no fallback | fail-closed reader + `asab:pkg2-census` |
| COMBINED-01..04 | `ExpenseCombinedAuthorizationTest` |
| **R-01** branch re-tag keeps original brand ownership (reports + approval list + home) | `ExpenseReportsHistoricalOwnershipTest::test_branch_retag_keeps_expense_with_its_original_brand_owner` (failing-before / passing-after) |
| **R-02** revoke→re-grant reactivates soft-deleted map; repeated revoke; dashboard-user reassignment; inactive-user refused | `BrandManagerAssignmentServiceTest::test_revoke_then_regrant_…`, `…::test_repeated_revoke_is_idempotent_and_safe`, `…::test_reassigning_dashboard_user_…`, `…::test_grant_refuses_an_inactive_dashboard_user` |
| **R-03** resolver denies disabled user / company mismatch / malformed map type | `BrandManagerAuthorizationCharacterizationTest::test_resolver_denies_*` |
| **R-04** origin-ownership + audit migration `down()` refuse a destructive rollback | `Pkg2MigrationRollbackGuardTest` |
| **R-04** census flags partial origin + ineffective delegation | `Pkg2OwnershipCensusCommandTest::test_census_flags_partial_origin_and_ineffective_delegation` |

## R-04 rollback & preflight hardening
- `2026_10_13_000001_add_origin_ownership_to_expenses` and `2026_10_13_000002_create_asab_brand_manager_assignment_audit` now **refuse** a `down()` that would destroy live data (populated immutable origin / append-only audit rows) — throw before any schema change; an empty DB still rolls back cleanly. Forward-fix instead of rollback once in use.
- `asab:pkg2-census` strengthened: adds `fu02_expenses_partial_origin` (origin branch stamped, origin brand/company NULL → brand-invisible) and `d2_brand_managers_ineffective_delegation` (a live map that resolves to NO brands — disabled user, no/empty role, cross-company, or orphaned/soft-deleted manager), each with its own warning.

## Open gates (unchanged)
- MariaDB up/down dry-run for both new migrations, and RG-01 two-connection concurrency: **NOT_RUN** (no MariaDB). SQLite only.
- Grantor-authority for a public delegation endpoint: owner decision (safe admin-command core delivered).
- Legacy NULL-origin expense backfill and legacy-manager grants: explicit, separately-approved remediation (census provided).
- `FinancialDataService` current-branch expense keying: flagged for a separate scoped pass (SOURCE_INSPECTION_ONLY), not changed here.
- IR-01 export delivery (P1) still design-only. Dashboard/Flutter unverified. No commit/push/merge.
