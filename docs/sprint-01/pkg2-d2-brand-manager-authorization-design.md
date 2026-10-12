# D-2 — Brand Manager Authorization: design proposal (NOT IMPLEMENTED)

Status: **BLOCKED on a shared-infrastructure decision** (design gate). This document is a proposal for review by Mahmoud (technical) and Mohamed (business). No code in this package implements it.

## Problem

A mobile **brand manager** (`Modules\BrandOwner\Models\BrandManager extends BrandOwner`, table `brand_managers`) has no brand column. EXP-06 resolves which brands a brand actor may see through `Modules\Admin\Services\MobileBranchScopeService::visibleBranchIds()`, which for any `BrandOwner` (and therefore any `BrandManager`) does:

1. `AsabIdentityMap` where `entity_type = brand_owner`, `legacy_id = actor->id` → `dashboard_id`; **else**
2. same-email fall-back: `AsabUser` where `email = actor->email`; then
3. `AsabUserRole` where `user_id = …`, `role_key = 'brand-owner'` → `brand_ids` → branches.

For a brand manager this means: no dedicated identity-map entity exists (`AsabIdentityMap` has `brand_owner`, `branch_manager`, `cashier`, `supplier`, `supplier_user` — **no `brand_manager`**), and there is no `brand-manager` role (`role_key` only ever `brand-owner`). So a brand manager gets scope **only** when they share an email with a dashboard user holding a `brand-owner` role — i.e. authorization by email equality, inheriting the owner's brands.

This is **fail-closed** (no link → no access) and therefore safe for the P0 isolation fixes, but it is **not an acceptable permanent authority model**: email equality is not an explicit, revocable, auditable assignment, and it grants a manager the full brand-owner brand set rather than an explicitly assigned subset.

## Proposed minimum model (reuse identity-map + roles, no new table)

1. **Identity-map entity.** Add `AsabIdentityMap::ENTITY_BRAND_MANAGER = 'brand_manager'` (distinct from the existing `branch_manager`). A mobile `BrandManager` is linked to its dashboard `AsabUser` by an explicit map row (`entity_type = brand_manager`, `legacy_id = brandManager->id`, `match_method` recorded), created at provisioning — never by email equality at read time.
2. **Dedicated role.** Add a `brand-manager` `role_key` in `asab_user_roles`, carrying the **explicitly assigned** `brand_ids` for that manager (a subset of the company's brands; multi-brand allowed).
3. **Resolution.** In `MobileBranchScopeService`, branch on the concrete class: a `BrandManager` resolves its `AsabUser` via the `brand_manager` identity-map entry (no same-email fall-back), then reads `brand_ids` from the `brand-manager` role. A `BrandOwner` keeps the existing `brand-owner` path.
4. **Consistency + fail-closed.** Validate that every resolved brand belongs to the mapped user's company; a missing map, a revoked/absent role, a cross-company brand, or duplicate/conflicting map rows → empty scope.
5. **Brand-owner behaviour unchanged.**

## Why this is gated (shared-infra impact)

`MobileBranchScopeService` is shared: it also drives the cashier and branch-manager branch pickers and the brand-owner branch lists across modules. Changing its resolution (adding a class branch, a new entity type, a new role, and removing the brand-manager same-email fall-back) is a change to shared authorization used by unrelated modules. Per the closure authorization's design gate, that portion is **stopped pending an explicit decision**, rather than inventing a business rule.

### Dependencies / impact to confirm before implementing

- Provisioning: who creates the `brand_manager` identity-map row and the `brand-manager` role, and when (dashboard invite flow? backfill command `asab:backfill-identity-map`?).
- Backfill: existing brand managers currently relying on the same-email `brand-owner` link would **lose access** the moment the fall-back is removed, unless migrated to explicit `brand-manager` assignments first (expand → backfill → switch reads → remove fall-back, across releases).
- Business rule (Mohamed): is a brand manager assigned an explicit **subset** of the owner's brands, or always the full brand set? This decides whether the `brand-manager` role's `brand_ids` can differ from the owner's.
- Test matrix (to add once approved): explicitly-assigned manager sees only assigned brand; unassigned → none; revoked → none; cross-company linkage denied; same-email-without-explicit-link does **not** grant; duplicate/conflicting maps fail closed; legitimate multi-brand assignment; brand-owner unchanged.

## Current safety posture until D-2 lands

- A-1 and G-2 isolation are enforced regardless of D-2: scope is resolved server-side and fails closed.
- A brand manager with no same-email `brand-owner` link has **no** report/expense access (safe default).
- A brand manager who **does** share an email with a `brand-owner` dashboard user currently inherits that owner's brands. This is the behaviour D-2 must replace with an explicit assignment.
