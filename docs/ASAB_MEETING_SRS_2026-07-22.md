# ASAB (عصب) — Dashboard ↔ Mobile Linking SRS
## Software Requirements Specification — extracted from the 2026-07-22 stakeholder meeting

> **Source:** stakeholder walkthrough meeting, **2026-07-22** (recordings `7-22-2026_23_30-Tab`, `7-22-2026_23_35-Tab`; Egyptian-Arabic). This SRS is the requirements-form of the meeting extract; the working notes live in `docs/tasks/dashboard-mobile-linking-FRD.md` (FRD).
> **Relationship to other docs:**
> - `docs/ASAB_DASHBOARD_SRS.md` — the full-system SRS (v1.0, 2026-07-09, from the prototype). **This** document is the *delta* the 2026-07-22 meeting added on top of it (dashboard fixes + dashboard↔mobile bridge).
> - `docs/tasks/dashboard-mobile-linking-FRD.md` — the same content as an FRD with the live implementation log (§15).
> - `docs/tasks/IMPLEMENTATION-STATUS-2026-07-25.md` — one-page status board.
> **Version:** 1.0 — 2026-07-26 · **Status:** most requirements implemented (see §8 traceability). Statuses: ✅ done · ◑ partial · ⛔ open/decision-gated.
> **Backend:** Laravel 12 modular monolith. `Modules/Admin` = ASAB dashboard (`asab_*` tables); legacy mobile = `Modules/{BrandOwner,Shift,Cashier,BranchManagers,Expense,Purchase,Supplier,Aggregator,Branch}`.

---

## Table of contents
1. Introduction
2. Overall description
3. Functional requirements
4. Integration (bridge) requirements
5. Non-functional requirements
6. Reported defects (meeting)
7. Open decisions
8. Traceability & implementation status
9. Change log

---

## 1. Introduction

### 1.1 Purpose
Specify the requirements raised in the 2026-07-22 meeting: fix the confirmed dashboard defects, and make the ASAB dashboard and the legacy mobile app a single, synchronized system ("same data, both sides"). Each requirement carries a stable ID reused from the FRD so it is traceable to code and tests.

### 1.2 Scope
In scope: Brands/Restaurants/Branches, Accountant scoping & distribution, data upload & linking, user login (dashboard→mobile), Shifts, Sales approval, Expenses approval, Purchases/Suppliers, Fixed-asset assignment, and the cross-cutting bridge/persistence/naming rules. **Out of scope (deferred):** Inventory, and the full Fixed-Assets lifecycle beyond assignment.

### 1.3 Definitions (glossary)
| AR | EN | Meaning |
|---|---|---|
| براند | Brand | Top-level tenant; owned by a Brand Owner; has a package. |
| مطعم | Restaurant | Belongs to a Brand; has branches. |
| فرع | Branch | Belongs to a Restaurant; operational unit (shifts/cashiers). |
| براند أونر | Brand Owner | Owns a Brand; mobile login via email+OTP; approves expenses. |
| رئيس الحسابات | Head of Accounts | Final approver (sales & expenses); may edit numbers; brand-scoped. |
| محاسب | Accountant | Reviews shifts/sales/expenses; scoped to brands/restaurants/branches. |
| بيرتشس أوفيسر | Purchase Officer | Manages purchase orders; **dashboard only**. |
| مدير فرع | Branch Manager | Runs a branch on mobile; ends shift; edits returned sheets; orders stock. |
| مشرف | Supervisor | Opens shifts on mobile. |
| كاشير | Cashier | Records daily sales, uploads bills, on mobile. |
| مورد | Supplier | Receives purchase orders; internal or external. |
| فارنس | Variance | Flagged difference on a rejected sheet; attributed to an employee. |
| اعتماد | Approval / Final approval | Locks a sheet after the last approver signs off. |
| الجسر | Bridge | Dashboard services writing legacy mobile tables (and back). |

### 1.4 References
FRD `docs/tasks/dashboard-mobile-linking-FRD.md`; full SRS `docs/ASAB_DASHBOARD_SRS.md`; API response spec `docs/API_RESPONSE_FORMAT.md`; project guardrails `CLAUDE.md`.

---

## 2. Overall description

