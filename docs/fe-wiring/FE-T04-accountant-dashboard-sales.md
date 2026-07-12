# FE Wiring — T04 Accountant Dashboard & Sales (لوحة المحاسب والمبيعات)

> Backend module status: ✅ ready for integration · Delivered 2026-07-10 · Tests: 32 green
> Base URL: `/api/v1` · Auth: `Authorization: Bearer <token>` (Sanctum, guard `asab`)
> Envelopes (`AsabResponse`): success = bare object, or `{ "data": [...], "meta": {...} }` for lists.
> Errors: `{ "error": { "code", "message", "messageAr", "details" }, "requestId" }`.
> Money: integer **halalas** everywhere. Divide by 100, format `ar-SA`.
> **Read [FE-T03](FE-T03-operations-pipeline.md) first** — approve/reject/clarify/list/enums live there.

## Screens covered

| Prototype screen | Endpoints |
|---|---|
| «ملخص اليوم» dashboard: 4 KPI cards | `GET /company/me/accountant/dashboard` → `counts` |
| «الموديولات التسعة» grid + urgent red dot | same call → `modules[]` |
| «تقدم اليوم» progress bars | same call → `progressToday` |
| Scope subtitle «الفروع المخصصة: … · الموديولات: …» | same call → `scope` |
| «🔴 يحتاج انتباهاً فورياً» | same call → `needsAttention[]` |
| Sales KPI cards (ACC-1.1) + «تنبيه: توجد فروق» banner (ACC-1.5) | `GET /company/me/sales/kpis` |
| Day pills + «n عملية مطلوبة — m مكتملة · k ناقصة» (ACC-1.2) | `GET /company/me/sales/day-completeness` |
| «جدول مطابقة المبيعات» matching table (ACC-1.3) | `GET /operations?moduleKey=sales` → `salesBreakdown` |
| «جدول المقارنة والتسوية» channel table (ACC-1.4) | `GET /operations/{id}` → `reconciliation` |
| Edit mode «تعديل الأرقام» / «💾 حفظ» | `PATCH /company/me/operations/{id}/sales-details` |
| «تحميل الفرق على موظفين» allocation modal | `POST /company/me/operations/{id}/sales-variance/assign` + employee lookup |
| «المرفقات (3)» panel | `GET /operations/{id}/attachments` |
| «سجل النشاط» timeline · notes · approve/reject/clarify | T03 endpoints (`audit-trail`, `notes`, `approve`, `reject`, `request-clarification`) |

## Conventions

- Two surfaces, same handlers: company portal `/v1/company/me/*` (role `accountant`, `Idempotency-Key` honoured on mutations) and internal dashboard `/v1/accountant/*` (role `accountant,head`).
- **Canonical vs alias** — send the canonical:
  | Canonical | Alias (deprecated) |
  |---|---|
  | `PATCH /company/me/operations/{id}/sales-details` | `PATCH …/reconciliation`, `PATCH /accountant/operations/{id}/reconciliation` |
  | `POST /company/me/operations/{id}/sales-variance/assign` | `POST …/variance-allocations` (adds `remainingVarianceHalalas`) |
- `{id}` accepts uuid or `publicId` (`OPS-2401`).
- Everything is assigned-branch scoped: an accountant on brand A never sees brand B's numbers, and out-of-scope ids answer **404**.

---

## Endpoints

### 1. Accountant dashboard

`GET /api/v1/company/me/accountant/dashboard` · Role `accountant`
`GET /api/v1/accountant/dashboard` · Role `accountant,head` — same blocks, KPIs under `kpis` instead of `counts`, plus `recentOperations[]`

```json
{
  "today": "2026-07-10",
  "counts": {
    "awaitingReview": 13, "iApproved": 32, "finalApproved": 20, "rejected": 3,
    "newTodayCount": 45, "approvalRatePct": 71, "overdueCount": 2
  },
  "scope": {
    "branchCount": 1, "branchIds": ["b-1"], "isCompanyWide": false,
    "moduleKeys": ["sales", "expenses"], "moduleLabelsAr": ["المبيعات", "المصروفات"]
  },
  "modules": [
    { "key": "sales", "labelAr": "المبيعات", "icon": "💰", "pendingCount": 14, "totalCount": 42, "hasUrgent": true, "label": "المبيعات" }
  ],
  "progressToday": { "reviewPct": 75, "approvalPct": 50, "documentationPct": 50, "completedBranchesPct": 100, "operationsToday": 4 },
  "pendingByModule": { "sales": 14 },
  "needsAttention": [ { "operationId": "…", "refNum": "OPS-2399", "branch": "فرع مكة - المعابدة", "moduleLabel": "المشتريات", "match": "diff", "diff": "نقص في التحصيل: 350.00 ر.س" } ],
  "rejectedReuploadNeededCount": 3
}
```

