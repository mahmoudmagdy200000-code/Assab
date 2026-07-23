# Functional Requirements — Dashboard ↔ Mobile Linking & Dashboard Fixes

> **Source:** Stakeholder walkthrough meeting, 2026-07-22 (recordings `7-22-2026_23_30-Tab` and `7-22-2026_23_35-Tab`). Meeting was held in Egyptian Arabic; requirements below are the extracted, de-duplicated intent of that session.
> **Status:** Draft for build. Items flagged **[CONFIRM]** need a one-line answer before coding.
> **Scope of this session:** Brands/Restaurants/Branches, Accountant scoping, Data upload & linking, User login, Shifts, Sales approval, Expenses approval, Purchases/Suppliers. **Inventory** and **Fixed Assets** were explicitly deferred to the next session.

---

## 1. System context

The platform is **two worlds** that must stay in sync:

- **ASAB Dashboard** — the web control panel where the Brand Owner, Head of Accounts, Accountant, and Purchase Officer work.
- **Mobile Application** (legacy backend) — where the Branch Manager, Supervisor, Cashier, and Supplier work day-to-day.

The dashboard **writes into the mobile tables directly** (the "bridge"). The governing principle repeated throughout the meeting:

> **Same data, both sides.** A table produced on the mobile app (e.g. an end-of-shift sales sheet) appears **identically** on the dashboard for the responsible accountant, and vice-versa. Approvals made on the dashboard reflect back into the mobile app.

---

## 2. Glossary (roles & entities)

| Term (AR) | Term (EN) | Meaning |
|---|---|---|
| براند / علامة تجارية | **Brand** | Top-level tenant. Owned by a Brand Owner. Has a package/subscription. |
| مطعم | **Restaurant** | Belongs to a Brand. Has branches. |
| فرع | **Branch** | Belongs to a Restaurant. Operational unit (where shifts/cashiers live). |
| براند أونر | **Brand Owner** | Owns a Brand. Logs into mobile app via email + OTP. Approves expenses. |
| رئيس الحسابات | **Head of Accounts** | Final approver for sales & expenses. Can edit numbers. Scoped to brands. |
| محاسب | **Accountant** | Reviews shifts/sales/expenses. Scoped to specific brands/restaurants/branches. |
| بيرتشس أوفيسر | **Purchase Officer** | Manages purchase orders. **Dashboard only** (no mobile app). |
| مدير فرع / برانش مانجر | **Branch Manager** | Runs a branch on mobile. Ends shift, edits returned sheets, orders stock. |
| مشرف / مشرف عام | **Supervisor** | Opens shifts on mobile. |
| كاشير | **Cashier** | Records daily sales, uploads bills, on mobile. |
| مورد / سبلاير | **Supplier** | Receives purchase orders (dashboard + mobile). Internal or external. |
| الشيفتات | **Shifts** | Work shifts per branch. |
| فارنس / فرق | **Variance** | Difference flagged when a sheet is rejected; attributed to an employee. |
| اعتماد | **Approval / Final approval** | Lock a sheet after the last approver signs off. |

---

## 3. Data hierarchy & upload levels (CORE SPEC)

```
Brand
 └── Restaurant
      └── Branch
```

Uploaded reference data lands at a **specific level** and propagates down. This is a hard rule and drives every screen.

| Data set | Uploaded at level | Propagation |
|---|---|---|
| Sales items (أصناف المبيعات) | **Brand** | All restaurants + all branches see the same list. |
| Raw materials / purchase items (المواد الخام) | **Brand** | All restaurants + all branches (per-restaurant view). |
| Suppliers (الموردين) | **Brand** | All restaurants + all branches. |
| Employees (الموظفين) — cashiers etc. | **Branch** | Each branch has its own employee list. Upload template carries a **branch name** column. |
| Fixed Assets (الأصول الثابتة) | **Branch** | Each branch has its own. *(Detailed flow deferred to next session.)* |