### 2.1 Product perspective — two worlds, one truth
The platform is two systems that must stay in sync:
- **ASAB Dashboard** — web control panel (Brand Owner, Head of Accounts, Accountant, Purchase Officer).
- **Mobile App** (legacy backend) — Branch Manager, Supervisor, Cashier, Supplier.

Governing principle: **same data, both sides.** A sheet produced on mobile appears identically on the dashboard for the responsible accountant, and dashboard approvals reflect back into the mobile app. The dashboard **writes into the mobile tables directly** (the bridge).

### 2.2 Data hierarchy & upload levels
```
Brand → Restaurant → Branch
```
| Data set | Upload level | Propagation |
|---|---|---|
| Sales items (أصناف المبيعات) | Brand | all restaurants + branches |
| Raw materials (المواد الخام) | Brand | all restaurants + branches |
| Suppliers (الموردين) | Brand | all restaurants + branches |
| Employees (الموظفين) | Restaurant *(meeting said branch; resolved to restaurant — Q9)* | per-restaurant roster |
| Fixed assets (الأصول الثابتة) | Branch, or Brand-level "awaiting assignment" (`branch_id=null`) | assigned to a branch later |

### 2.3 Constraints & assumptions
API-only Laravel 12; Sanctum tokens; UUID PKs + SoftDeletes; MySQL (SQLite in tests); Asia/Riyadh timezone; Arabic + English. One password works in both worlds (credential copy via the identity map — never a live reset). Money: integer **halalas** on the dashboard, decimal **SAR** on mobile.

---

## 3. Functional requirements

> Each row: **ID — statement** · *status* · code anchor. IDs match the FRD.

### 3.1 Data upload & propagation
- **FR-DATA-1** — Sales items, raw materials, suppliers uploaded once at Brand level appear under every restaurant/branch without re-upload. · ✅ · `UploadController::brandUpload`
- **FR-DATA-2** — Employee uploads carry a branch column and attach each row to the named branch (restaurant-scoped roster). · ✅ · `UploadController::employees`
- **FR-DATA-3** — Uploaded reference data feeds the mobile app (raw materials→purchases, sales items→expense taxonomy, employees→cashier roster). · ✅ · catalog bridges (§4)
- **FR-DATA-4** — Uploads MUST be durable and survive refresh (was BUG-6). · ✅ · persists in `asab_employees`; read via `restaurantStatus`

### 3.2 Brands / Restaurants / Branches / Modules
- **FR-BRB-1** — Create a Brand (name, package, owner email); owner receives email + OTP for mobile. · ✅ · `BrandController`
- **FR-BRB-2** — Brand→restaurants→branches create flows. · ✅ · `Restaurant/BranchController`
- **FR-MOD-1** — The Modules widget returns the granted module count/list (fixed catalog of 9), not 0 (BUG-1). · ✅ · `BrandController::present` (`moduleCount`)

### 3.3 Accountant / Head of Accounts — scope & distribution
- **FR-ACC-1** — Create Accountant/Head and assign scope by brand/restaurant/branch. · ✅
- **FR-ACC-2** — Assigning a brand grants scope over all its restaurants/branches. · ✅ · `DistributionController::assignments`
- **FR-ACC-3** — Responsible-user summary returns real counts + actual assigned restaurants/branches (BUG-2). · ✅ · `AccountantScopeService`
- **FR-ACC-4** — Users list shows brand **name**, not ID (BUG-3 / FR-X-1). · ✅ · `UserController::present`
- **FR-ACC-5** — Distribution summary lists each Head → Accountants → restaurants/branches by name. · ✅ · `DistributionController::index`
- **FR-ACC-6** — Module permissions editable after assignment. · ◑ · editable, but `module_keys` is one flat list (no per-restaurant column)
- **FR-ACC-7** — Permission & scope edits persist across refresh (BUG-5). · ✅ · resolved with BUG-4 (writes always persisted; the empty display was the coverage-derivation bug)
- **FR-ACC-8** — Brand Owner sets which Head each Accountant reports to; editable. · ✅ · `reports_to_id`, `moveToHead`

