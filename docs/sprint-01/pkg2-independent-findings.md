# PKG-2 — Independent-reviewer findings follow-up (IR-PKG2-01..04)

Local working tree on `task/pkg2-brc05-brc03-local` on top of `1433d81`. Business Rules v3.2. **Uncommitted; not accepted.** This pass investigates an independent reviewer's four findings, confirms/challenges each against source, and applies only the smallest safe in-scope corrections.

| Finding | Verdict | Severity | Resolution |
|---|---|---|---|
| **IR-PKG2-01** historical/export file delivery not re-authorised | **Confirmed (code-path)** | P1 | **Design-only** — `pkg2-export-delivery-security-design.md`; negative controls added; closure claim amended. No storage/architecture change (gated). |
| **IR-PKG2-02** D-2 brand-manager authority (same-email inheritance) | **Confirmed (design gap)** | P1 | **Still BLOCKED** — characterization tests added; no shared-infra change. `pkg2-d2-brand-manager-authorization-design.md`. |
| **IR-PKG2-03** `default_branch` on out-of-scope `branch_id` | **Confirmed (minor contract inconsistency)** | P2 | **Fixed** — `default_branch`/`summary.branch` derive from the effective scope → `null` when scope is empty; test added. |
| **IR-PKG2-04** coverage / migration / concurrency evidence gaps | **Partially valid** | P2 | Real-CSV content + custody-export HTTP tests added; MariaDB dry-run, RG-01, NFR flake, consumers reaffirmed as gates/NOT_RUN. |

## IR-PKG2-01 — export delivery (P1, design-only)
Report exports are written to the **public** disk and delivered by a static `asset('storage/…')` URL with no download route and no per-download authorization; export history is keyed by creator id with no revocation/scope re-check. A-1 secured the queries, aggregates and new file **content**, not file **delivery** or legacy files. The URL is *initially disclosed* only to the authenticated creator, but it is an **unauthenticated bearer link** that can be forwarded, logged or otherwise leaked (the 6 random filename chars are not access control) — so this is retention/confidentiality, not an anonymous cross-tenant read → P1 (FU-04). Negative controls: `tests/Feature/ExpenseExportSecurityTest.php` (`test_negctrl_*`). Fix is design-only because it moves storage off the public disk, adds an authenticated scoped download route, and must handle already-generated files — all gated. See the design doc.

## IR-PKG2-02 — D-2 (P1, still BLOCKED)
`MobileBranchScopeService` resolves a brand manager via identity map **or same-email** fallback to a `brand-owner` role, so a same-email manager inherits that owner's full brand set with no explicit per-manager assignment. Safe parts (unlinked → none; cross-company → isolated) and the gap (same-email inherits [A1,A2]) are pinned by `tests/Feature/BrandManagerAuthorizationCharacterizationTest.php`. The correct model changes shared `MobileBranchScopeService` → gated. Not closed.

## IR-PKG2-03 — default_branch (P2, fixed)
`BrandOwnerReportsService::getExpenseReportDetails()` derived `default_branch` from the full authorised set, so an out-of-scope `branch_id` (zero aggregates) still returned another allowed branch. Now derived from the **effective** scope (`$scopeIds`), so it is `null` when the effective scope is empty; unchanged for no-filter and in-scope requests. Test: `ExpenseReportsIsolationTest::test_client_branch_id_cannot_broaden_scope`.

## IR-PKG2-04 — coverage (P2, partially valid)
Added: `ExpenseExportSecurityTest` reads the **actual** generated CSV from the public disk and asserts only authorised rows (no cross-tenant amount/branch), exercises the custody export **HTTP** path, and covers the unlinked owner. Reaffirmed as gates/NOT_RUN: G-1 enum→string MariaDB dry-run + up/down + deploy-before-code (`pkg2-final-closure.md`, `migration-and-compatibility.md`); RG-01 two-connection MariaDB NOT_RUN; full SQLite not GREEN (the one NFR `LoadTest` parallelism timing flake — passes in isolation); Dashboard/Flutter and production preflight unverified.

## Scope held
No commit/push/merge; no shared Authentication/`MobileBranchScopeService` change; no storage migration or production cleanup; no Flutter/Dashboard change; historical financial data untouched. New changes remain uncommitted for independent review.

---
# Second follow-up (FU-01..FU-04)

| Finding | Verdict | Severity | Resolution |
|---|---|---|---|
| **FU-01** soft-deleted manager groups as "Unassigned" in branch_comparisons | **Confirmed** | P2 | **Fixed** — `baseExpenseQuery` eager-loads `branchManager` with `withTrashed()->with('branch')`; before-control proved it; regression test added. |
| **FU-02** manager-branch reassignment reattributes historic expenses across brands | **Confirmed (conditional operation exists)** | P1 | **Design-only** — `ManagerBranchSyncService` mutates `branch_managers.branch_id`; characterization test added; immutable expense-branch design proposed, not implemented (schema + backfill, gated). |
| **FU-03** G-1 migration `down()` relies on DB strict mode | **Confirmed** | P2 | **Fixed** — `down()` preflight aborts before any schema change if `brand_manager` rows exist; never coerces/deletes history; guard test added. |
| **FU-04** over-strong security wording | **Valid (doc)** | P2 | **Corrected** — export URL described as an unauthenticated bearer link; "assigned" brand-manager scope clarified (see below). |

**FU-01** — `Expense.branchManager` is a plain `belongsTo` to a soft-deleting model, so the report's eager-load excluded trashed managers while the scope subquery (correctly) included them: the money counted in the tenant total but grouped under "Unassigned". Fix loads the trashed manager + its branch so grouping resolves. Totals unchanged.

**FU-02** — `expenses.branch_manager_id` is the only link to a branch; the tenant is resolved from the manager's **current** `branch_id`. `Modules/Admin/app/Services/ManagerBranchSyncService::sync()` repoints that column (`forceFill(['branch_id' => …])->save()`) as a supported dashboard assignment. So a historic expense silently follows a reassigned manager to the new branch/brand — leaving the original brand owner's report and appearing in the destination brand owner's. Confirmed cross-**brand** within a company (the realistic path; cross-**company** would additionally require the dashboard to permit a cross-company assignment). This is a pre-existing data-model property that A-1 and the G-2 repository scoping both inherit — not introduced by A-1. The fix (an immutable branch/brand snapshot on `expenses` + scope by it + backfill of history) is a schema/data-model change touching already-reviewed G-2 scoping and requires a history backfill — **gated**. See `pkg2-expense-branch-ownership-design.md`. Characterization: `tests/Feature/ExpenseReportsHistoricalOwnershipTest::test_gap_manager_reassignment_reattributes_historic_expense_across_brands`.

**FU-03** — `tests/Feature/ExpenseTimelineMigrationRollbackGuardTest`.

**FU-04 — "assigned" clarification.** Throughout these docs and tests, a brand manager's "assigned" brands means **the brands of the dashboard `brand-owner` role matched to the manager by identity map or e-mail** (the current `MobileBranchScopeService` behaviour), **not** an explicit per-manager assignment. An explicit per-manager assignment does not exist until D-2 is implemented. Statements that a brand manager "sees only its assigned brand" are true of the fixtures (where the matched role holds exactly that brand) but must not be read as a guarantee of per-manager authorization — see `pkg2-d2-brand-manager-authorization-design.md`.