- **FR-DATA-1 —** Sales items, raw materials, and suppliers uploaded once at Brand level MUST appear under every restaurant and branch of that brand without re-upload.
- **FR-DATA-2 —** Employee uploads are per-branch; the import template MUST include a branch identifier column, and each row MUST be attached to the named branch. **⚠️ Discrepancy:** code currently uploads employees at **restaurant** level (`POST /admin/restaurants/{id}/upload/employees` → `asab_employees`), while the meeting specifies **branch** level. Resolve per open Q9.
- **FR-DATA-3 —** Uploaded reference data feeds the mobile app (raw materials → purchases, sales items → shift sales, employees → cashier/shift roster). **[CONFIRM]** exact table targets on the mobile side (see §12).
- **FR-DATA-4 (BUG) —** Uploaded data currently **does not persist**: after upload the sheet shows as uploaded, but on refresh it reverts to "not uploaded" (demonstrated with restaurant employees). Upload MUST be durable and survive refresh.
- **Note:** The generic **CSV distributor** shown for restaurant distribution is **not the intended mechanism** — uploads use the templated importer, not that CSV screen. **[CONFIRM]** whether the CSV screen should be hidden/removed.

---

## 4. Brands / Restaurants / Branches + Modules card

- **FR-BRB-1 —** Adding a Brand (name, package, owner email) and issuing the owner's mobile credentials works and stays. On create, the Brand Owner receives email + OTP to enter the mobile app.
- **FR-BRB-2 —** A Brand has restaurants; a restaurant has branches. Both create flows work.
- **FR-MOD-1 (BUG) —** The **Modules** widget on the brand view currently returns **0**. It MUST return the actual **count/list of modules** granted to that brand. Modules are a **fixed catalog of 9** (`ModuleCatalog.php`): `sales, expenses, purchases, inventory, waste, assets, shifts, employees, cash`. The granted subset is stored in the `modules` JSON column on `asab_brands`; each **package** (`asab_brand_packages`) maps to a subset. The widget must read that JSON and return count + names. *(The meeting said "~8"; the code catalog is 9 — confirm none is meant to be hidden.)*

---

## 5. Accountant / Head of Accounts — assignment & scope

Brand Owner creates Accountants and Heads of Accounts, then assigns each one a scope: **brand(s) → the brand's restaurants → their branches**. When a brand is assigned, **all its restaurants and branches fall under that user's scope**.

- **FR-ACC-1 —** Brand Owner can create an Accountant / Head of Accounts and assign scope by brand, restaurant, and branch.
- **FR-ACC-2 —** Assigning a **brand** to an Accountant/Head grants scope over **all restaurants and all branches** of that brand.
- **FR-ACC-3 (BUG) —** The responsible-user summary currently shows **"zero accountant / zero restaurant / zero validity"** even after assignment. It MUST return the real counts and the **actual assigned** restaurants/branches for each accountant.
- **FR-ACC-4 —** The **users list** currently shows the brand column as a raw **ID**. It MUST show the **brand name** (see cross-cutting §10, names-not-IDs).
- **FR-ACC-5 —** The **distribution summary** (ملخص التوزيع) MUST list each Head of Accounts / manager with the Accountants under them and their assigned restaurants/branches — by **name**.
- **FR-ACC-6 —** Module **permissions** for an Accountant are set at assignment time and MUST be **editable afterwards** (currently the user believes they must re-create the assignment). Editing permissions/scope MUST re-save cleanly.
- **FR-ACC-7 (BUG) —** After granting permissions/scope and refreshing, the change **reverts / does not persist** (reported as "it returns them back / returns a panel"). Permission & scope edits MUST persist across refresh. **[CONFIRM]** exact reproduction (which screen, what reverted).
- **FR-ACC-8 (reports-to) —** The Brand Owner assigns which **Head of Accounts** each Accountant reports to (meeting: "I chose for him to submit his report to → Ahmed Awad"). The distribution view MUST show this Accountant→Head chain, and it MUST be editable. *(Backed by `asab_user_roles.reports_to_id` / `DistributionController::moveToHead`.)*
- **Working as-is:** distributing accountants to heads of accounts was confirmed working. Subscriptions screen was confirmed with **no issues**.

---

## 6. User provisioning & mobile login