Notes for FE:
- `modules[]` is **always the nine modules**, in catalogue order, zero-count included. `hasUrgent` = any pending record with `match:"diff"` **or** pending for more than 48h → the red dot.
- `approvalRatePct` is the accountant's own record: `approvedByMe ÷ (approvedByMe + rejectedByMe)`, `0` when they've reviewed nothing. **It used to be hardcoded `100`.**
- `iApproved` counts operations *this user* approved (`approved_by_id`), not "everything in approved state".
- `progressToday.*Pct` are 0–100 integers over today's operations; `completedBranchesPct` = branches that uploaded ÷ branches in scope.
- `pendingByModule` is the old sparse map, kept only for the pre-T04 screen. Bind `modules[]` instead.

### 2. Sales KPIs + variance banner

`GET /api/v1/company/me/sales/kpis?date=2026-07-10` (also `/accountant/sales/kpis`) · `date` defaults to today

```json
{
  "date": "2026-07-10",
  "totalSalesHalalas": 50958000,
  "branchCount": 8,
  "trendPct": 8.3,
  "totalCollectedHalalas": 50478000,
  "totalVarianceHalalas": -480000,
  "varianceCaseCount": 3,
  "zeroVarianceBranchCount": 5,
  "varianceBranches": [ { "branchId": "b-1", "name": "فرع الرياض - العليا", "varianceHalalas": -320000 } ]
}
```

- `trendPct` compares against the previous day; **`null`** when there were no sales the prior day (render "—", not 0%).
- Only **reconciled** operations contribute to `totalCollectedHalalas` / variance. Sales totals come from the locked `amount`.
- `varianceBranches[]` drives the red ACC-1.5 banner.

### 3. Day completeness (day pills)

`GET /api/v1/company/me/sales/day-completeness?days=7` (1–31, default 7; also `/accountant/sales/day-completeness`)

```json
{
  "data": [
    {
      "date": "2026-07-10", "pillLabelAr": "اليوم",
      "requiredCount": 3, "completedCount": 1, "missingCount": 2,
      "bannerAr": "3 عملية مطلوبة — 1 مكتملة · 2 ناقصة",
      "missingBranches": [ { "branchId": "b-2", "name": "فرع جدة - الحمراء" } ]
    }
  ]
}
```

Newest day first. `pillLabelAr` is `اليوم / أمس / قبل يومين / قبل N أيام`, then the ISO date past a week.
**Rule:** every in-scope branch owes one sales statement per day, so `requiredCount` = branches in scope.

### 4. Operation detail — channel reconciliation table

`GET /api/v1/operations/{id}` (T03 endpoint). For `moduleKey: "sales"` it now carries a `reconciliation` block — `null` until the accountant reconciles:

```json
{
  "reconciliation": {
    "channels": [
      { "key": "cash", "labelAr": "نقدي (صندوق)", "icon": "💵", "group": "core",
        "posAmountHalalas": 420000, "actualAmountHalalas": 420000, "diffHalalas": 0,
        "status": "exact", "statusLabelAr": "متطابق" },
      { "key": "jahez", "labelAr": "جاهز", "icon": "🟡", "group": "delivery",
        "posAmountHalalas": 135000, "actualAmountHalalas": 120000, "diffHalalas": -15000,
        "status": "diff", "statusLabelAr": "فرق" }
    ],
    "totals": {
      "expectedTotalHalalas": 1000000, "totalCollectionHalalas": 1000000,
      "varianceHalalas": 0, "status": "exact", "statusLabelAr": "مطابق"
    },
    "varianceReason": null,
    "isLocked": false
  }
}
```