### 3.4 User provisioning & mobile login
- **FR-USR-1** — Dashboard-created users (Branch Manager, Cashier, Supervisor) log into the mobile app with those credentials. · ✅ · provisioner + `acceptInvitation`
- **FR-USR-2** — Mobile accounts are independent (no master/linked parent). · ✅
- **FR-USR-3** — Credentials by email; one password across both worlds; no log leakage. · ✅ · identity-map hash copy
- **FR-USR-4** — A Branch Manager account resolves to its restaurant + branches. · ✅
- **FR-USR-5** — Cashier-create branch picker scoped to the current brand→restaurant, not all branches (BUG-8). · ✅ (backend) · `BranchController::index?brandId=&restaurantId=` (FE must pass the filter)

### 3.5 Shifts
- **FR-SHF-1** — Shift settings per brand (count, hours, start); a **Regenerate** action builds the schedule. · ✅ · `ShiftConfigService` + `ShiftScheduleBridgeService`
- **FR-SHF-2** — Brand settings apply to all its branches; an accountant with 2 brands configures both. · ✅
- **FR-SHF-3** — The settings screen loads the correct (assigned) brand, not a placeholder (BUG-7). · ✅ · `AccountantCompanyController::shiftConfigs`
- **FR-SHF-4** — Working day = first shift start → last shift end (from config). · ✅
- **FR-SHF-5** — "Accountant can close/manage shift" toggle. · ✅ · config flag
- **FR-SHF-6** — Mobile: Supervisor opens, Cashier operates; accountant sees daily shifts of responsible branches. · ✅
- **FR-SHF-7** — Dashboard config seeds mobile shift instances (not a live mirror); Regenerate writes the mobile `shifts` rows. · ✅ · `ShiftScheduleBridgeService::regenerateForBrand` + `POST …/shift-config/regenerate`

### 3.6 Sales approval (المبيعات) — critical
- **FR-SAL-1** — The end-of-shift sheet is one object on mobile and dashboard, incl. aggregator breakdown, cash, card, orders. · ◑ · card + aggregator now bridged; **orders count** is not yet a stored field (Q11)
- **FR-SAL-2** — Status: `pending → accountant_approved → head_final`, with a `rejected` branch back to the Branch Manager. · ✅ · Operations pipeline
- **FR-SAL-3** — Only Head (and, per config, accountant) edit numbers pre-final; Branch Manager edits only when returned. · ✅
- **FR-SAL-4** — After final approval the sheet is read-only for all. · ✅ · `assertMutable`
- **FR-SAL-5** — Reject captures a reason from a **fixed list** + attributed employee (+number) + surfaces variance. · ⛔ · reason is free-text; enum needs Q8
- **FR-SAL-6** — Cashier uploads a bill per shift; Head can view/download. · ◑ · `pos_receipt` + variance/rejection files; no dedicated bill entity
- **FR-SAL-7** — Accountant/Head sees the responsible branch's sheet by branch. · ✅
- **FR-SAL-8** *(added 2026-07-25)* — The bridged cash figure MUST be the drawer cash (opening float + cash collected), so a mobile-originated shift is not charged a phantom shortage equal to its float. · ✅ · `BridgeLegacyCashierShift` (`cashActual = collected + float`)
- **FR-SAL-9** *(added 2026-07-25)* — A dashboard reject/approve reflects onto the mobile row (`review_status`) and notifies the cashier in-app. · ✅ · `ShiftFeedbackBridgeService`, `CashierShiftResource`, `NotificationType::SHIFT_SALES_REJECTED`

### 3.7 Expenses (المصروفات)
- **FR-EXP-1** — Expense items come from the sales-items taxonomy; a category flagged *expense* shows under Expenses, *purchase* under Items. One taxonomy, two destinations. · ✅ · `ExpenseTaxonomyBridgeService` → `categories.type`
- **FR-EXP-2** — Selectable suppliers are the brand's dashboard suppliers. · ✅ · legacy `suppliers` via bridge
- **FR-EXP-3** — Status: `pending → brand_owner_approved → accountant_documented → head_final`, with reject. · ✅ · Operations pipeline (`ExpenseBridgeService`, `ExpenseInvoiceService`, `ExpenseFeedbackBridgeService`)
- **FR-EXP-4** — Read-only after final approval. · ✅
- **FR-EXP-5** — Expenses are not tied to shifts. · ✅
- **FR-EXP-6** — Mobile Items/Expenses pickers surface the brand's uploaded catalog by category (BUG-9). · ✅ · categories/suppliers/branch_item bridges

