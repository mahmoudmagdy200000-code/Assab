# ASAB (عصب) — Dashboard System SRS
## Software Requirements Specification — Full System, All Roles

> **Source of truth:** reverse-engineered from the interactive prototype at
> https://asab-static-api-server.vercel.app/ (all 20 JS chunks analyzed screen-by-screen),
> merged with the client meeting notes (roles/flows) and mapped against the existing
> Laravel backend on branch `asab-admin-backend`.
>
> **Version:** 1.0 — 2026-07-09
> **Backend:** Laravel 12 modular monolith (`Modules/Admin` = ASAB layer, `asab_*` tables, guard `asab`)
> **Prototype brand:** «عصب ASAB — نظام إدارة مالية المطاعم» (Restaurant Financial Management System)
> Tagline from prototype metadata: **9 modules · 6 roles · integrated approval pipeline**

---

## Table of Contents

1. [Introduction](#1-introduction)
2. [System Overview](#2-system-overview)
3. [Roles & Permissions](#3-roles--permissions)
4. [Domain Model (Entities & Fields)](#4-domain-model)
5. [Operation Lifecycle & Status Enums](#5-operation-lifecycle--status-enums)
6. [Functional Requirements — Platform Admin](#6-functional-requirements--platform-admin)
7. [Functional Requirements — Accountant](#7-functional-requirements--accountant)
8. [Functional Requirements — Head Accountant](#8-functional-requirements--head-accountant)
9. [Functional Requirements — Procurement (Purchasing Manager)](#9-functional-requirements--procurement)
10. [Functional Requirements — Supplier](#10-functional-requirements--supplier)
11. [Functional Requirements — Branch Manager](#11-functional-requirements--branch-manager)
12. [Functional Requirements — Company Portal (بوابة الشركات)](#12-functional-requirements--company-portal)
13. [Functional Requirements — Brand Owner & Mobile Touchpoints](#13-functional-requirements--brand-owner--mobile-touchpoints)
14. [Cross-Cutting Features (Reports, ERP, Notifications, Reminders)](#14-cross-cutting-features)
15. [Non-Functional Requirements](#15-non-functional-requirements)
16. [Current Backend Mapping & Gap Analysis](#16-current-backend-mapping--gap-analysis)
17. [Build Plan — Epics & Tasks](#17-build-plan--epics--tasks)

---

## 1. Introduction

### 1.1 Purpose
This document specifies the complete backend requirements for the ASAB dashboard system
(الداشبورد الرئيسي + بوابة الشركات) so it can be implemented end-to-end. Every screen,
table column, form field, status value, and approval flow in this document was extracted
from the prototype UI; nothing is invented.

### 1.2 Scope
- **In scope:** the web dashboard backend (API-only) for all roles: Platform Admin,
  Company Admin, Brand Owner, Head Accountant, Accountant, Branch Manager,
  Procurement Manager, Supplier — plus the dashboard↔mobile-app bridge points
  (daily uploads, inventory item lists, notifications).
- **Out of scope (per meeting notes):** Reports for the Procurement and Supplier roles
  ("requested to not work on reports for now"), the standalone employee bulk-upload
  template ("may be removed to avoid confusion"), and hiding the Supplier module in
  the first release ("the supplier module should be hidden for now"). These are still
  specified below for completeness but flagged `DEFERRED`.
- The legacy mobile-app modules (`Modules/Branch`, `Modules/Shift`, …) already exist;
  the dashboard reads/writes them through the ASAB layer (see §16).

### 1.3 Definitions
| Term | Meaning |
|---|---|
| **Operation (عملية)** | Any submission from a branch that travels the approval pipeline (sales statement, expense statement, purchase receipt, inventory count, waste doc, shift close…). Stored in `asab_operations`. |
| **Main Dashboard (الداشبورد الرئيسي)** | The internal ASAB-company dashboard: platform admin, accountants employed by ASAB, head accountants, procurement, suppliers. |
| **Company Portal (بوابة الشركات)** | The self-service portal for a client restaurant group (tenant), e.g. «مجموعة التاج للمطاعم» — same modules scoped to one company. |
| **Stage (م1..م6)** | The 6-stage operation lifecycle (§5.2). |
| **Brand (علامة تجارية)** | Top commercial entity under a company (e.g. «برغر التاج»). |
| **Restaurant (مطعم)** | Mid-level entity under a brand, groups branches by city/region. |
| **Branch (فرع)** | Physical location; the unit of daily data entry and subscription pricing. |

### 1.4 References
- Prototype: https://asab-static-api-server.vercel.app/
- Repo specs: `BACKEND_API_SPEC.md`, `COMPANY_DASHBOARD_API_SPEC.md`,
  `MISSING_Dashboard_ENDPOINTS_SPEC.md`, `docs/API_RESPONSE_FORMAT.md`,
  `docs/FRONTEND_WIRING_PROMPT.md`, `docs/PROCUREMENT_DASHBOARD_API.md`,
  `docs/MONTHLY_INVENTORY_FUNCTIONAL_REQUIREMENTS.md`
- Meeting notes (2 meetings: role walkthrough + accounting-system walkthrough)

---

## 2. System Overview

### 2.1 The Two Worlds

```
┌────────────────────────────────────────────────────────────────────┐
│                        ASAB PLATFORM                               │
│                                                                    │
│  ┌──────────────────────────┐   ┌───────────────────────────────┐ │
│  │ MAIN DASHBOARD           │   │ COMPANY PORTAL (بوابة الشركات) │ │
│  │ (الداشبورد الرئيسي)      │   │ per-tenant self-service       │ │
│  │ - Platform Admin         │   │ - Company Admin               │ │
│  │ - Accountant             │   │ - Head Accountant             │ │
│  │ - Head Accountant        │   │ - Accountant                  │ │
│  │ - Procurement Manager    │   │ - Branch Manager              │ │
│  │ - Supplier               │   │ - Procurement Manager         │ │
│  │ - Branch Manager         │   └───────────────────────────────┘ │
│  └──────────────────────────┘                                     │
│                 ▲                              ▲                   │
│                 │ approval pipeline / uploads  │                   │
│  ┌──────────────┴──────────────────────────────┴────────────────┐ │
│  │ MOBILE APP (existing legacy modules)                         │ │
│  │ Branch Manager · Cashier · Supplier · Brand Owner            │ │
│  └──────────────────────────────────────────────────────────────┘ │
│                 │                                                  │
│                 ▼                                                  │
│        External ERP (outbound batches م5, inbound P&L reports م6) │
└────────────────────────────────────────────────────────────────────┘
```

- The **mobile app** is where field data originates (shift close, sales report, expense
  invoices, purchase receipts, daily counts, waste events, purchase requests).
- The **dashboard** is the review/approval hub (meeting: "the dashboard acts as the
  central hub for data review and approval, while the mobile app is used for field
  operations").
- **ERP** integration is bidirectional: finally-approved operations are exported in
  batches (م5); monthly financial reports (P&L, balance sheet, cash flow) are produced
  in the ERP, uploaded to ASAB, and dispatched to brand owners (م6, currently "future
  stage" in pipeline UI but the Reports Manager screens exist).

### 2.2 Hierarchy & Multi-Tenancy

```
Company (شركة/مجموعة)  ← tenant boundary, subscription plan
 └── Brand (علامة تجارية)  ← owner email → credentials; modules; catalog scope
      └── Restaurant (مطعم)  ← city-level grouping
           └── Branch (فرع)   ← daily data entry unit; per-branch subscription (main admin view)
```

Every query MUST be tenant-scoped (company → brand → restaurant → branch chain).
Users carry scoped assignments (brand_ids / restaurant_ids / branch_ids / module_keys
on `asab_user_roles`).

> ⚠️ **Granularity note (from main-shell mock data):** in the main-dashboard world the
> lowest level under a restaurant is modeled as an operational **section** with its own
> manager — «الصالة الرئيسية» (main hall), «قسم التوصيل» (delivery section), «الدور الأرضي/الأول»
> (floors). So "branch" may mean *section-of-location*, each independently managed.
> Brand mock record: `{name, color, abbr, plan (بلاتيني/ذهبي), owner, ownerEmail,
> modules[8], subStatus, expires, daysLeft, restaurants[{name, city, accountants_count,
> status, branches[{name, manager}]}]}`. Confirm with client whether branch = location
> or section before finalizing hierarchy constraints.

### 2.3 The Modules (الموديولات)

The prototype's canonical module list (Admin assignment UI shows 8; accountant home says
"الموديولات التسعة"; company portal lists 9 toggles):

| # | Key | Arabic | Notes |
|---|---|---|---|
| 1 | `sales` | المبيعات | Daily sales statements + channel reconciliation |
| 2 | `expenses` | المصروفات | Expense statements + multi-invoice verification |
| 3 | `purchases` | المشتريات | PO ↔ receipt ↔ invoice 3-way matching |
| 4 | `inventory` | المخزون | Daily + monthly counts, item-list config |
| 5 | `fixed-assets` | الأصول الثابتة | Register, depreciation, expense→asset conversion |
| 6 | `shifts` | الشفتات / إدارة الشفتات | Setup, live monitor, close, history |
| 7 | `employees` | كشف الموظفين / كشف الحساب | Employee wallet/ledger, payroll deductions |
| 8 | `cash` | العهد النقدية | Petty-cash custody per branch |
| 9 | `waste` | الهدر والتالف | Waste/spoilage docs with responsibility allocation |

---

## 3. Roles & Permissions

### 3.1 Role Catalogue

Existing `asab_roles` keys map 1:1 to the prototype roles:

| Role key | Arabic (prototype) | Works on | Summary |
|---|---|---|---|
| `admin` | الأدمن / لوحة الأدمن الرئيسية | Main dashboard | Manages brands, restaurants, branches, packages, users, uploads, reports manager, ERP export, subscriptions, permission matrix, audit. |
| `company-admin` | أدمن الشركة | Company portal | Tenant self-service: subscription/plan, users, brands & branches, module toggles, billing, settings, support. |
| `head` | رئيس الحسابات | Both | Final approval (م4), accountant supervision, ERP posting, cash custody management, reminders, financial reports. |
| `accountant` | محاسب | Both | First-line review (م2→م3) of all module operations for assigned branches/modules. |
| `branch` | مدير فرع | Dashboard + mobile | Branch master data, daily uploads, employees, items, suppliers, branch settings (read-only shift timing), purchase requests. |
| `procurement` | مدير المشتريات | Main dashboard | Own items & suppliers, incoming purchase requests, grouping/consolidation, dispatch to suppliers, aggregated (approved) orders. |
| `supplier` | مورد | Dashboard + mobile | Incoming orders accept/reject, own catalog (items, prices, min qty), delivery status. |
| `brand-owner` | صاحب العلامة | Mobile (+email) | Receives login credentials by email on brand creation; receives monthly reports (email + in-app + PDF/Excel). |

### 3.2 Assignment Rules (from meeting + prototype)

- **Branch manager** → exactly **one branch**; full access to all mobile modules.
- **Accountant** → assigned to **one brand** and a set of **restaurants/branches**
  (prototype shows "الفروع المخصصة: 1–50") plus a **module subset**
  ("الموديولات: المبيعات، المصروفات"). Two assignment UX paths (Admin UI):
  1. **By restaurant/branch** («بالمطعم / الفرع») — grant all modules of a branch.
  2. **By module** («بالموديول») — pick modules, then pick branches; bulk
     "تطبيق على جميع الفروع" (apply to all branches).
- **Every accountant MUST have a head accountant** (manager) — actions notify the head.
- **Head accountant** supervises N accountants (prototype: 4 accountants × 20–25 branches).
- **Accountant works at brand level, not branch level** (meeting) — receives financial
  data from branches under that brand.
- **Admin can create another admin** with the same permissions.

### 3.3 Permission Matrix

`asab_permission_matrix` (already implemented): role_key × module → permission
(view / review / approve / final-approve / manage). Admin can edit cells, clone
versions, restore snapshots. Zero-trust: every endpoint checks role + status=active +
tenant scope (`EnsureAsabRole`, `ResolveTenant`).

---

## 4. Domain Model

All ASAB tables: UUID PKs, `asab_` prefix, money in integer halalas, SoftDeletes,
Asia/Riyadh timezone. Fields below come from the prototype mock data (= the UI contract).

### 4.1 Tenancy & Org

**Company** (`asab_companies` — exists)
`name, logo, plan (Basic|Professional|Enterprise → أساسي/احترافي/مؤسسي), status, max_branches, max_users, monthly_revenue, next_billing, modules(json)`

**Brand** (`asab_brands` — exists)
`company_id, name, abbr (بر/بز/تج), color (#hex), emoji, owner_name, owner_email, owner_user_id, plan, sub_status, expires_at, days_left, modules(json), status`

**Restaurant** (`asab_restaurants` — exists)
`brand_id, company_id, name, city (الرياض/جدة/الدمام/مكة المكرمة/الطائف), accountant_count, status`

**Branch** (legacy `branches` + ASAB hierarchy columns — exists)
`restaurant_id/brand hierarchy, name, city, manager_name, phone, address, monthly_target, sales_month, expenses_month, status`
Prototype branch card: name, city, «م.الفرع:» manager, % of target progress, monthly sales.

**Plan / Subscription** (`asab_plans`, `asab_subscriptions`, `asab_company_subscriptions` — exist)
- **Main-dashboard per-branch plans:** «فضي» 🥈 Silver 899 SAR/mo · «ذهبي» 🥇 Gold 1,499 ·
  «بلاتيني» 💎 Platinum 2,499. Status chain: `active نشط → warning ينتهي قريباً → danger حرج → expired منتهي`
  driven by `days_left` (warn ≤90, danger ≤30). Renew resets 365 days.
- **Company-portal plans:** Basic «أساسي» 199/mo or 1,990/yr (5 branches, 15 users, 4 modules,
  email support) · Professional «احترافي» 400/mo or 4,800/yr (20 branches, 50 users, all modules,
  account manager, advanced reports) · Enterprise «مؤسسي» custom (unlimited, SLA 99.9%, open API).
  Quotas surfaced: branches used/max, users used/max, storage GB used/max.

**User** (`asab_users` + `asab_user_roles` — exist)
`name, email, phone (05XXXXXXXX), status (active نشط | inactive معطل), last_login`
Role assignment: `role_key + scope (brand_ids, restaurant_ids, branch_ids, module_keys)`.
On create: **auto-email login credentials** («سيتم إرسال بيانات الدخول تلقائياً إلى البريد الإلكتروني»).
Company portal instead sends an **invite** («إرسال دعوة»).

### 4.2 Catalog & Master Data

**Item / Category (أصناف)** (`asab_inventory_catalog` — exists)
`brand_id, name (ar), name_en, category, unit, type (sales-item|raw-material), unit_price, min_stock`
- Units: كجم، لتر، كرتون، قطعة، رغيف
- Categories: بروتين، خضروات، مخبوزات، ألبان، زيوت، صوصات، مشروبات، حبوب، توابل
- Meeting: items uploaded by admin apply to **all restaurants and branches under the brand**;
  "category" field label must match the mobile app naming.

**Material (مواد الشراء)** — same catalog table with `type=raw-material`; used by the
purchasing module, separate from sales/expense items.

**Supplier** (`asab_suppliers` + `asab_supplier_items` — exist)
`company_id, brand_id, name, category, contact_person, phone, email, commercial_reg, address, payment_terms, rating (★), orders_count, total_spent, active`
Supplier catalog item: `code (DJJ-001), name, unit, price, min_qty, status (active نشط | suspended موقوف)`

**Branch employee (staff master data)** — from branch-manager upload:
`name, national_id (10 digits), job_role (كاشير/مشرف/طباخ/عامل نظافة/مشرف الشفت/كاشير رئيسي), salary, hire_date, status`

**Fixed Asset** (`asab_assets`, `asab_asset_drafts` — exist)
`name, branch_id, brand_id, category (معدات مطبخ/تقنية وأجهزة/أثاث ومفروشات/مركبات/صيانة وإنشاءات/أخرى), value (pre-tax), annual_depreciation, purchase_date, manager, serial, status (active نشط | maintenance صيانة | retired مُهلك)`
Draft (expense→asset conversion): `invoice_num, vendor, desc, amount_pre_tax, branch, date, expense_op_id, asset_name, category, useful_life_months (24|36|48|60|72|84), status (draft في انتظار التأكيد | confirmed مؤكد)`

### 4.3 Operations & Pipeline

**Operation** (`asab_operations` + `asab_approval_steps` — exist)
`ref_num (SL-/EX-/PO-/INV-/OPS-/WD- prefix per module), branch_id, brand_id, module (sales|expenses|purchases|inventory|waste|shifts|assets|employees|cash), amount, status, match_status, submitted_by, submitted_at, attachments_count, diff_note (free text e.g. «الكاش يختلف بـ 350 ر.س»), source_module, source_id (bridge to legacy tables)`

**Module payloads:**
- **Sales channels** `[{name, icon, pos_amount, actual_amount}]` — channel enum:
  «كاشير (POS)» 🖥️ · «نقدي (صندوق)» 💵 · «بنكي / بنك الرياض (Mada)» 🏦💳 ·
  delivery apps: «طلبات» 🔴 · «هنقرستيشن» 🟠 · «جاهز» 🟡 · «تو يو (ToYou)» 🔵 · «نينجا» ⚫
- **Expense invoices** `[{inv_num, vendor, desc, amount, date, verified(bool), converted_to_asset(bool)}]`
  — VAT math shown in UI: pre-tax = amount/1.15, VAT 15%, incl-tax.
- **Purchase items** `[{item, unit, ordered_qty, received_qty, unit_price}]`
- **Inventory lines** `[{item, unit, prev_qty (أمس/الشهر الماضي), curr_qty (اليوم/هذا الشهر), category}]`
- **Waste products** `[{name, qty, unit, unit_price, class (هدر waste | تالف spoiled), responsibility (موظف employee | مطعم restaurant), emp_allocations: [{emp_id, emp_name, amount}]}]`

**Employee shortfall allocation** (sales reconciliation + waste): rows of
`{employee_number → auto-filled name, amount}` with quick-fill "⚡ المتبقي" (remaining);
must be fully allocated («محمَّل بالكامل») before confirm.

### 4.4 Shifts

**Brand shift config** (`asab_brand_shift_configs` — exists)
`brand_id, morning_shift (07:00-15:00), evening_shift, opening_float (الميزانية الافتتاحية, default 500)`
Meeting: settings define **number of shifts (e.g. 4), duration (e.g. 8h), start times**,
named sequentially (الأول، الثاني…). Branch manager can view but NOT edit timings.

**Shift** (`asab_shifts` — exists)
Open: `branch_id, brand_id, cashier, start_time, open_amount, est_sales, orders_count, status`
Closed: `+ close_amount, actual_cash, sales, diff, type (صباحي|مسائي), date`
Live statuses: `active نشط | late تأخير (انتهى وقت الشفت — لم يُغلق الصندوق بعد) | ended منتهي`

### 4.5 Money Movement

**Cash custody (العهدة)** (`asab_cash_custody`, `asab_cash_transactions`, `asab_settlement_requests` — exist)
`branch_id, custodian, opening, received, spent, current, min_alert (default 5,000), status (normal طبيعي | low منخفض | critical حرج)`
Ledger txn: `date, description (تعزيز عهدة من الخزينة/سداد مورد/سداد فاتورة كهرباء/رواتب عمال يومية/مصروفات متنوعة…), type (in مدين-وارد | out دائن-صادر), amount, running_balance`

**Employee account / wallet** (`asab_employees`, `asab_employee_movements` — exist)
`employee: name, role, branch, balance (negative = مديون للشركة, positive = دائن), salary, advances, deductions, net, status`
Movement: `date, description, type (debit مدين | credit دائن), amount, running_balance, category, ref (SAL-/ADV-/DED-)`
Categories: «فرق مبيعات» sales variance · «تسوية» settlement · «نقص إيصالات» receipts shortage ·
«سلفة» advance · «مكافأة» bonus · «غياب» absence · «خصم هدر» waste deduction · «خصم فرق كاش» cash-gap deduction.
Rule: negative balance **auto-deducted from next salary**; manual «تسوية الرصيد» settle action.

### 4.6 Procurement

**Purchase request** (branch → procurement): `item, qty, unit (كجم/كرتون/قطعة/لتر), urgency (عادي|عاجل ⚡), status, date`

**Order group (consolidation)**: `item, requests_count, branch_lines [{branch, qty, unit}], total_qty, suggested_supplier, unit_price, total_cost, savings, savings_pct, status (new طلبات جديدة | grouped تم التجميع | sent أُرسل للمورد | confirmed مؤكد)`

**Purchase order** (legacy `purchase_orders` — exists): `id (PO-xxx / PO-BATCH-xxx), supplier, brand, items, total, status (pending معلق | approved معتمد | delivered تم التسليم), sent_date, eta, in_transit`
Supplier-side statuses: `pending في انتظار الرد | confirmed مقبول | delivered تم التسليم | rejected مرفوض`
Meeting: purchases can come from **supplier, another branch, or purchasing manager** (3 source types); returns («مرتجعات») also flow here; accountant can **document ("توثيق")** and edit line items before head approval.

### 4.7 Reports & ERP

**ERP export batch** (`asab_erp_batches` — exists)
`batch_num (EXP-2025-10-14-001 / ERP-BATCH-202510-001), module, branches, operations_count, total, approved_by, ready_at, status (ready جاهز للتصدير | exported تم التصدير لـ ERP | failed فشل التصدير)`

**Financial report** (Reports Manager)
`restaurant/brand, period (month), type (قائمة الدخل P&L | الميزانية العمومية | التدفقات النقدية), status (pending في انتظار التقرير | uploading جاري الرفع | approved معتمد للإرسال | sent تم الإرسال), revenue, net_profit, profit_margin_pct, file, sent(bool)`
P&L structure: revenue lines = cash, bank + aggregator channels (هنقرستيشن/جاهز/تو يو/نينجا);
expense lines = رواتب وأجور، إيجارات، مواد خام ومشتريات، كهرباء وماء، صيانة، مصروفات إدارية متنوعة;
net band with margin %.
Send options: channel (email | in-app | both), format (PDF | Excel | both), cover message.

### 4.8 Notifications & Reminders

**Notification** (`asab_notifications` — exists): `title, body, time, icon, unread` +
bell dropdown with unread counter, mark-all-read.
**Reminder** (`asab_reminders` — exists): `title, desc, due, priority (عالية|متوسطة|منخفضة), type (urgent|report|finance|team), done` + auto-reminder rules.

---

## 5. Operation Lifecycle & Status Enums

### 5.1 Core Operation Status

| key | Arabic (long / short) | Meaning |
|---|---|---|
| `pending` | قيد المراجعة / معلق | Submitted by branch, awaiting accountant |
| `approved` | تمت الموافقة / مقبول — «معتمد - مرحلة 1» | Accountant approved, awaiting head |
| `rejected` | مرفوض | Returned to branch manager (needs re-upload) |
| `final-approved` | معتمد نهائياً / نهائي 🔒 «مُغلق» | Head approved — locked/immutable |

### 5.2 The 6-Stage Pipeline («دورة حياة العملية»)

| Stage | key | Arabic | Actor |
|---|---|---|---|
| م1 | `submit` | 📱 رُفع من الفرع | Branch manager (mobile) |
| م2 | `review` | 🔍 قيد المراجعة | Accountant |
| م3 | `approved` | ✓ موافق عليه | Accountant approved → head queue |
| م4 | `final` | 🔒 معتمد نهائياً | Head accountant |
| م5 | `erp` | 🔗 مُرحَّل لـ ERP | Head/Admin posts batch |
| م6 | `reports` | 📊 تقارير ERP (قراءة) | Future stage (read-only reports) |

Rejected = off-pipeline (−1), badge «✕ مرفوض», requires branch re-upload.
Pipeline footer rule (verbatim): «م1–م5: مدارة بواسطة آلة الحالة · م6 (تقارير ERP):
بيانات مرجعية مُستوردة — مرحلة مستقبلية» — stages 1–5 are a state machine; stage 6 is
imported reference data (future).

### 5.2b Operation Origin (مصدر العملية)

Every operation carries an **origin**:

| key | Arabic | Meaning |
|---|---|---|
| `mobile` | 📱 تطبيق الفرع | Submitted from the branch mobile app |
| `procurement` | 🛒 سير المشتريات | Generated by the procurement flow |
| `system` | ⚙ استيراد النظام | System import (uploads/integrations) |

### 5.2c Daily Rollup State Machine (per branch/day aggregation)

The main dashboard rolls up a branch-day's operations into one aggregate state
(derivation from the ops' statuses — implement as computed service, not stored-only):

| key | Arabic label | Sub-label | step |
|---|---|---|---|
| `empty` | لا بيانات | لم يُرفع أي بيان | 0 |
| `incomplete` | غير مكتمل | توجد بيانات معلقة في المراجعة | 1 |
| `ready_consolidation` | جاهز للتجميع | كل البيانات راجعة — ابدأ التجميع | 2 |
| `consolidated` | مُجمَّع | قيد محاسبي مُغلق — جاهز للدفعة | 3 |
| `ready_erp` | جاهز لـ ERP | دفعة جاهزة للإرسال | 4 |
| `exported` | مُصدَّر | مُرحَّل في ERP — انتظار التأكيد | 5 |
| `erp_imported` | مُستورَد في ERP ★ | ERP أكّد الاستلام — مرحلة مستقبلية | 6 |

Derivation (from prototype logic): no ops → `empty`; any pending → `incomplete`;
all reviewed, only accountant-approved → `ready_consolidation`; mix of approved +
final-approved → `consolidated`; all final-approved, not yet posted → `ready_erp`;
all final-approved **and** `erp_posted` → `exported`; ERP receipt confirmation →
`erp_imported` (future). Operations carry an `erp_posted` flag.

### 5.3 Match Status (reconciliation)

| key | Arabic | Row treatment |
|---|---|---|
| `exact` | متطابق ✓ | green |
| `review` / `needs-review` | يحتاج مراجعة | amber right-border |
| `diff` / `qty-diff` | فرق / فرق في الكمية ⛔ | red right-border |

### 5.4 Rejection Reasons (fixed lists, required on reject)

Generic: «بيانات غير مكتملة، فاتورة مفقودة أو غير واضحة، تناقض في المبالغ، فرق في الكميات، تاريخ غير صحيح، مورد غير معتمد، أخرى»
Sales-specific adds: «تقرير POS مفقود، كشف البنك غير مرفق»
+ optional free-text details. Rejection **returns the operation to the branch manager**.

### 5.5 Other Status Sets (verbatim from UI)

- Expense invoice match: `matched مطابقة للفاتورة ✓ | mismatch تناقض في المبلغ ⚠ | missing فاتورة مفقودة ✗`
- Stock level: `ok كافٍ ✓ | low منخفض ⚠ | critical حرج 🔴` (+ normal/low/critical for custody)
- Waste doc: `pending في الانتظار | approved معتمد | rejected مرفوض`; reasons: «انتهاء صلاحية، تلف»
- Monthly inventory branch flags: «🚩 مُعلَّم» flagged · «✅ أكّده الفرع» branch confirmed ·
  «📤 إشعار أُرسل» notified · «لم يُرفع» not uploaded · «⚠ N شذوذ» anomalies (>50% swing)
- Report status: `pending | uploading | approved | sent` (§4.7)
- ERP batch: `ready | exported | failed`
- Attendance: `present حاضر ● | upcoming قادم | absent غائب`

---

## 6. Functional Requirements — Platform Admin

Header: «لوحة الأدمن الرئيسية» — إدارة شاملة للنظام. Tabs: الرئيسية / المستخدمون / الاشتراكات.

Main-shell sidebar (full admin page set): `admin-overview` الرئيسية · `admin-users`
المستخدمون · `admin-restaurants` المطاعم والفروع · `admin-subscriptions` الاشتراكات ·
`admin-companies` اشتراكات الشركات · `admin-permissions` الصلاحيات · `admin-reports`
مدير التقارير · `admin-audit` سجل النشاطات · `admin-settings` إعدادات النظام.
Role-switcher personas (main dashboard): أدمن النظام، رئيس الحسابات، محاسب — الفروع 1–50،
مدير فرع الرياض - العليا، مدير المشتريات، المورد (labeled by company name).

### ADM-1 Overview
- **ADM-1.1** KPI cards: active branches (+delta this month), brands count, active users (+delta), uptime %.
- **ADM-1.2** «توزيع المحاسبين» widget: counts per role (محاسبون، رؤساء حسابات، مدراء فروع، أدمن).
- **ADM-1.3** «تنبيهات الاشتراكات»: up to 4 branches with warning/danger/expired status.
- **ADM-1.4** Quick actions: add accountant, add branch, distribute accountants, manage subscriptions, activity log.

### ADM-2 Brand / Restaurant / Branch Management (meeting-critical)
- **ADM-2.1** Create **brand** with details incl. **owner email** → system provisions a
  brand-owner login and **emails credentials automatically** (already built: B-A6
  `BrandOwnerProvisioningService`). Package/plan selection is part of the add-brand form.
- **ADM-2.2** Create **restaurants** under a brand (name + city). A restaurant has many branches.
- **ADM-2.3** Create **branches** under a restaurant. CRUD on all three levels; changes audited.
- **ADM-2.4** Subscription per branch: plan (فضي/ذهبي/بلاتيني), price/mo, days-left countdown,
  status auto-derivation, renew / immediate-renew / reactivate actions (reset 365 days).
- **ADM-2.5** Brand accordion view with per-brand alert badges; search; 4 summary tiles
  (active / expiring soon / critical / expired).

### ADM-3 User Management
- **ADM-3.1** Add user modal: full name*, email*, mobile (05XXXXXXXX), role*
  (محاسب / رئيس حسابات / مدير فرع) → **credentials auto-emailed**; no manual password.
- **ADM-3.2** User list: search, status pill, branch-count pill, assignment chips
  («{branch} · كل الموديولات» or «n موديول»), activate/deactivate, reset password, edit.
- **ADM-3.3** Accountant distribution (توزيع المهام): by-branch mode (all modules of a branch)
  and by-module mode (modules → branches, apply-to-all). Current-assignments list with remove.
- **ADM-3.4** Assign accountant → brand + restaurants + **head accountant** (meeting:
  mandatory manager). Accountant permissions = module subset (Sales, Expenses, Inventory,
  Fixed Assets, Cash Custody, Account Statement, Shifts).
- **ADM-3.5** Admin can create other admins with same permissions.

### ADM-4 Data Upload (رفع البيانات)
- **ADM-4.1** Upload **categories/items** (sales/expense items) per brand — applies to all
  restaurants+branches under the brand. Template columns: رمز الصنف، اسم الصنف، الفئة، الوحدة، السعر.
- **ADM-4.2** Upload **materials** (purchase raw materials) — separate list, `type=raw-material`.
- **ADM-4.3** Upload **fixed assets** per branch using template.
- **ADM-4.4** ~~Upload employees~~ — `DEFERRED` (meeting: may be removed to avoid confusion).
- **ADM-4.5** Template downloads («نموذج»), upload status tracking (`asab_upload_status`),
  validation errors surfaced per row.

### ADM-5 Reports Manager (مدير التقارير) — see §14.2
### ADM-6 ERP Export Screen — see §14.3
### ADM-7 Permission Matrix, Audit, Settings
- **ADM-7.1** «مصفوفة الصلاحيات / صلاحيات الأدوار»: role × module grid; **click a cell to
  cycle the permission level** (levels include at least «للعرض فقط» view-only up to full
  control); changes apply on save; «نسخ صلاحيات دور إلى دور آخر» clone role→role (replaces
  target role's permissions — confirm dialog); version history + restore (already built).
- **ADM-7.2** «سجل النشاطات» activity log with Excel export («جارٍ تصدير سجل النشاطات إلى Excel»).
- **ADM-7.3** «إعدادات النظام» system settings; per-user «إعادة تعيين كلمة المرور» reset,
  «تعديل الصلاحيات» edit permissions, delete user.

### ADM-8 Company Subscriptions («اشتراكات الشركات — بوابة المجموعات»)
Manages the **tenant companies of the Company Portal** (the bridge between the two worlds):
- **ADM-8.1** KPIs: active companies · **trial «تجريبي» (يحتاج تحويل needs conversion)** ·
  expiring ≤30 days · monthly revenue from companies (K SAR) · managed branches + users.
- **ADM-8.2** List: search by company/contact/city; registered-companies count, active
  count, monthly revenue header.
- **ADM-8.3** «إضافة شركة جديدة» add company; **«منح حساب بوابة المجموعات»** grant a
  Groups-Portal account (provisions company-admin login).
- **ADM-8.4** Company status set includes `trial` in addition to active/warning/danger/expired.

---

## 7. Functional Requirements — Accountant

Sidebar (company portal naming): لوحة التحكم، التذكيرات، المبيعات، المصروفات، المشتريات، المخزون،
الهدر والتالف، الأصول الثابتة، إدارة الشفتات، كشف حساب الموظفين، إدارة العهد النقدية، التقارير.

### ACC-0 Dashboard («ملخص اليوم»)
- **ACC-0.1** Subtitle shows the accountant's **scope**: «الفروع المخصصة: 1–50 · الموديولات: …».
- **ACC-0.2** KPIs: new ops today · approved-by-me (sent to head) · pending-for-me · approval rate %.
- **ACC-0.3** Module grid («الموديولات التسعة»): per module pending/total + urgent red dot.
- **ACC-0.4** Filters: branch, module, date (اليوم/أمس/هذا الأسبوع/هذا الشهر), status, search.
- **ACC-0.5** Pending-operations inbox: rows with module badge, branch, ref, time-ago,
  match badge, diff warning, attachment count, amount; actions: view / approve / reject
  (modal, §5.4) / request clarification («توضيح» — non-terminal). **Bulk approve** («موافقة جماعية»).
- **ACC-0.6** «تقدم اليوم» progress bars: review, approval, documentation, completed branches.
- **ACC-0.7** «يحتاج انتباهاً فورياً»: pending ops with match=diff + rejected-count footer.
- **ACC-0.8** Pagination on all lists.

### ACC-1 Sales (المبيعات)
- **ACC-1.1** KPIs: total sales today (branch count, trend) · total collected · total variance
  (case count) · branches with zero variance.
- **ACC-1.2** Day pills (الكل/اليوم/أمس/قبل يومين/الأسبوع الماضي) with **completeness banner**:
  «n عملية مطلوبة — m مكتملة · k ناقصة» (expected vs uploaded per day).
- **ACC-1.3** Matching table: branch, date, cash sales, card sales, app sales, total sales,
  total collected, variance (✓ مطابق or amount+⚠), status, actions. Row select + bulk
  «اعتماد المحدد (n)» + Excel export.
- **ACC-1.4** Detail screen (per operation):
  - Reconciliation table per **collection channel** (§4.3 channel enum): entered vs expected
    vs diff vs status; grand-total row.
  - Editable reconciliation ledger (edit mode «تعديل الأرقام»/«💾 حفظ»; top line
    «إجمالي المبيعات (من رفع مدير الفرع — مقفل)» locked; hidden once final-approved).
  - **Shortfall → employee allocation**: «الفرق يُخصم من حساب المسؤول» — allocate the gap
    across employees by employee number (auto-name fill, quick-fill remaining, must fully
    allocate before confirm). Feeds Employee Account movements («فرق مبيعات»).
  - Attachments panel (POS report PDF, bank statement, aggregator report) view/download.
  - Activity log timeline (submitted by branch manager 📱 → received by system ✅ → under review 👁).
  - Accountant notes (save note).
  - Actions: «موافقة — إرسال لرئيس الحسابات» / «رفض — إعادة لمدير الفرع» (modal) / «طلب توضيح».
- **ACC-1.5** Variance alert banner listing branches with variances.

### ACC-2 Expenses (المصروفات)
- **ACC-2.1** KPIs: total expenses today (invoice count) · pending (matched/mismatch/missing split) ·
  approved today (sent to head) · discrepancies count.
- **ACC-2.2** Statement rows may contain **multiple invoices**. Expanded invoice table columns:
  inv#, vendor, description, date, pre-tax (amount/1.15), VAT 15%, incl-tax, attachments
  (front photo, stamp+signature photo, totals photo), **توثيق** verification toggle per
  invoice (badge «✅ كل الفواتير موثّقة» when all verified), **أصل ثابت** convert button.
- **ACC-2.3** Invoice review modal: side-by-side entered-data vs attached-invoice data,
  mismatch delta («⚠ فرق: 200 ر.س عن الفاتورة الأصلية»), missing-invoice state, preview file.
- **ACC-2.4** **Expense → Fixed-asset conversion wizard** (2 steps): asset name* + category*
  → useful life (24–84 months) → summary (value pre-tax, annual depreciation = value ÷ years)
  → creates **asset draft** pending confirmation; invoice marked «محوّل» (cannot convert twice).
- **ACC-2.5** Approve statement / reject (reasons list); bulk approve; Excel export;
  4 expense types (meeting) — categories: مواد تنظيف، صيانة، إيجار، فواتير خدمات، متنوع.
- **ACC-2.6** Drafts panel under the list: confirm / discard each draft.

### ACC-3 Purchases (المشتريات)
- **ACC-3.1** KPIs: total purchases today · pending review · qty discrepancies · approved.
- **ACC-3.2** PO rows: branch, PO id, supplier, receive date, attachments, item count,
  received total, diff badge. Filter by status; tabs: «بيانات المشتريات» / «الموردون المعتمدون»
  (supplier cards: items, ★rating, monthly order count).
- **ACC-3.3** Detail modal — 3-way match: table الصنف/المطلوب/المستلم/الفرق/سعر الوحدة/الإجمالي;
  summary tiles (original order value, actual received value, qty-diff or matched);
  mismatch banner → verify with branch manager and supplier before approval.
- **ACC-3.4** Actions: approve / «رفض مع ذكر السبب» / close. Meeting: accountant can also
  **document («توثيق»)** orders and **edit line items (price, qty)** before head approval;
  filter by supplier or branch; includes return orders; 3 order sources (supplier /
  other branch / purchasing manager).

### ACC-4 Inventory (المخزون)
- **ACC-4.1** Tabs: «الجرد اليومي» / «الهدر والتالف» / «جرد الأصناف» / «التقارير»;
  type toggle «الجرد الشهري / الجرد اليومي»; brand + branch filters.
- **ACC-4.2** KPIs: low items · total waste today · waste rate % · normal items;
  (company portal adds) uploaded counts, anomaly alerts (>50% swings), completed branches.
- **ACC-4.3** **Daily count table**: item, unit, opening, +received, −consumed, −waste,
  actual closing, min level, stock status (حرج/منخفض/طبيعي). Formula banner:
  «رصيد الفتح + مشتريات − مبيعات ± تحويلات = رصيد الإغلاق»; per-line match check.
  Action: «اعتماد الجرد» approve count; approve/reject per pending op.
- **ACC-4.4** **Monthly count**: previous month vs current month vs % change chip
  (red <−30%, green >+30%); flag branch 🚩, click-to-flag items, «إرسال (n)» send flagged
  items notification to branch manager app, record branch confirmation («أكّده الفرع»).
  (Meeting: accountant sends inventory to branch manager for adjustments.)
- **ACC-4.5** **Daily-count item selection** («تحديد أصناف الجرد اليومي»): per-branch
  independent item lists — brand pills → branch pills → catalog with category filter +
  search (ar/en), select/deselect all, numbered selected panel; **save & push to mobile
  instantly** with notification to branch manager («حفظ وتحديث التطبيق فوراً» → «تم الحفظ
  والتزامن»). This is the dashboard→mobile bridge.
- **ACC-4.6** Excel export (per branch and all-branches).

### ACC-5 Waste & Spoilage (الهدر والتالف)
- **ACC-5.1** KPIs: pending review · approved this month · total losses (waste+spoiled) ·
  charged-to-employees total.
- **ACC-5.2** Waste doc rows (WD-xxx): branch, brand, products count, date, total,
  «منه على موظفين» employee-charged amount, status; approve/reject; bulk approve;
  branch filter pills with pending badges; search; Excel.
- **ACC-5.3** Expanded per product: toggle classification «هدر ↔ تالف», toggle responsibility
  «على موظف ↔ على المطعم»; employee allocation panel (same component as sales shortfall).
  Approved employee charges post to Employee Account as «خصم هدر» debits.

### ACC-6 Shifts (إدارة الشفتات)
- **ACC-6.1** KPIs: open now · closed today · today's sales · cash gaps needing review.
- **ACC-6.2** Tabs: **مباشر** Live (pulsing cards: cashier, start, orders so far, expected
  sales, close button; late banner «انتهى وقت الشفت — لم يُغلق الصندوق بعد») ·
  **إعداد** Setup (per brand: morning/evening hours + opening float — admin-set, branch
  manager read-only) · **إغلاق** Close (expected POS sales read-only + actual cash input →
  delta banner ✓ or «فرق: ±X» → confirm close) · **سجل** History (branch, cashier, date,
  shift type صباحي/مسائي, sales, diff; Excel).
- **ACC-6.3** Contact employee modal: phone (tel: link) + WhatsApp (wa.me/966…).
- **ACC-6.4** Approval flow (meeting): cashier/branch manager closes shift (cash, card,
  aggregators totals + attachments e.g. PDF invoices for shortage/handover) → accountant
  approve / reject (reason) / return → head accountant final approval → shift closed,
  next shift begins.

### ACC-7 Employee Accounts (كشف حساب الموظفين)
- **ACC-7.1** Master-detail: searchable employee list (name, role, branch, signed balance) ·
  detail card (balance + caption مديون للشركة/دائن/لا يوجد رصيد; stat tiles: total debit,
  total credit, movement count, statement period; salary/advances/net tiles in portal).
- **ACC-7.2** Movements ledger: date+desc, category chip, debit, credit, running balance;
  month selector; closing-balance footer; PDF/Excel export.
- **ACC-7.3** Actions: «إضافة حركة جديدة» add movement · «تسوية الرصيد» settle balance.
  Auto-rule: negative balance deducted from next salary (banner).
- **ACC-7.4** Sources of movements: sales channel shortfalls, cash gaps, waste charges,
  advances, bonuses, absence deductions, settlements, salary (SAL-/ADV-/DED- refs).

### ACC-8 Cash Custody (العهد النقدية) — accountant view (head manages, §8)
- **ACC-8.1** KPIs: active custodies · pending disbursement requests · near-depletion
  (<500 SAR remaining).
- **ACC-8.2** Per-branch custody rows: custodian, brand, warning chips, usage bar
  («n% مُصرَف», red >85%), remaining; expandable txn table (date, desc, type إيداع/صرف,
  ±amount, current balance footer); filters (search, brand, custody status); Excel.

### ACC-9 Reminders (التذكيرات والمهام)
- **ACC-9.1** Task list: check toggle, title, desc, priority chip, due, delete;
  high-priority alert bar; new-reminder modal (title, details, due date, priority).

### ACC-10 Reports (التقارير) — downloadable file cards (P&L monthly, daily sales summary,
expenses report, inventory report, monthly payroll). `Read-only downloads in v1.`

---

## 8. Functional Requirements — Head Accountant

Sidebar: لوحة التحكم، بانتظار الاعتماد، المعتمدة نهائياً، المرفوضة، per-module screens
(المبيعات/المصروفات/المشتريات/المخزون/الهدر/الأصول/الشفتات/كشف الموظفين/العهد النقدية)،
التذكيرات، أداء المحاسبين، التصدير لـ ERP، التقارير المالية.

### HEAD-1 Dashboard
- **HEAD-1.1** KPIs: awaiting-my-approval (from accountants, م3) · approved-by-me today
  (posted to ERP) · accountants active (n/n) · rejected ops for review · overall performance %.
- **HEAD-1.2** Pipeline widget (6 stages with counts; م6 always «مرحلة مستقبلية»).
- **HEAD-1.3** Brand performance: % of target bar, sales, expenses, net per brand.
- **HEAD-1.4** Bottom queue «بانتظار اعتمادك النهائي» with «اعتماد الكل» + per-row actions.

### HEAD-2 Final Approval (بانتظار الاعتماد)
- **HEAD-2.1** Operations **grouped by accountant × module**; group header: accountant,
  module pill, «⚠ يوجد فروق» badge, op count, group total. Group actions: «اعتماد الكل»
  batch-approve / «إرجاع للمراجعة» return for review.
- **HEAD-2.2** Filters: brand pills, module pills, displayed-total; expanded rows show
  lifecycle stepper, ref, branch, match pill, time-ago, amount; per-row approve/reject/view;
  attachments list.
- **HEAD-2.3** «المعتمدة نهائياً» read-only locked list (🔒) with total badge —
  awaiting ERP posting. «المرفوضة» list — need branch re-upload.
- **HEAD-2.4** Per-module final-approval screens (same component): KPIs pending/final/rejected,
  bulk «🔒 اعتماد الكل», two sections (awaiting / finalized).
- **HEAD-2.5** Meeting flow: accountant approves → head approves/rejects → on head approval
  operation locks and (for shifts) the shift closes and next begins.

### HEAD-3 Accountant Performance (أداء المحاسبين)
- **HEAD-3.1** Card per accountant: assigned branches count, ★rating, approval-rate bar
  (≥90 green, ≥70 amber, else red), approved / pending counters (pending >5 red),
  actions: view operations, contact. Team list: responsible brand, monthly ops, pending.

### HEAD-4 Cash Custody Management — owns «تعزيز عهدة» replenish (from treasury الخزينة):
per-branch balances vs min threshold (5,000), immediate top-up for low/critical branches,
monthly ledger (مدين وارد / دائن صادر / running balance), Excel. (Full spec §ACC-8 + replenish.)

### HEAD-5 ERP Export — see §14.3 (head surface: filtered export + «ترحيل الكل»).
### HEAD-6 Reminders — same as ACC-9 with head-specific mock types (urgent/report/finance/team).
### HEAD-7 Financial Reports — download cards: P&L per brand, branch comparison,
sales summary, expense analysis (PDF).

---

## 9. Functional Requirements — Procurement

(Purchasing Manager — mostly dashboard-only per meeting.)

### PRC-1 Dashboard
- **PRC-1.1** KPIs: new requests (need processing) · grouped requests (ready to send) ·
  sent requests (awaiting confirmation) · monthly savings (SAR + % of purchases, trend).
- **PRC-1.2** Latest POs list; «أمر شراء جديد» create modal (supplier, brand, description).

### PRC-2 Incoming Requests → Consolidation (the core value loop)
- **PRC-2.1** Purchase requests arrive from branches (mobile). Group card per **item**:
  emoji+name, status, «n طلبات من n فروع», per-branch qty lines, suggested supplier,
  unit price, total cost, expected savings (amount + %).
- **PRC-2.2** Actions by status: new → «تجميع وإرسال للمورد» · grouped → «إرسال للمورد» ·
  sent → awaiting supplier confirmation; always: edit ✏️ / reject ✕.
- **PRC-2.3** Status chain: `new → grouped → sent → confirmed` (§4.6).
- **PRC-2.4** Grouped-orders view per **supplier** across branches («تجميع أوامر الشراء حسب
  المورد عبر كل الفروع»): orders count, branch chips, total, bulk send.
- **PRC-2.5** Sent orders: PO-BATCH ids, sent date, total, ETA, in-transit/delivered.
- **PRC-2.6** Meeting: incoming orders can be **approved, partially approved, or rejected
  with a reason** (e.g. price higher than agreed). Aggregated (approved) orders list.

### PRC-3 Own Items & Suppliers (meeting: procurement manages their own)
- **PRC-3.1** Items & prices table: item (+brand), unit, last price, supplier count;
  add item. Items linked to the purchasing profile appear in the mobile app filtered
  by supplier/purchasing officer.
- **PRC-3.2** Suppliers: add supplier; rows with category chip, contact person, phone,
  ★rating, lifetime orders/spend, active/suspended toggle. KPIs: active suppliers,
  total purchases, avg rating.

### PRC-4 Reports — `DEFERRED` (meeting). Prototype cards exist: monthly purchases by
supplier, price comparison, supplier performance, pending orders.

---

## 10. Functional Requirements — Supplier  `HIDDEN IN V1 (meeting)`

### SUP-1 Orders
- **SUP-1.1** Incoming orders list («الطلبات الواردة»): order id, **from** (procurement
  manager OR a specific branch), items text, total, order date, delivery date, status.
- **SUP-1.2** Accept «قبول» / reject «رفض» pending orders — from dashboard **or** mobile app.
  Status: `pending في انتظار الرد → confirmed مقبول → delivered تم التسليم | rejected`.
- **SUP-1.3** Separate approved and rejected lists (meeting).
- **SUP-1.4** KPIs: new orders · accepted this month · my total sales (trend) · active items n/m.

### SUP-2 Catalog («أصنافي»)
- **SUP-2.1** Item CRUD: code, name, unit, price, min order qty, active/suspended toggle.
  Supplier prices feed procurement's cost & savings calculations and appear in the mobile
  app filtered by supplier.

### SUP-3 Reports — `DEFERRED` (meeting).

---

## 11. Functional Requirements — Branch Manager

Works on both mobile and dashboard. Sidebar: نظرة عامة، رفع البيانات، الموظفون، الأصناف،
الموردون، إعدادات الفرع (+hidden: طلبات الشراء، شفتات الفرع).

### BRM-1 Overview
- **BRM-1.1** Monthly achievement hero (target vs actual %), KPIs: today's sales (trend),
  month sales, month expenses, net profit.
- **BRM-1.2** «مهام اليوم»: upload morning-shift sales / upload today's expenses /
  daily inventory count / close evening shift (states: مكتمل/معلق/لاحقاً).
- **BRM-1.3** «طاقم اليوم»: name, role, shift, attendance status.

### BRM-2 Daily Upload (رفع البيانات) — the م1 entry point
- **BRM-2.1** Sales form: total sales*, shift (صباحي/مسائي/كامل اليوم), attachment (image/PDF).
- **BRM-2.2** Expenses form: total, description textarea, invoice upload.
- **BRM-2.3** Submit «رفع البيانات للمحاسب» → creates pending operations; success screen.
  Meeting data-entry scope: daily reports, sales reports, daily inventory, waste,
  expenses, purchase requests, cash account statements.

### BRM-3 Master Data (bulk upload + tables)
- **BRM-3.1** Employees upload (name, national ID, job, salary, hire date) + table with
  search/add/status. `Employee template DEFERRED per meeting — keep manual add.`
- **BRM-3.2** Items upload (code, name, category, unit, price) — **view** items assigned
  to the branch by admin (meeting: view-only for admin-pushed catalog).
- **BRM-3.3** Suppliers upload (name, CR, phone, email, address) + «طلب مورد جديد»
  request routed to procurement manager; chips معتمد / قيد المراجعة.

### BRM-4 Daily Inventory (الجرد اليومي)
- **BRM-4.1** Stock table: item (+min level), actual qty, status (كافٍ/منخفض/حرج).
- **BRM-4.2** «تسجيل جرد» modal: per configured item — expected qty shown, input actual →
  submit to accountant (review within 24h). Item list comes from ACC-4.5 push.

### BRM-5 Shifts (hidden page + mobile)
- **BRM-5.1** Open shift: cashier select, opening float (default 500) → open.
- **BRM-5.2** Close shift: shows open time + float → close → sent to accountant.
- **BRM-5.3** Branch settings show shift timings **read-only** (set by admin).

### BRM-6 Purchase Requests (hidden page + mobile)
- **BRM-6.1** Request rows: item, qty, unit, urgency (عادي/عاجل⚡), status, date.
- **BRM-6.2** New request modal: item, qty, unit (كجم/كرتون/قطعة/لتر), priority → pending.

### BRM-7 Branch Settings — name, manager, phone editable; city read-only;
shift timings read-only (meeting).

---

## 12. Functional Requirements — Company Portal

Tenant self-service («بوابة الشركات», v2.1). Role-picker login (5 roles); bilingual ar/en
RTL/LTR toggle; dark navy sidebar; notification bell with unread counter + mark-all-read.
Head/accountant/branch/procurement screens inside the portal = §7–§11 scoped to the tenant.
Company-admin-specific screens:

### CMP-1 Dashboard — welcome, plan hero card (plan, status ✅, expiry + days left,
quota chips: brands n/∞, restaurants n/∞, branches n/20, users n/50, price, upgrade button),
KPIs (monthly sales +trend, expenses, net profit +trend, branch achievement rate «n من n فرع
فوق الهدف»), per-brand performance cards (sales vs target progress).

### CMP-2 Subscription & Plan
- Billing period toggle «سنوي (وفّر 17%) / شهري»; current-plan card with usage meters
  (branches, users, storage GB); 3-plan comparison (§4.1); upgrade request → alert;
  Enterprise → contact sales.

### CMP-3 User Management — count «نشط · n/50»; list with role chip, status, email,
branch; enable/disable; edit (name, role/permissions, contact); **invite modal**
(name, email, role select) → «إرسال دعوة» registration invite.

### CMP-4 Brands & Branches — KPIs (brands, restaurants, active branches «من 20 مسموح»,
monthly sales); brand→restaurant→branch accordion (manager, city, % of target, sales);
**add-branch modal** (name, brand select, city select) → «سيظهر بعد مراجعة الإدارة»
(pending platform-admin review).

### CMP-5 Active Modules — 9 module toggles with plan gating: «مفعّلة» / «متاحة للتفعيل»
(in plan) / «تحتاج ترقية» (not in plan → upgrade alert).

### CMP-6 Billing & Payments — KPIs (total paid, next invoice date+amount, payment method
card **** 4521); invoice history rows (id INV-2025-012, date, amount, «✓ مدفوع», PDF
download); export Excel.

### CMP-7 Company Settings — group name, main city, CR number, email; save.

### CMP-8 Support — channels (live chat 9am–9pm, phone 800 123 4567, email support@asab.sa
24h SLA); ticket form (issue type: subscription/technical/general/feature request +
description) → success + send-another.

---

## 13. Functional Requirements — Brand Owner & Mobile Touchpoints

### BRO-1 Brand Owner
- **BRO-1.1** On brand creation, receives **login credentials via email** (mobile app access).
- **BRO-1.2** Receives monthly financial reports (P&L etc.) via email + in-app notification
  with PDF/Excel attachment (§14.2).
- **BRO-1.3** Legacy mobile portal already exists (`Modules/BrandOwner`): OTP auth,
  cash-transfer requests, fixed-asset requests, brand managers, settings — unchanged.

### MOB-1 Mobile-app bridge points (dashboard side must support):
- **MOB-1.1** Receive operations submitted from mobile (م1) across all modules.
- **MOB-1.2** Push daily-inventory item lists to branch-manager app instantly (ACC-4.5).
- **MOB-1.3** Push monthly-inventory flagged-items notifications (ACC-4.4).
- **MOB-1.4** Rejections notify branch manager with reason (re-upload loop).
- **MOB-1.5** Supplier accept/reject possible from mobile too (SUP-1.2).
- **MOB-1.6** Cashier (mobile-only role): opens/closes shifts; shift-close data becomes
  a م1 operation (cash, card, aggregator totals + attachments for shortage/handover).

---

## 14. Cross-Cutting Features

### 14.1 Notifications
- Bell dropdown (title, body, time-ago, icon, unread dot), unread counter per role,
  mark-all-read, mark-one-read.
- Event triggers (minimum): new pending op (accountant), op approved (head), rejection
  (branch manager), clarification request, inventory list push, flagged-items push,
  subscription expiry warnings, reminder due, supplier new order, procurement new request,
  accountant-action → head notification (meeting: every accountant action notifies head).

### 14.2 Reports Manager (Admin) + P&L pipeline
- **RPT-1** 4-step wizard: ① export from ERP (external) → ② upload Excel (.xlsx/.xls/.csv,
  validation «✓ تم التحقق») → ③ read-only P&L preview (revenue by channel incl. aggregators;
  operating expenses; net + margin; download PDF) → ④ send to owners.
- **RPT-2** Reports table per restaurant/month: revenue, net profit, margin pill, status,
  actions (preview/send/upload/download); month + report-type filters
  (P&L / balance sheet / cash flows).
- **RPT-3** Send options: method (email / in-app / both), format (PDF / Excel / both),
  cover message; per-row send + bulk «إرسال الكل (n تقرير)»; recipients checklist with
  owner emails; success confirmation with count.
- **RPT-4** KPIs: ready, sent this month, waiting for ERP, total restaurants.

### 14.3 ERP Export
- **ERP-1** Only **finally-approved** (م4) operations are exportable. Batches per
  day+module: batch_num, module, branches, op count, total, approved_by, ready_at, status.
- **ERP-2** Admin screen: connection banner (متصل ونشط, last successful export timestamp +
  count), KPIs (ready / exported today / awaiting approval at head / failed=errors),
  filters (module/status/period), export-log table with row select («تحديد الجاهز»),
  per-row export, bulk export selected, bulk export all-ready; selection footer with totals.
- **ERP-3** Head surface: filter form (module radio, period radio incl. custom range,
  restaurant select, status radio معتمدة-فقط default) → results (found count, per-restaurant
  breakdown, total) → **«تصدير Excel»** or **«ترحيل API»** direct posting.
  Batch numbers: `ERP-BATCH-YYYYMM-nnn` / `EXP-YYYY-MM-DD-nnn`. `failed` state must retry.

### 14.4 Audit & Activity
- Every mutation audited (`asab_audit_logs`, middleware `asab.audit`); per-operation
  activity timeline (submitted/received/under-review/approved/rejected with actor + time);
  admin activity-log screen.

---

## 15. Non-Functional Requirements

| # | Requirement |
|---|---|
| NFR-1 | **API-only** Laravel; unified envelope `{success, message, data, meta}` / errors `{success:false, message, errors}` (`AsabResponse`, docs/API_RESPONSE_FORMAT.md). |
| NFR-2 | Auth: Sanctum tokens, guard `asab`; roles via `asab.role` middleware; zero-trust `authorize()` (no `return true`). |
| NFR-3 | **Multi-tenancy isolation** at query layer (company/brand/restaurant/branch scopes); never trust client IDs without ownership validation (`ResolveTenant`, `TenantBranchResolver`). |
| NFR-4 | i18n: `apilocale` middleware (Accept-Language ar/en); all enums/labels translatable; RTL-first UI copy above is the ar source of truth. |
| NFR-5 | Timezone Asia/Riyadh; currency SAR stored as integer halalas; `ar-SA` display formatting client-side. |
| NFR-6 | All lists paginated (`paginatedResponse`) or hard-capped; eager-load to prevent N+1; composite indexes on (branch_id,status,date)-style hot paths. |
| NFR-7 | Multi-step writes in `DB::transaction` (approve+allocate+ledger post = one atomic unit). |
| NFR-8 | Passwords/OTP/tokens hashed; credential emails via Notification module (never logged). |
| NFR-9 | Idempotency on mutation endpoints (`asab.idempotency`), esp. approvals & ERP posts. |
| NFR-10 | Locking: `final-approved` operations immutable (reject edits with 409/domain exception). |
| NFR-11 | Events/Listeners for cross-module effects (approval → ledger post → notification); Jobs for uploads, report parsing, email dispatch, ERP batches (with retry). |
| NFR-12 | Real-time: broadcast live-shift updates + notification counters (Pusher). Polling fallback acceptable v1. |
| NFR-13 | Files: attachments stored via `asab_attachments`; Excel parse/generate queued; templates downloadable. |
| NFR-14 | Tests: Pest feature tests per endpoint group; SQLite in-memory (guard driver-specific migrations); run with `-d memory_limit=1024M`. |
| NFR-15 | Audit every mutation; permission matrix versioned with restore. |

---

## 16. Current Backend Mapping & Gap Analysis

Branch `asab-admin-backend`, `Modules/Admin` (routes: `Modules/Admin/routes/api.php`,
~819 lines, base `/api/v1`). Status legend: ✅ built · 🟡 partial/verify · ❌ missing.

| SRS area | Status | Where / gap |
|---|---|---|
| ADM-2 Brands/Restaurants/Branches CRUD + owner provisioning | ✅ | `Admin/BrandController`, `RestaurantController`, `BranchController`, `BrandOwnerProvisioningService` (B-A6 done) |
| ADM-2.4 Subscriptions/plans/renewals | ✅ | `SubscriptionController`, `asab_plans`, `asab_subscriptions` |
| ADM-3 Users + distribution + credentials email | ✅ | `UserController`, `DistributionController` |
| ADM-4 Uploads (items/materials/assets) + templates | ✅ | `UploadController`, `asab_upload_status` (verify material `type=raw-material` path) |
| ADM-7 Permission matrix / audit / settings / job monitor | ✅ | `PermissionMatrixController`, `AuditLogController`, `JobMonitorController` |
| §5 Operation pipeline (م1–م4) | ✅ | `asab_operations` + `asab_approval_steps`, `OperationService`, `operations/*` routes |
| ACC-0 dashboard inbox/KPIs | 🟡 | `Accountant/*` controllers exist — verify KPI + module-grid + completeness endpoints match §7 |
| ACC-1 sales channel reconciliation + editable ledger | 🟡 | verify channel payloads + edit endpoint + locked total rule |
| ACC-1.4 / ACC-5.3 employee shortfall allocation | ❌ | need allocation endpoints posting `asab_employee_movements` atomically with approval |
| ACC-2 expenses multi-invoice + توثيق per invoice | 🟡 | legacy `Modules/Expense` + operations bridge; per-invoice verify flag needs check |
| ACC-2.4 expense→asset wizard + drafts | ✅ | `asab_asset_drafts` + accountant assets routes |
| ACC-3 purchases 3-way match + توثيق + line edit | 🟡 | legacy `purchase_orders` surfaced; verify edit-lines + document endpoints |
| ACC-4 inventory daily/monthly + flags + confirmations | 🟡 | `asab_inventory_catalog`, `asab_branch_inventory_lists`, legacy Inventory; verify flag/notify/confirm loop (docs/MONTHLY_INVENTORY_FUNCTIONAL_REQUIREMENTS.md) |
| ACC-4.5 per-branch daily-count item lists + instant push | 🟡 | `asab_branch_inventory_lists` exists; verify push-notification wiring |
| ACC-5 waste docs + classification + responsibility | 🟡 | legacy Inventory waste tables; ASAB surface to verify |
| ACC-6 shifts live/setup/close/history | ✅/🟡 | `asab_shifts`, `asab_brand_shift_configs`, legacy Shift; verify live board + contact data |
| ACC-7 employee ledger | ✅ | `asab_employees`, `asab_employee_movements`; add settle-balance + add-movement if absent |
| ACC-8/HEAD-4 cash custody + replenish | ✅ | `asab_cash_custody`, `asab_cash_transactions`, `asab_settlement_requests` |
| HEAD-2 grouped final approval (accountant×module) + bulk | 🟡 | `Head/*` controllers; verify grouping + bulk + return-for-review |
| HEAD-3 accountant performance stats | ❌ | aggregation endpoints (rate, rating, counters) |
| PRC-2 request consolidation (item groups, savings) | 🟡 | `Procurement/*` + docs/PROCUREMENT_DASHBOARD_API.md; verify grouping/savings model |
| SUP-* supplier dashboard surface | 🟡 | legacy `Modules/Supplier` portal exists; ASAB dashboard surface `HIDDEN V1` |
| BRM-* branch manager dashboard surface | 🟡 | `Branch/*` controllers; verify upload forms + master-data endpoints |
| CMP-* company portal | ✅ | `/company/me/*` full surface (billing, users, modules, SSO, support) |
| RPT-* reports manager + P&L parse + owner dispatch | 🟡 | `ReportController`, `ReportBuilderService`; verify Excel P&L parsing + bulk send |
| ERP-* batches + export + API post | ✅/🟡 | `asab_erp_batches`, `ErpBatchService`; verify head filtered-export + failed-retry |
| 14.1 notifications + reminders | ✅ | `asab_notifications`, `asab_reminders`, `NotificationService` |
| Live updates (shifts, counters) | ❌ | broadcasting not wired for ASAB surfaces |
| §5.2b operation `origin` (mobile/procurement/system) | 🟡 | `asab_operations.source_module` exists — verify it maps to the 3-value origin enum + expose in payloads |
| §5.2c daily rollup state machine (branch/day aggregate) | ❌ | derivation service + endpoint for pipeline widget & completeness banners |
| ADM-8 company subscriptions incl. `trial` + grant-portal-account | 🟡 | `Admin/CompanyController` exists; verify trial status, conversion KPI, grant-account action |

> Also check `MISSING_Dashboard_ENDPOINTS_SPEC.md` — it is the repo's own gap list and
> should be reconciled with the table above when starting each epic.

---

## 17. Build Plan — Epics & Tasks

Ordered for dependency + meeting priorities (supplier hidden, reports deferred for
procurement/supplier, focus on main features). Each task = verify-first (the repo may
already cover it), then implement + Pest tests.

### Epic 0 — Baseline audit (do first)
- [ ] Run full test suite; fix any red baseline (`-d memory_limit=1024M`, SQLite guards).
- [ ] Diff `MISSING_Dashboard_ENDPOINTS_SPEC.md` against implemented routes; produce checklist.
- [ ] Smoke-test each existing role surface (admin/company/accountant/head/branch/procurement)
      against §6–§12 with a seeded demo tenant («مجموعة التاج للمطاعم» fixture recommended).

### Epic 1 — Accountant core review loop (highest value)
- [ ] ACC-0 dashboard endpoints: KPIs, module grid (pending/total/urgent), filters, inbox,
      bulk approve, pagination.
- [ ] ACC-1 sales: matching table, day pills + completeness (expected-vs-uploaded per day),
      detail reconciliation (channels), editable ledger with locked branch total,
      approve/reject/clarify, variance banner, Excel export.
- [ ] **Employee shortfall allocation service** (shared by sales + waste): validate full
      allocation, auto-name lookup by employee number, post movements atomically on approve.
- [ ] ACC-2 expenses: statement + multi-invoice table (pre-tax/VAT/incl-tax), per-invoice
      توثيق toggle, review modal data, missing/mismatch detection, approve/reject, bulk.
- [ ] ACC-3 purchases: 3-way match detail, توثيق + line edit (price/qty), approve/reject,
      supplier/branch filters, return orders, approved-suppliers tab.
- [ ] Rejection-reason enums (generic + sales-specific) + clarification request channel.

### Epic 2 — Head accountant final approval + ERP
- [ ] HEAD-2 grouped queues (accountant×module), bulk approve group / return-for-review,
      brand/module filter pills, locked final list, rejected list.
- [ ] §5.2c rollup state machine service (branch/day aggregate: empty→incomplete→
      ready_consolidation→consolidated→ready_erp→exported) + pipeline-widget endpoint;
      `origin` enum exposed on operations.
- [ ] HEAD-3 performance aggregations (approval rate, counters; rating source TBD).
- [ ] ERP batches: auto-batch on final approval (day+module), admin export log +
      bulk/selected export, head filtered export (Excel + API post), failed→retry.
- [ ] Lock rule NFR-10 enforced (immutable final-approved) + idempotent approvals.

### Epic 3 — Inventory & waste
- [ ] ACC-4.3 daily count equation view + approve; branch submit endpoint (BRM-4).
- [ ] ACC-4.4 monthly compare (% change, anomaly >50%/30% thresholds), flag branch/items,
      send-to-branch notification, branch confirm, record confirmation.
- [ ] ACC-4.5 per-branch item-list config + instant push notification to branch app.
- [ ] ACC-5 waste docs: classification/responsibility toggles, employee allocation reuse,
      approve/reject + ledger posting («خصم هدر»).

### Epic 4 — Shifts & money movement
- [ ] ACC-6 live board (open shifts + late detection vs config), setup (brand hours+float,
      admin-set/branch read-only), close flow (expected vs actual cash, diff), history+Excel.
- [ ] Shift approval chain: close → accountant → head → closed/next (meeting flow).
- [ ] ACC-7 employee ledger: add movement, settle balance, monthly statement, PDF/Excel,
      auto-salary-deduction rule.
- [ ] ACC-8/HEAD-4 custody: replenish (تعزيز) from treasury, min-threshold alerts,
      monthly ledger, near-depletion KPI (<500), disbursement requests.

### Epic 5 — Procurement loop (supplier surface hidden but API ready)
- [ ] PRC-2 consolidation: item-level grouping across branch requests, suggested supplier +
      savings calc from supplier prices, status chain new→grouped→sent→confirmed,
      partial approve / reject-with-reason (price higher than agreed etc.).
- [ ] PRC-3 own items & suppliers CRUD (scoped to purchasing profile), mobile filter contract.
- [ ] BRM-6 purchase requests (branch side) + urgency.
- [ ] SUP-1/2 supplier orders accept/reject + catalog CRUD (endpoints live, UI hidden v1).

### Epic 6 — Branch manager dashboard surface
- [ ] BRM-1 overview (target %, tasks-of-day state machine, crew list).
- [ ] BRM-2 daily upload forms → م1 operations with attachments.
- [ ] BRM-3 master-data tables + supplier request → procurement.
- [ ] BRM-7 settings (shift timings read-only).

### Epic 7 — Reports & owner dispatch
- [ ] RPT-1/2 Excel P&L upload + parse (revenue channels incl. aggregators, expense lines,
      net/margin), read-only preview payload, per-restaurant monthly table.
- [ ] RPT-3 dispatch: email + in-app + PDF/Excel attachment, cover message, bulk send,
      recipients = brand owners; status flip to sent; download.
- [ ] HEAD-7 / ACC-10 downloadable report cards (static generation acceptable v1).

### Epic 8 — Notifications, reminders, realtime, polish
- [ ] Notification triggers matrix (14.1) wired through events/listeners; head notified on
      every accountant action (meeting).
- [ ] Reminders CRUD + auto-reminder rules surfacing.
- [ ] Broadcasting for live shifts + bell counters (Pusher) or polling endpoints v1.
- [ ] Demo seeder: full «مجموعة التاج» tenant with 3 brands / 7 restaurants / 12 branches,
      sample ops in every stage — powers FE integration + tests.

### Deferred (explicit meeting decisions)
- Supplier module UI hidden; procurement/supplier reports; employee bulk-upload template;
  «Company Portal» advanced items beyond CMP-1..8 (module "similar but tackled later");
  م6 ERP-reports read stage (future).

---

*End of SRS v1.0 — generated from prototype analysis on 2026-07-09. Update §16 statuses as epics land.*