- `group` splits the table: `core` rows first, `delivery` rows under the «تطبيقات التوصيل» heading.
- `isLocked` = operation is `final-approved` → hide the edit UI (the server 409s anyway).
- `expectedTotalHalalas` is the branch's locked total («من رفع مدير الفرع — مقفل»).

### 5. Save a reconciliation ⚠️ idempotent

`PATCH /api/v1/company/me/operations/{id}/sales-details` · Role `accountant`

Canonical body:

```json
{ "channels": [
    { "key": "cash", "actualAmountHalalas": 420000, "posAmountHalalas": 420000 },
    { "key": "jahez", "actualAmountHalalas": 120000 }
  ],
  "varianceReason": "فرق قناة جاهز" }
```

| Field | Type | Required | Validation |
|---|---|---|---|
| `channels[].key` | string | yes | one of the 8 channel keys below |
| `channels[].actualAmountHalalas` | int | yes | the entered amount |
| `channels[].posAmountHalalas` | int | no | expected; omitted → keeps the branch-reported value |
| `varianceReason` | string | no | ≤80 |

Response `200`: `{ id, reconciliation: {…as §4}, totalCollectionHalalas, varianceHalalas, match }`.

- **The total is never an input.** `varianceHalalas = collected − amount` (a **shortfall is negative**).
- Saving **re-derives the match badge**: variance 0 → `match: "exact"` and `diffNote: null`; otherwise `match: "diff"` and `diffNote: "نقص في التحصيل: 350.00 ر.س"` (or «زيادة في التحصيل»).
- Errors: `422 UNKNOWN_SALES_CHANNEL` (`details.allowed` lists the keys) · `409 OP_ALREADY_FINAL`.
- **Legacy body still accepted** (one release): `{ cashHalalas, bankHalalas, deliveryApps: [{name, amountHalalas}] }` — the app name is folded onto a canonical key («هنقرستيشن» → `hungerstation`); an unrecognised name now 422s.

### 6. Allocate the shortfall to employees ⚠️ idempotent

`POST /api/v1/company/me/operations/{id}/sales-variance/assign` · Role `accountant`

```json
{ "allocations": [ { "empNumber": "1001", "amountHalalas": 20000 },
                   { "employeeId": "…uuid…", "amountHalalas": 15000 } ],
  "notes": "فرق قناة جاهز" }
```

Response `200`:

```json
{ "operationId": "…", "varianceTotalHalalas": 35000,
  "allocations": [ { "id": "…", "employeeId": "…", "employeeName": "محمد العتيبي",
                     "amountHalalas": 20000, "category": "sales_variance",
                     "categoryLabelAr": "فرق مبيعات", "appliedAt": "…" } ],
  "remainingUnallocatedHalalas": 0, "remainingVarianceHalalas": 0 }
```

- **The allocations must sum to `abs(variance)`** — partial *and* over allocation return `422` with `messageAr: "يجب أن يساوي مجموع التخصيصات قيمة الفارق"` and post **nothing**. (Before T04 a shortfall bypassed this check entirely.)
- The whole set is one transaction: an unknown/out-of-branch employee (`422`) rolls back the movements already staged.
- `409 OP_ALREADY_FINAL` on a locked or rejected operation.
- Quick-fill «⚡ المتبقي» = `remainingVarianceHalalas` from the previous response (or `abs(variance) − Σ entered`).
- Posts `debit` rows on the employee statement with `category: "sales_variance"` («فرق مبيعات»), description `فرق مبيعات — OPS-2401 — {notes}`.

### 7. Employee name auto-fill

`GET /api/v1/company/me/branches/{branchId}/employees/lookup?empNumber=1001` → `{ "empNumber": "1001", "name": "محمد العتيبي" }` · `404` when unknown or in another branch.

### 8. Matching table rows

`GET /api/v1/operations?moduleKey=sales` (T03 list). Sales rows now carry:

```json
{ "salesBreakdown": { "cashHalalas": 400000, "cardHalalas": 400000, "appsHalalas": 150000,
                      "totalSalesHalalas": 1000000, "collectedHalalas": 950000, "varianceHalalas": -50000 } }
```

`null` until the operation is reconciled (render «—»). `cashHalalas` folds `cash` + `pos`; `appsHalalas` folds the five delivery channels. Non-sales rows carry `salesBreakdown: null`.

