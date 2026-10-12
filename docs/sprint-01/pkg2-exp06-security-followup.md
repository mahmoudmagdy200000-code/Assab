# PKG-2 security follow-up — EXP-06 tenant isolation and decision attribution

Local only (working tree on `task/pkg2-brc05-brc03-local`, on top of `1a541b2`). Business Rules v3.2 EXP-06: the brand owner and an authorized brand manager decide expenses within their assigned scope; accountants/heads and branch managers do not.

## Scope source (AS-IS, verified in source)
`brand_owners` / `brand_managers` have no brand column and `BrandManager extends BrandOwner`. The only assignment source in the repository is `MobileBranchScopeService`: identity map (or same e-mail) → `asab_users` → `asab_user_roles(role_key='brand-owner').brand_ids` → branches of those brands (`branches.asab_brand_id` / restaurant). It fails closed. `ExpenseBrandScopeService` wraps it; the expense is reached through `expenses.branch_manager_id → branch_managers.branch_id` (soft-deleted managers included).

There is no dedicated brand-manager assignment model. A brand manager is "assigned" only when a dashboard user with the same e-mail holds a `brand-owner` role over specific brands. See the review report for the proposed dedicated model.

## Behaviour
* All brand-actor reads/writes on expenses go through `ExpenseRepository::*InBranchScope` (private `inBranchScope()`); the old unscoped brand-owner repository methods were removed.
* Out-of-scope or unknown id → 404 `Expense not found`, before the service is called (zero writes, zero events).
* Decision attribution: `ExpenseDecisionRole` (`brand_owner` | `brand_manager`) is passed to `ExpenseApprovalService`; the stage value is unchanged.

## Not changed (documented limits)
* `expense_timelines.performed_by_type` enum cannot hold `brand_manager` → stays `brand_owner`; actor id is in `performed_by`. Minimum compatible fix (needs authorization): additive migration extending the enum (MySQL `MODIFY COLUMN`, SQLite guard) + `ExpenseTimelinePerformedByType` case + `UnifiedTimelineResource` label.
* `ExpenseApprovalService` locking (EXP-07) and custody debit semantics remain BRC-02.