### 3.8 Purchases & Suppliers (المشتريات)
- **FR-PUR-1** — "Send to Supplier" sends order details via WhatsApp; an order number is shown. · ✅ · `WhatsAppSupplierNotifier` (wa.me deep link)
- **FR-PUR-2** — Internal suppliers route to their in-app order; external via WhatsApp; internal-vs-external is a typed distinction. · ◑ · `asab_suppliers.is_external` added + exposed (`supplierKind`); routing signal available to FE/app
- **FR-PUR-3** — Branch Manager can raise purchase requests from mobile and pick a supplier. · ✅
- **FR-PUR-4** — Purchase items = restaurant raw materials; supplier catalog = that supplier's items. · ✅

### 3.9 Fixed assets — assignment
- **FR-FA-1** *(from the meeting's brand-level upload note)* — Brand-level uploaded assets land `branch_id=null` ("awaiting assignment") and MUST be visible to the responsible accountant and assignable to a branch. · ✅ · `AssetController::index` (`?assignment=pending`, `summary.pendingAssignment`) + `updateAsset` via `scopeToAssignedBranchesOrUnassigned`. *Note:* `asab_assets` has no brand column → the unassigned pool is company-scoped.

### 3.10 Cross-cutting
- **FR-X-1** — Every response referencing a brand/restaurant/branch returns the human-readable name (IDs may remain as keys). · ✅
- **FR-X-2** — All create/assign/upload actions are durable across refresh. · ✅
- **FR-X-3** — Any sheet/roster shown on both sides is a single source written across the bridge — no divergent copies. · ✅
- **FR-X-4** — Outbound supplier orders use WhatsApp. · ✅

---

## 4. Integration (bridge) requirements

The dashboard synchronizes with the mobile world through these bridges (verified by a 7-linkage adversarial audit):

| Bridge | Requirement | Status | Anchor |
|---|---|---|---|
| Expenses | mobile expense → توثيق → head final → back to mobile | ✅ | `ExpenseBridgeService` / `ExpenseInvoiceService` / `ExpenseFeedbackBridgeService` |
| Purchases core | shared `purchase_orders`; officer decision writes back | ✅ | `ProcurementDecisionService` |
| Catalog upload | brand upload → mobile pickers (suppliers/branch_item/categories) | ✅ | `ProcurementCatalogBridgeService`, `ExpenseTaxonomyBridgeService` |
| WhatsApp send | order → supplier via wa.me | ✅ | `WhatsAppSupplierNotifier` |
| Sales approval | end-of-shift sheet ↔ accountant→head; decision + notify back to mobile | ✅ | `BridgeLegacyCashierShift`, `ShiftFeedbackBridgeService` |
| Shifts config | dashboard config seeds mobile `shifts` + Regenerate | ✅ | `ShiftScheduleBridgeService` |
| Credentials | dashboard/invited user → mobile login, one password | ✅ | `OnboardController::acceptInvitation` + provisioner registry |
| Supplier | dashboard supplier ↔ mobile login + orders; internal/external | ◑ | typed `is_external`; residual: no mobile "branch adds supplier" create-path, so no mobile-origin suppliers to surface |

**Mobile-contract requirements** (surfaced when the bridges ran end-to-end):
- **INT-1** — Mobile resources MUST not emit `null` where the app casts to a non-null String (bridge-provisioned rows can leave nullable columns unset). · ✅ · `SupplierResource`/`BranchItemResource`/`SupplierItemResource` coalesce.
- **INT-2** — Auth endpoints MUST accept the account field the app sends (`email`, labelled «Registered»), mapping it to `identifier`. · ✅ · `NormalizesIdentifier` trait on first-login + login.
- **INT-3** — A mobile submission (sales/expense) MUST reach the **scoped** accountant responsible for that brand/restaurant, not only the head. The bridged operation's branch MUST carry `asab_company_id` + `asab_brand_id` so `TenantBranchResolver` resolves it for a brand-scoped accountant. · ✅ · `BranchHierarchyLinker::ensure` heals the branch tags (from its restaurant link) at the bridge boundary (`ExpenseBridgeService::sync`, `BridgeLegacyCashierShift::handle`) + backfill migration `2026_07_26_000001`. *Residual:* a branch with no restaurant link at all must be attached via `PATCH /admin/branches/{id}` `{restaurantId}`.

---

## 5. Non-functional requirements
- **NFR-SEC-1** — Zero-trust: no blanket `authorize()`; every endpoint checks roles/policies; multi-tenant isolation at the query layer (tenant global scope; `assertBranchAssigned`/`assertBrandAssigned`). Passwords/OTPs hashed; no plaintext logging.
- **NFR-ARC-1** — SOLID: thin controllers, business logic in services, data access in repositories; providers injected via interfaces (e.g. `SupplierOrderNotifier`), no facades in domain services.
- **NFR-INT-1** — Multi-step writes wrapped in `DB::transaction`; bridges are additive & idempotent; changes backward-compatible.
- **NFR-PERF-1** — No N+1 (eager load); list endpoints paginate or cap; composite indexes where queried.
- **NFR-CON-1** — Unified JSON envelope (`BaseController`/`AsabResponse`); money in halalas on the dashboard.
- **NFR-LOC-1** — Arabic + English; Asia/Riyadh timezone.
- **NFR-TEST-1** — Pest suites (Feature/Unit/NFR); SQLite in-memory; SQLite-incompatible migrations guarded by driver checks.

---

## 6. Reported defects (meeting) — resolution
| ID | Area | Status |
|---|---|---|
| BUG-1 | Modules widget = 0 | ✅ |
| BUG-2 | Accountant summary zeros | ✅ |
| BUG-3 | Users list shows brand ID | ✅ |
| BUG-4 | Accountant assigned restaurants empty | ✅ |
| BUG-5 | Permissions don't persist | ✅ (resolved via BUG-4) |
| BUG-6 | Upload reverts after refresh | ✅ (persisted; was a FE read of the wrong status endpoint) |
| BUG-7 | Shift settings load wrong brand | ✅ |
| BUG-8 | Cashier branch picker lists all branches | ✅ (backend; FE passes filter) |
| BUG-9 | Mobile Items/Expenses pickers empty | ✅ |

---

## 7. Open decisions
| # | Question | State |
|---|---|---|
| Q1 | Package → modules mapping | open (fallback: full 9-catalog when unset) |
| Q5 | Does Regenerate write mobile shift rows? | **resolved: yes** (FR-SHF-7) |
| Q7 | Typed internal/external supplier? WhatsApp for internal too? | **partly resolved:** `is_external` added; routing decision with FE/app |
| Q8 | Full reject-reason enum (sales & expenses) | **open** (blocks FR-SAL-5) |
| Q9 | Employees upload level: restaurant vs branch | **resolved: restaurant** |
| Q10 | Expense-type mapping (petty/بيري = `pre_approval`?) | open |
| Q11 | Orders-count as a first-class sheet field | **open** (blocks part of FR-SAL-1) |
| — | Per-brand isolation of unassigned assets (needs `brand_id` on `asab_assets`) | open |
| — | Company disable/delete → mobile propagation | open |
| ops | `FRONTEND_URL` + `CORS_ALLOWED_ORIGINS` in prod env | **ops action** (code reads them) |

---

## 8. Traceability & implementation status (summary)
- **Dashboard defects:** 9/9 ✅.
- **Bridges:** 7/8 ✅, 1 ◑ (supplier — typed flag done, mobile-origin surfacing is moot until a mobile create-path exists).
- **Functional requirements:** the majority ✅; open items are decision-gated (FR-SAL-5/Q8, orders-count/Q11) or documented limitations (FR-ACC-6, FR-SAL-6).
- **Deferred:** Inventory; full Fixed-Assets lifecycle beyond assignment.

Detailed code/test mapping and the session-by-session build log are in `docs/tasks/dashboard-mobile-linking-FRD.md` §15.9–§15.13.

---

## 9. Change log
| Date | Change |
|---|---|
| 2026-07-22 | Meeting held; requirements extracted. |
| 2026-07-23 | BUG-1/2/3/4/7/8 fixed; WhatsApp send-to-supplier. |
| 2026-07-25 | BUG-9 (catalog bridges); 4 partial bridges closed (sales/shifts/credentials/supplier); phantom-shortage financial fix; mobile null-safety + identifier alias; fixed-asset assignment. |
| 2026-07-26 | This SRS authored from the meeting extract. |