### 9. Attachments panel

`GET /api/v1/operations/{id}/attachments` (also `/company/me/operations/{id}/attachments`)

```json
{ "data": [ { "id": "…", "filename": "تقرير POS الرئيسي.pdf", "mimeType": "application/pdf",
              "size": 245000, "publicUrl": "https://…", "label": null,
              "verifiedAt": null, "uploadedAt": "2026-07-10T09:15:00+03:00" } ],
  "meta": { "total": 3 } }
```

Matches the operation's `attachmentCount`. Download/verify/delete a single file via the existing `/attachments/{id}` endpoints.

### 10. Exports

- List: `GET /company/me/operations/export?moduleKey=sales&format=xlsx|csv` — assigned-branch scoped.
- Single operation: `GET /company/me/operations/{id}/export` — now **404s** on an out-of-scope operation, and the sales sheet appends the channel rows + «إجمالي التحصيل» + «الفرق».

### 11. Internal list surface

`GET /api/v1/accountant/operations` gained `dateFrom`, `dateTo` and `search` (parity with the T03 list). Prefer `GET /operations` (richer filters, label pairs).

---

## Enums

**Sales channels** (`channels[].key`) — full catalogue also at `GET /lookups/operation-enums` (T03) for status/match:

| Key | labelAr | icon | group |
|---|---|---|---|
| `pos` | كاشير (POS) | 🖥️ | core |
| `cash` | نقدي (صندوق) | 💵 | core |
| `bank` | بنكي / بنك الرياض (مدى) | 🏦 | core |
| `talabat` | طلبات | 🔴 | delivery |
| `hungerstation` | هنقرستيشن | 🟠 | delivery |
| `jahez` | جاهز | 🟡 | delivery |
| `toyou` | تو يو (ToYou) | 🔵 | delivery |
| `ninja` | نينجا | ⚫ | delivery |

**Modules** (`modules[].key`, nine, catalogue order): `sales` المبيعات 💰 · `expenses` المصروفات 🧾 · `purchases` المشتريات 🛒 · `inventory` المخزون 📦 · `waste` الهدر 🗑️ · `assets` الأصول 🏷️ · `shifts` الورديات 🕐 · `employees` الموظفين 👥 · `cash` النقدية 💵

**Movement category**: `sales_variance` → «فرق مبيعات» (employee-statement debits raised by this module).

**Reject reasons** (T03 lookup) — sales adds `missing_pos_report` «تقرير POS مفقود» and `missing_bank_statement` «كشف البنك غير مرفق».

## Error codes (module-specific)

| HTTP | code | When |
|---|---|---|
| 422 | `UNKNOWN_SALES_CHANNEL` | channel key/name not in the enum (`details.allowed`) |
| 422 | `VALIDATION_ERROR` (`messageAr: يجب أن يساوي…`) | allocations ≠ `abs(variance)` |
| 422 | `VALIDATION_ERROR` (`messageAr: الموظف غير موجود في الفرع`) | employee not in the operation's branch |
| 409 | `OP_ALREADY_FINAL` | reconcile / allocate / edit a locked or rejected record |
| 404 | `NOT_FOUND` | out-of-scope operation, branch or employee |

## Behaviour changes shipped in T04

1. `approvalRatePct` is computed (was hardcoded `100`).
2. The module grid is always **nine** modules with `totalCount` + `hasUrgent` (was a sparse 6-module pending-only map).
3. Reconciliation is **channel-based and validated**; free-text channel names now 422.
4. **Shortfall allocations must be complete** — the old guard never fired on negative variance.
5. Employee movements carry `category` (`sales_variance`).
6. `match` / `diffNote` are re-derived on every reconciliation save (badges no longer go stale).
7. Single-op export is assigned-branch scoped (was tenant-only).

## Still missing (other tasks)

- Bulk **final**-approve, return-for-review, grouped head queue → **T10**.
- Expense multi-invoice + توثيق + asset conversion → **T05**.
- Purchases 3-way match / توثيق / line edit → **T06**.

## Test fixtures

`tests/Feature/AccountantDashboardTest.php`, `SalesReconciliationTest.php`, `SalesVarianceAllocationTest.php` (32 tests).