- **FR-USR-1 —** Users created on the dashboard (Branch Manager, Cashier, Supervisor) MUST be able to log into the **mobile app** with those credentials.
- **FR-USR-2 —** Mobile accounts are **independent** (each stands alone); there is **no master/linked parent account**.
- **FR-USR-3 —** Credentials are delivered by **email** (e.g. Branch Manager `manager@asab.com`). A single password works across both worlds (dashboard + mobile) — see credential model. Password delivery MUST NOT leak via logs.
- **FR-USR-4 —** Each Branch Manager account resolves to a specific restaurant + its branches (the account demoed did not show its branches — verify scoping returns the manager's restaurant/branches).
- **FR-USR-5 (BUG) —** When creating a **Cashier / employee** under a Branch Manager, the **branch selector shows ALL branches system-wide** instead of only the branches of that brand's/restaurant's (demoed: "Bazooka has one restaurant, four branches… it should show *Main Brunch* and *Main Brunch 2* of *Bain Al-Reem*, not every branch"). The branch picker MUST be **scoped to the current brand → restaurant**. See BUG-8.

---

## 7. Shifts module

Shifts are configured **on the dashboard by the Accountant** (not the Brand Owner). The accountant is scoped to the brands/branches they manage.

- **FR-SHF-1 —** Shift **settings** are defined per Brand the accountant is responsible for: number of shifts, hours per shift, and start time (e.g. 4 shifts × 8h starting 08:00). A **Regenerate** action auto-builds the shift schedule (Shift 1…N).
- **FR-SHF-2 —** Shift settings for a brand apply to **all branches** of that brand. If an accountant is responsible for 2 brands, they can open and configure both.
- **FR-SHF-3 (BUG) —** The shift settings screen currently loads the **wrong brand** ("banal"/placeholder). It MUST load the **correct brand with its own standards/data**.
- **FR-SHF-4 —** Shift **time window**: the Branch Manager's working day spans from the **first shift's start** to the **last shift's end** (derived from the shift config). Managed from the dashboard.
- **FR-SHF-5 —** Config toggle **"Accountant can close/manage shift"** exists and is optional (demoed as turned off). Provide it as a setting.
- **FR-SHF-6 —** On the mobile side, a **Supervisor** opens the shift; a **Cashier** operates it (main branch, restaurant). The accountant sees the **daily shifts of all branches** they are responsible for.
- **FR-SHF-7 —** Shift settings on the dashboard are **not a live mirror** of the mobile shift instances — they configure/seed them. The dashboard's job is the configuration + the daily roll-up, not real-time shift state. **[CONFIRM]** exact bridge point (does Regenerate write shift rows into the mobile tables?).

---

## 8. Sales approval workflow (المبيعات) — CRITICAL

### 8.1 Generation (mobile)
1. Cashier records daily sales during the shift (mobile).
2. Branch Manager **ends the shift** (mobile) → the app produces a **sales sheet** containing: total sales, cash amount, bank/credit-card amount, orders count, and per-**aggregator** breakdown (Jahez, Keeta, Ninja, HungerStation, etc.).

### 8.2 Review & approval (dashboard ↔ mobile)
3. The **same sheet** appears on the dashboard for the **Accountant** responsible for that branch (identical columns/numbers).
4. Accountant **approves** or **rejects**. On approval, the accountant's name is stamped as **"Final Verify by"** in the mobile app.
5. On approval it flows to the **Head of Accounts**, who can **edit the numbers**, then **approve** (final) or **reject**.
6. **Final approval** by the Head of Accounts **locks** the sheet: no further edits by anyone; it is persisted and feeds the ERP + financial reports.

### 8.3 Reject / return loop
7. On reject, a **notification** goes to the Branch Manager in the mobile app. The manager opens the sheet, **edits the numbers**, and resends.
8. Reject requires a **reason** (from a fixed list: transfer delay, canceled sale, cash-box error, data-entry error, settlement, …), the **employee** the variance is attributed to, and that employee's number.
9. A **variance (فارنس)** = the flagged difference; it is returned to the Branch Manager **with the cashier details**.
10. The loop can bounce Manager → Accountant → Head of Accounts until final approval.

### 8.4 Rules
- **FR-SAL-1 —** The end-of-shift sales sheet MUST be the same object on mobile and dashboard (bridge), including aggregator breakdown, cash, card, orders.
- **FR-SAL-2 —** Status model: `pending → accountant_approved → head_final` with a `rejected` branch that returns to the Branch Manager.
- **FR-SAL-3 —** Only the Head of Accounts (and, per config, the accountant) may edit numbers pre-final. The Branch Manager may edit **only when the sheet is returned to them**.
- **FR-SAL-4 —** After **final approval** the sheet is **read-only** for all roles.
- **FR-SAL-5 —** Reject MUST capture reason + attributed employee (+ number) and surface the variance to the Branch Manager with cashier details.
- **FR-SAL-6 —** Bills/attachments (فواتير): the Cashier uploads a bill **per shift**; it is attached to the shift and the Head of Accounts can **view/download** it.
- **FR-SAL-7 —** The accountant/head sees the responsible branch's sheet by branch (e.g. Riyadh main branch).

---

## 9. Expenses workflow (المصروفات / expenses)

### 9.1 Types & creation
- Four expense types (meeting → code enum `expenses.expense_type`): **Quick Cash** → `quick_cash`, **Petty/Pre (بيري)** → `pre_approval` *(mapping to confirm, Q10)*, **Single** → `single_invoice`, **Group Invoice** → `grouped_invoice`.
- Creating an expense sets status **PENDING**.
- Expense fields: **Supplier** (from dashboard suppliers), **Item(s)**, **supplier invoice number**, **date**, **amount before tax**, **amount after tax**, **attachments**.

### 9.2 Approval chain
> **Build status:** Today the Expense module approves **brand-owner only** (draft→pending→approved/rejected). The Accountant "document each invoice" stage and the Head-of-Accounts final stage below **do not exist yet** and are a primary build item (see §15.6 / §15.8).

1. New expense → **PENDING**.
2. **Brand Owner approves** first. *(Note: expense approval routes to the **Brand Owner**, not the dashboard.)*
3. After Brand Owner approval → back to the **Accountant**, who opens the pending invoice, reviews **attachments**, **documents/authenticates each invoice** (توثيق), then approves.
4. **Head of Accounts** sees the same table → **final approval** → locked (no edits after).
5. Reject by Brand Owner or Accountant returns down the chain (mirrors the sales reject loop).

### 9.3 Category linkage
- **FR-EXP-1 —** Expense items come from the **sales-items** taxonomy. A shared **category** field drives routing: a category flagged as *expense* appears under **Expenses**; a category flagged as *item/purchase* appears under **Purchases/Items**. One taxonomy, two destinations.
- **FR-EXP-2 —** Suppliers selectable on an expense are the **dashboard-registered suppliers** of that brand.
- **FR-EXP-3 —** Status model: `pending → brand_owner_approved → accountant_documented → head_final`, with a reject branch.
- **FR-EXP-4 —** After final approval the expense is **read-only**.
- **FR-EXP-5 —** Expenses are **not** tied to shifts (they originate from dashboard/items, not the mobile shift sheet).
- **FR-EXP-6 (BUG / linking) —** On mobile, the **Items** and **Expenses** pickers must return the brand's uploaded catalog filtered by category (per FR-EXP-1). In the demo these lists **came back empty** ("I choose Items… here too, not coming back"). The mobile pickers MUST surface the brand-level uploaded sales-items/categories (this is the consumer side of the brand-level upload in §3, and depends on the upload persistence fix BUG-6).
- **[CONFIRM]** whether the Brand-Owner approval step is on mobile, dashboard, or both.

---

## 10. Purchases & Suppliers (المشتريات)

### 10.1 Purchase order
- Purchase items = **raw materials** (uploaded per **restaurant**).
- Create order: select **items** → select **supplier** → send.
- The **Purchase Officer** manages orders. **Dashboard only — no mobile app.** They see **new orders per branch**, approve, **collect** the requests, then **Send to Supplier**.

### 10.2 Supplier model
- Suppliers are registered on the **dashboard**; each supplier maintains **its own item catalog**. Supplier items appear to the buyer for selection.
- Two supplier kinds:
  1. **Internal** — registered in the dashboard / working with Assab. Have **mobile app + dashboard**; receive orders on either.
  2. **External** — a supplier the **Branch Manager adds** and deals with outside the platform.
- A supplier receives an order → **accepts** → **prepares** → **delivers**; the order is fulfilled with its goods. Supplier can operate from mobile or dashboard.

### 10.3 Send-to-Supplier / WhatsApp
> **Build status:** WhatsApp send is a **stub** today — `SendOrderNotification::sendWhatsApp()` only logs, there is no `whatsapp` column and no wa.me/Twilio wiring, and "Send to Supplier" merely stamps `PurchaseOrderGroup.sent_at`. Real WhatsApp delivery is a primary build item (§15.7 / §15.8).

- **FR-PUR-1 —** **Send to Supplier** opens the supplier's **phone number** and sends the order details (ordered items, quantities) via **WhatsApp** (WhatsApp integration). An order number is shown.
- **FR-PUR-2 —** For internal suppliers, "Send to Supplier" routes into their dashboard/mobile order inbox; for external suppliers it goes out via WhatsApp. **[CONFIRM]** whether internal suppliers also get the WhatsApp message or only the in-app order.
- **FR-PUR-3 —** Branch Manager can also raise/receive purchase requests from the **mobile app** and pick a supplier there.
- **FR-PUR-4 —** Purchase items list = the restaurant's raw materials; supplier catalog = that supplier's registered items.

---

## 11. Cross-cutting requirements

- **FR-X-1 (Names not IDs) —** Every dashboard list/response that references a **brand, restaurant, or branch** MUST return the **human-readable name** (not a bare UUID/ID). Applies to: users list brand column, accountant scope lists, distribution summary. *(IDs may remain in the payload as keys, but a name MUST be present for display.)*
- **FR-X-2 (Persistence) —** All create/assign/upload actions MUST be **durable** and survive a refresh. Two confirmed defects: data upload (FR-DATA-4) and permission/scope edits (FR-ACC-7).
- **FR-X-3 (Bridge integrity) —** Any sheet/roster shown on both sides MUST be a single source of truth written across the bridge — no divergent copies.
- **FR-X-4 (WhatsApp) —** Outbound supplier orders use WhatsApp integration (FR-PUR-1).

---

## 12. Defect list (fix these — reported live in the meeting)

| ID | Area | Defect |
|---|---|---|
| BUG-1 | Modules card | Returns 0 instead of the brand's module count/list (FR-MOD-1). |
| BUG-2 | Accountant summary | Shows zero accountants/restaurants/branches despite assignments (FR-ACC-3). |
| BUG-3 | Users list | Brand shown as ID, not name (FR-ACC-4 / FR-X-1). |
| BUG-4 | Accountant scope | Assigned restaurants/branches return empty (FR-ACC-3). |
| BUG-5 | Permissions | Edits don't persist after refresh (FR-ACC-7). |
| BUG-6 | Data upload | Uploaded data (e.g. employees) not saved; reverts on refresh (FR-DATA-4). |
| BUG-7 | Shifts | Shift settings load the wrong/placeholder brand (FR-SHF-3). |
| BUG-8 | User/branch scoping | Cashier-create branch picker lists ALL branches, not the brand's/restaurant's (FR-USR-5). |
| BUG-9 | Expenses/Items (mobile) | Mobile Items/Expenses pickers return empty; brand catalog not surfaced (FR-EXP-6). |

---

## 13. Deferred / out of scope (next session)

- **Inventory** (الانفنتري) — not covered.
- **Fixed Assets** (الأصول الثابتة) — upload level defined (branch), full flow deferred.

---

## 14. Open questions — [CONFIRM] before build

1. Canonical list of the ~8 modules and the **package → modules** mapping (FR-MOD-1).
2. Exact mobile-side **table targets** the bridge writes for each uploaded data set (FR-DATA-3).
3. Should the generic **CSV distributor** screen be removed/hidden (§3 note)?
4. Reproduction detail for the **permission-revert** bug — which screen, what value reverts ("panel"?) (FR-ACC-7).
5. Does **Regenerate** write shift rows into the mobile shift tables, or only store config (FR-SHF-7)?
6. Where does **Brand-Owner expense approval** happen — mobile, dashboard, or both (FR-EXP-5)?
7. Do **internal suppliers** also receive the WhatsApp message, or only the in-app order (FR-PUR-2)? And should **internal vs external** be a real typed flag on the supplier (currently none)?
8. Full **reject-reason** enum for sales and expenses (currently free-text) (FR-SAL-5).
9. **Employees upload level:** restaurant (current code) or branch (meeting)? Do `asab_employees` rows need a `branch_id` and a per-branch upload endpoint?
10. **Expense-type mapping:** is the meeting's "petty (بيري)" the code's `pre_approval`, or a new `petty` type to add?
11. **Orders count** on the sales sheet — required as a first-class field (not present on shift-sales tables today)?
12. Should the **accountant + head-of-accounts stages be added inside the Expense module**, or handled by a new approval layer in `Modules/Admin` (mirroring `ShiftCloseService`)?

---

## 15. Codebase mapping — what exists vs. what to build

**Where things live:** the ASAB dashboard management layer is one module, **`Modules/Admin`** (all `asab_*` tables). The mobile/operational side is the legacy modules (`Modules/BrandOwner`, `Shift`, `Cashier`, `Expense`, `Purchase`, `Supplier`, `Aggregator`, `Branch`). The bridge = Admin services writing legacy tables.

Legend: ✅ exists · ⚠️ partial / needs change · ❌ not built.

### 15.1 Brands / Restaurants / Branches / Modules / Packages
| Item | Status | Location |
|---|---|---|
| Brand CRUD | ✅ | `Admin/…/BrandController`, table `asab_brands` (has `modules` json, `plan`, `owner_email`); routes `/api/v1/admin/brands` |
| Restaurant CRUD | ✅ | `Admin/…/RestaurantController`, `asab_restaurants` (**has `accountant_count` col**) |
| Branch CRUD | ✅ | `Admin/…/BranchController` over legacy `branches` + asab hierarchy cols (`asab_brand_id`, `asab_restaurant_id`) |
| Module catalog (9) | ✅ | `Admin/app/Support/ModuleCatalog.php` → `sales, expenses, purchases, inventory, waste, assets, shifts, employees, cash`; `GET /admin/lookups/modules` |
| Packages | ✅ | `Admin/…/PackageController`, `asab_brand_packages` |
| **BUG-1 Modules widget = 0** | ⚠️ FIX | must read `asab_brands.modules` json → return count+names |

### 15.2 Accountant / Head of Accounts
| Item | Status | Location |
|---|---|---|
| Roles + scope | ✅ | `asab_user_roles` JSON cols `scope, brand_ids, restaurant_ids, branch_ids, module_keys`, `reports_to_id`; `Admin/…/DistributionController` (`/admin/distribution/*`, `/admin/accountants/{id}/assignments`) |
| Brand-level scope → all restaurants/branches | ✅ | `DistributionController::assignments()` forces `scope='brand'` |
| **BUG-2 zero accountants/restaurants** | ⚠️ FIX | `asab_restaurants.accountant_count` not populated/returned; `DistributionController::index` returns restaurants as **ids** (has name maps available) |
| **BUG-3 users list shows brand ID not name** | ⚠️ FIX | `Admin/…/UserController::present()` emits `brand_ids/restaurant_ids/branch_ids` raw uuids — add name resolution |
| **FR-ACC-6 per-restaurant module perms** | ⚠️ | documented limitation: `module_keys` is one flat list, no per-restaurant column (`DistributionController::restaurantModules`) |
| **BUG-5 permission edit not persisting** | ❓ | reproduce on the distribution/assign-modules save path |

### 15.3 Data upload
| Data set | Meeting level | Code level | Status |
|---|---|---|---|
| sales-items / raw-materials → `asab_inventory_catalog` (+legacy `items`) | Brand | **Brand** ✅ | `Admin/…/UploadController::brandUpload`, `POST /admin/brands/{id}/upload/{type}` |
| suppliers → `asab_suppliers` | Brand | **Brand** ✅ | same |
| fixed-assets → `asab_assets` | Branch | Brand **or** Branch ✅ | `brandFixedAssets()` / `POST /admin/branches/{id}/upload/fixed-assets` |
| employees → `asab_employees` | **Branch** | **Restaurant** ⚠️ | `POST /admin/restaurants/{id}/upload/employees` — **DISCREPANCY**, see open Q9 |
| **BUG-6 upload not persisting** | — | — | ❓ verify `UploadController` write + `asab_upload_status` |

### 15.4 Shifts
| Item | Status | Location |
|---|---|---|
| Shift config (num_shifts 1–4, duration_hours, first_shift_start) + window generation | ✅ | `Admin/app/Models/BrandShiftConfig.php` (`asab_brand_shift_configs`), `ShiftConfigService::windows()`, `AccountantCompanyController::saveShiftConfig`; `GET shifts/configs`, `PUT brands/{brandId}/shift-config` |
| Config per brand → all branches | ✅ | keyed by `brand_id` |
| **FR-SHF-3 wrong brand loads** | ⚠️ FIX | verify brand resolution in `shiftConfigs()` |
| Operational shifts (open/close, supervisor/cashier) | ✅ | `Modules/Shift` — `cashier_shifts` (`ShiftStatus`: not_started/in_progress/completed/canceled/reassigned), branch-manager workday |
| Accountant→Head close/approval chain | ✅ | `Admin/app/Services/ShiftCloseService.php` — close→`pending_review`, head final→`closed`, reject→reopen `active` (tagged ACC-6.4 / HEAD-2.5) |

### 15.5 Sales approval — **mostly exists**
| Item | Status | Location |
|---|---|---|
| End-of-shift sheet (cash, card, variance) | ✅ | `cashier_shifts` (`total_sales, cash_collected, card_payments, variance, pos_receipt`), `branch_manager_shifts.aggregator_payments` |
| Aggregator breakdown | ✅ structure / ⚠️ no seed | `shift_sales_breakdown (cashier_shift_id, aggregator_id, amount)`; `Modules/Aggregator` — **Jahez/Keeta/Ninja/HungerStation NOT seeded** |
| **Orders count** in sheet | ❌ | not a field on shift-sales tables — see open Q… |
| Reject/return loop + 2-reject limit + edit-after-reject | ✅ | `manager_approval_status`: pending/approved/rejected/rejected_final + `rejection_count, was_edited_after_rejection` |
| Variance per employee | ✅ | `ShiftController::varianceAllocations`, `ShiftCloseService::setVarianceAllocations`; `VarianceType` over/short, `ResponsibilityType` self/self_and_others/other_factors/mixed |
| Review mirrored to mobile | ✅ | `ShiftFeedbackBridgeService` → `cashier_shifts.review_status` (`approved`/`rejected`) |
| **FR-SAL-5 fixed reject-reason list** | ❌ | reasons are **free-text** (`rejection_reason` max 500) — no enum. Build the reason list. |
| Per-shift bill/attachment | ⚠️ | POS receipt (single) + variance/rejection files (json) exist; no dedicated "bill" entity |

### 15.6 Expenses — **approval chain must be extended**
| Item | Status | Location |
|---|---|---|
| Expense model + types | ✅/⚠️ | `Modules/Expense` `expenses.expense_type`: `quick_cash, single_invoice, grouped_invoice, pre_approval` — meeting's "petty (بيري)" maps to `pre_approval`? **CONFIRM** (open Q10) |
| Status | ✅ | `ExpenseStatus`: draft/pending/approved/rejected |
| Fields (invoice no, before/after tax, attachments, date, supplier, items) | ✅ | `expenses`, `invoice_details`, `expense_items`, `expense_attachments`, `quick_cash_expenses` |
| Category type flag (expense vs purchase) | ✅ | `categories.type` enum `['purchase','expense']`; `Category::scopeExpense/scopePurchase` |
| **Approval chain: Brand Owner → Accountant (document each invoice) → Head final** | ❌ **BUILD** | Expense module has **brand-owner-only** approve/reject (`ExpenseApprovalService`, `ExpenseTimelinePerformedByType`: branch_manager/brand_owner/system). **Accountant + Head-of-Accounts stages do not exist.** |
| Expense items = "sales items" | ⚠️ | code uses `expense_items` (own table); no join to a sales-items table — **CONFIRM** intended source |

### 15.7 Purchases & Suppliers
| Item | Status | Location |
|---|---|---|
| Purchase order + rich status | ✅ | `Modules/Purchase` `PurchaseOrder`, `OrderStatus` (draft…confirmed/preparing/on_the_way/delivered/closed…), `OrderType` (direct_supplier, via_purchasing_officer, …) |
| Items = raw materials (per branch) | ✅ | `items` + `branch_item` pivot + `supplier_items` + `purchase_order_items` |
| Purchase Officer = dashboard-only | ✅ | `asab.role:procurement` (`Admin/routes` `/admin/procurement/*`); mobile only exposes item lookup |
| Dashboard→mobile bridge | ✅ | `Purchase/…/ProcurementDecisionService` writes `purchase_orders` + `purchase_order_items` (`decision_source='dashboard_procurement'`); `Admin/…/ProcurementCatalogBridgeService` writes `items, branch_item, supplier_items, suppliers` |
| Supplier mobile login | ✅ | `Modules/Supplier` `Supplier` (Sanctum + OTP) |
| Internal vs external supplier flag | ❌ | **no `is_external` column** — only `created_by_admin_at` hints admin-provisioned. Build the typed distinction if needed (open Q7). |
| **FR-PUR-1 Send-to-Supplier via WhatsApp** | ❌ **BUILD** | **STUB.** `SendOrderNotification::sendWhatsApp()` = `Log::info` only; no `whatsapp` column, no wa.me/Twilio. "Send to supplier" (`OrderConsolidationService::send`) only stamps `PurchaseOrderGroup.sent_at` — sends nothing externally. |

### 15.9 Implemented (session 2026-07-23)
Confirmed, unambiguous dashboard bugs fixed — additive/backward-compatible, `Modules/Admin`; all 50 related tests green (`DashboardLinkingFixesTest`, `UserAssignmentRulesTest`, `AdminDashboardBatch1Test`).

| Bug | What changed | Where |
|---|---|---|
| **BUG-2** ✅ | Restaurant `accountantCount` + brand-tree `accountants` now **derived** from brand-scoped accountant assignments (stale `accountant_count` column no longer read) | new `Services/AccountantScopeService`, `RestaurantController::present`, `BrandController::present` |
| **BUG-4** ✅ | Distribution `accountants[].restaurants` derived **through brands**; added `restaurantsNamed` + top-level `restaurantNames` map; `assigned`/`free` reflect real coverage | `DistributionController::index` |
| **BUG-3** ✅ | Users list adds `brandsNamed`/`restaurantsNamed`/`branchesNamed` `[{id,name}]`; raw id arrays unchanged (contract kept) | `UserController::present` + `nameMaps` |
| **BUG-1** ✅ | Brand response adds `moduleCount` (full 9-catalog when `modules` unset); `modules` array unchanged | `BrandController::present` |
| **BUG-8** ✅ (backend) | `GET /admin/branches?brandId=&restaurantId=` already scopes the branch picker — **FE must pass the filter**; no backend change | `BranchController::index` |

Still open (need the §14 answers before coding): BUG-5 (permission-revert repro), BUG-6/BUG-9 (upload persistence + mobile pickers), BUG-7 (shift wrong-brand). Interpretation note on BUG-1: the empty-`modules`→full-catalog fallback follows the meeting ("modules are fixed"); revisit if Q1 says packages restrict.

### 15.8 Biggest build items (not yet in code)
1. **Expense approval chain** beyond brand-owner: add Accountant (document/authenticate each invoice) + Head-of-Accounts final stages (§9.2).
2. **WhatsApp integration** for Send-to-Supplier (FR-PUR-1) — currently a logging stub.
3. **Fixed reject-reason list** for sales (and expenses) — currently free-text (FR-SAL-5).
4. **Names-not-IDs** resolution in users list + distribution list (BUG-3, BUG-4).
5. **Modules widget** returning real count (BUG-1) and **accountant_count** population (BUG-2).
6. **Employees upload level** decision: restaurant (code) vs branch (meeting) (Q9).
7. **Aggregator seed data** (Jahez/Keeta/Ninja/HungerStation) + **orders-count** field if required on the sheet.
8. **Persistence bugs**: upload (BUG-6) and permission edits (BUG-5).
