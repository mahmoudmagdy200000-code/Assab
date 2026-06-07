# ASAB — Missing Backend Endpoints (Frontend-Driven Audit)

> **Generated:** 2026-06-02
> **Source:** UI audit of the live frontend codebase (`artifacts/mockup-sandbox/src/`)
> **Status:** All 357 endpoints in `ASAB_FRONTEND_INTEGRATION.md §11` are wired in `src/api/queries/`. This file lists endpoints the **UI consumes but the backend contract does not yet expose** (or exposes ambiguously without payload shape).
>
> **For the backend team:** implement the endpoints below to remove the remaining mock fallbacks in the UI. Each entry includes: method, path, query/body, response, side-effects, and the frontend page that drives the need.

---

## Table of contents
1. [Conventions (same as the integration brief)](#1-conventions)
2. [Bulk/list Excel exports (per module)](#2-bulklist-excel-exports-per-module)
3. [Pipeline & aggregation dashboards (shape definitions)](#3-pipeline--aggregation-dashboards-shape-definitions)
4. [Head Accountant — performance & charts](#4-head-accountant--performance--charts)
5. [Company Admin — brand performance breakdown](#5-company-admin--brand-performance-breakdown)
6. [Admin audit log enhancements](#6-admin-audit-log-enhancements)
7. [Notification preferences](#7-notification-preferences)
8. [Forgot-password OTP resend](#8-forgot-password-otp-resend)
9. [Inventory daily reconciliation summary](#9-inventory-daily-reconciliation-summary)
10. [Sales variance employee allocation](#10-sales-variance-employee-allocation)
11. [Cross-cutting enhancements](#11-cross-cutting-enhancements)

---

## 1. Conventions

Identical to `ASAB_FRONTEND_INTEGRATION.md §3-4`. Recap:
- Envelope: bare object for single, `{data, meta}` for list, `{error:{code, messageAr}}` for errors.
- Money in integer **halalas** (1 SAR = 100 halalas).
- All JSON keys are **camelCase**.
- Bulk exports return either a **binary blob (sync 200)** with `Content-Disposition`, or **202 + jobId** for async (then poll `/company/me/exports/{jobId}/download`).

---

## 2. Bulk/list Excel exports (per module)

The frontend has Excel-export buttons on every list page. Currently `useExportShifts/useExportPayroll/useExportWaste/useExportCash` are wired, but **sales, expenses, purchases, assets, reminders, head ops, and supplier catalogs** still trigger `alert()` placeholders. Add a uniform export pattern.

**Pattern:** `GET /<list-endpoint>/export?format=xlsx|csv` returns the binary directly (`200` + blob) honoring the same filters as the list.

### 2.1 Sales bulk export
```
GET /api/v1/company/me/operations/export?moduleKey=sales&format=xlsx
GET /api/v1/company/me/operations/export?moduleKey=sales&dateFrom=&dateTo=&brandId=&branchId=&status=
```
- **Response:** `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` (xlsx) — columns:
  `OPS ID · Branch · Brand · Module · Amount (SAR) · Status · Match · Submitted By · Date · Reviewed By · Reviewed At · Notes`
- **Side-effect:** none (read-only).
- **UI:** `AccCompanySales` → "تصدير Excel" button (currently `alert(...)`).

### 2.2 Expenses bulk export
```
GET /api/v1/company/me/operations/export?moduleKey=expenses&format=xlsx
```
- Same shape as 2.1 + extra columns: `Vendor · Invoice Number · Verified · Converted to Asset`.
- **UI:** `AccCompanyExpenses`.

### 2.3 Purchases bulk export
```
GET /api/v1/company/me/operations/export?moduleKey=purchases&format=xlsx
```
- Columns: `PO · Supplier · Branch · Total · Items Count · Approval Status · Sent Status`.
- **UI:** `AccCompanyPurchases`.

### 2.4 Assets register export
```
GET /api/v1/company/me/assets/export?format=xlsx&category=&branchId=
```
- Columns: `FA Code · Name · Category · Branch · Purchase Date · Purchase Price (SAR) · Useful Life (months) · Monthly Depreciation (SAR) · NBV (SAR) · Custodian · Status`.
- **UI:** `AccAssets` → "تصدير سجل الأصول الثابتة إلى Excel".

### 2.5 Accountant reminders export
```
GET /api/v1/company/me/accountant/reminders/export?format=xlsx
```
- Columns: `Branch · Missing Item · Days Late · Last Reminder Sent · Response · Reminder Type`.
- **UI:** `AccReminders` → "تصدير التذكيرات المفقودة إلى Excel".

### 2.6 Head operations export (by status/module)
```
GET /api/v1/operations/export?status=pending|approved|rejected|final-approved&moduleKey=&format=xlsx
```
- Available to roles `head, accountant`. Honors `?dateFrom`, `?dateTo`, `?brandId`.
- **UI:** `HeadApprovalTab`, `HeadDashboard` per-tab export.

### 2.7 Supplier catalog export
```
GET /api/v1/asab/supplier/items/export?format=xlsx
GET /api/v1/company/me/procurement/items/export?format=xlsx
```
- Columns: `Item Code · Name · Category · Unit · Last Price (SAR) · Preferred Supplier · Active`.
- **UI:** `SupItems`, `ProcItems`.

### 2.8 Suppliers list export
```
GET /api/v1/company/me/suppliers/export?format=xlsx
```
- Columns: `Supplier Name · Category · Phone · Email · Rating · Orders Count · Active`.
- **UI:** `ProcSuppliers`.

### 2.9 Accepted/rejected orders export (supplier portal)
```
GET /api/v1/asab/supplier/orders/export?status=accepted|rejected&format=xlsx
```
- **UI:** `SupNewOrders`, `SupReports`.

---

## 3. Pipeline & aggregation dashboards (shape definitions)

`GET /pipeline/overview`, `GET /modules/aggregation`, and `GET /exceptions` exist in §11 but **payload shapes are undocumented**. The frontend renders these in `PipelineOverview`, `ModuleAggregationGrid`, and `ExceptionPanel` components on the Head dashboard.

### 3.1 Pipeline overview
```
GET /api/v1/pipeline/overview
```
**Response:**
```json
{
  "stages": [
    { "key": "submitted",      "labelAr": "تم الرفع",       "count": 142 },
    { "key": "pending",        "labelAr": "بانتظار المراجعة","count": 58 },
    { "key": "approved",       "labelAr": "معتمد محاسب",     "count": 23 },
    { "key": "final-approved", "labelAr": "اعتماد نهائي",    "count": 71 },
    { "key": "erp-posted",     "labelAr": "مُرحَّل ERP",      "count": 19 },
    { "key": "rejected",       "labelAr": "مرفوض",           "count": 8 }
  ],
  "throughputToday": { "submittedCount": 14, "completedCount": 11 },
  "avgCycleTimeHours": 6.4
}
```
- **Scope:** company-scoped for non-admin roles. Admin gets a `?companyId=` query option for cross-tenant view.

### 3.2 Module aggregation
```
GET /api/v1/modules/aggregation?dateFrom=&dateTo=
```
**Response:**
```json
{
  "modules": [
    {
      "moduleKey": "sales",
      "moduleLabelAr": "المبيعات",
      "totalCount": 320,
      "totalAmountHalalas": 18450000,
      "pendingCount": 22,
      "approvedCount": 248,
      "rejectedCount": 12,
      "finalApprovedCount": 38
    },
    {
      "moduleKey": "expenses",
      "moduleLabelAr": "المصروفات",
      "totalCount": 195,
      "totalAmountHalalas": 4820000,
      "pendingCount": 15,
      "approvedCount": 162,
      "rejectedCount": 8,
      "finalApprovedCount": 10
    }
  ]
}
```
- **Module keys:** `sales · expenses · purchases · inventory · shifts · employees · cash · waste`.
- **UI:** Head dashboard, Accountant dashboard tiles.

### 3.3 Exceptions
```
GET /api/v1/exceptions?severity=&moduleKey=&branchId=
```
**Response (list with envelope):**
```json
{
  "data": [
    {
      "id": "exc_01HXY...",
      "type": "cash_variance",
      "typeLabelAr": "فرق نقدي",
      "moduleKey": "sales",
      "severity": "high",
      "branchId": "br_...",
      "branchName": "فرع الرياض - العليا",
      "amountHalalas": 235000,
      "occurredAt": "2026-06-01T09:30:00Z",
      "linkedOperationId": "op_...",
      "messageAr": "فارق كاش 2,350 ر.س — يتطلب تخصيص لموظف",
      "actionable": true,
      "actionLabel": "تخصيص"
    }
  ],
  "meta": { "total": 7, "page": 1, "pageSize": 20, "totalPages": 1 }
}
```
- **Exception types:** `cash_variance, missing_invoice, late_report, inventory_flag, asset_unconfirmed, shift_unclosed, quota_warning`.
- **Severity:** `low, medium, high, critical`.

### 3.4 Exceptions lookup
```
GET /api/v1/lookups/exceptions
```
**Response:** `{ data: Array<{ value, labelAr, labelEn, defaultSeverity }> }`

---

## 4. Head Accountant — performance & charts

The `HeadDashboard` and `HeadAccountants` components render weekly charts and per-accountant performance metrics. Current `/head/dashboard` and `/head/accountants/performance` exist but their response shapes are partial.

### 4.1 Head dashboard — enriched response
```
GET /api/v1/head/dashboard
GET /api/v1/company/me/head/dashboard
```
**Response (extend current shape with these fields):**
```json
{
  "kpis": {
    "performanceRatePct": 87,
    "totalReviewedThisMonth": 935,
    "totalApprovedThisMonth": 839,
    "totalRejectedThisMonth": 28,
    "avgReviewTimeMinutes": 4.85,
    "vsLastMonthDeltaPct": 12.4
  },
  "weeklyPerformance": [
    { "day": "Sun", "dayAr": "الأحد",   "thisWeek": 24, "lastWeek": 18 },
    { "day": "Mon", "dayAr": "الاثنين", "thisWeek": 32, "lastWeek": 22 },
    { "day": "Tue", "dayAr": "الثلاثاء","thisWeek": 19, "lastWeek": 28 },
    { "day": "Wed", "dayAr": "الأربعاء","thisWeek": 41, "lastWeek": 31 },
    { "day": "Thu", "dayAr": "الخميس", "thisWeek": 35, "lastWeek": 25 },
    { "day": "Fri", "dayAr": "الجمعة", "thisWeek": 12, "lastWeek":  9 },
    { "day": "Sat", "dayAr": "السبت",  "thisWeek": 28, "lastWeek": 20 }
  ]
}
```

### 4.2 Accountants performance — enriched response
```
GET /api/v1/head/accountants/performance?dateFrom=&dateTo=
GET /api/v1/company/me/head/accountants/performance
```
**Response (array of accountants, each with these fields):**
```json
[
  {
    "id": "u_...",
    "name": "أحمد محمد الشهري",
    "branchesAssignedCount": 20,
    "reviewedCount": 250,
    "approvedCount": 230,
    "pendingCount": 5,
    "approvalRatePct": 92,
    "previousMonthRatePct": 88,
    "rating": 4.8,
    "avgReviewMinutes": 4.5,
    "level": "excellent",
    "levelLabelAr": "ممتاز"
  }
]
```
**Levels:** `excellent (>=90) · good (75-89) · acceptable (60-74) · needs_improvement (<60)`.

### 4.3 Head recent movements feed
```
GET /api/v1/head/movements/recent?limit=10
```
**Response:**
```json
{
  "data": [
    { "id": "mv_1", "actionAr": "اعتماد مبيعات فرع العليا", "timeAr": "قبل 12 دقيقة", "module": "sales",     "moduleLabelAr": "مبيعات" },
    { "id": "mv_2", "actionAr": "رفض مشتريات — فرق",       "timeAr": "قبل 45 دقيقة", "module": "purchases", "moduleLabelAr": "مشتريات" }
  ]
}
```
- **UI:** `HeadAccountants` activity feed (currently uses `RECENT_MOVEMENTS_FALLBACK`).

---

## 5. Company Admin — brand performance breakdown

The CADashboard renders per-brand sales vs target tiles. Current `/company/me/dashboard` returns top-level KPIs but **not the per-brand breakdown**.

### 5.1 Brand performance breakdown
```
GET /api/v1/company/me/dashboard/brand-performance
```
**Response:**
```json
{
  "brands": [
    {
      "id": "br_reem",
      "name": "مطعم الريم",
      "abbr": "ر",
      "color": "#7C3AED",
      "branchesCount": 4,
      "monthlySalesHalalas": 1842000000,
      "monthlyTargetHalalas": 2000000000,
      "achievementPct": 92,
      "monthlyExpensesHalalas": 615000000,
      "netProfitHalalas": 1227000000
    }
  ],
  "branchCompletionRate": 83,
  "branchesAboveTarget": 10,
  "totalBranchesCount": 12
}
```

### 5.2 Company dashboard — enrich existing endpoint
Add these fields to the existing `GET /api/v1/company/me/dashboard` response (under a `kpis` object):
```json
{
  "kpis": {
    "totalSalesHalalas": 18450000,
    "totalExpensesHalalas": 6150000,
    "netProfitHalalas": 12300000,
    "salesDeltaVsLastMonthPct": 8.2,
    "profitDeltaVsLastMonthPct": 12.4,
    "branchCompletionRate": 83,
    "branchesAboveTarget": 10
  },
  "totals": {
    "brandsCount": 4,
    "restaurantsCount": 7,
    "branchesCount": 12,
    "usersCount": 31
  }
}
```

---

## 6. Admin audit log enhancements

### 6.1 Audit log Excel export
```
GET /api/v1/admin/audit-logs/export?format=xlsx&userFilter=&actionType=&dateRange=
```
- **Response:** binary xlsx, columns matching the UI table:
  `Date · Time · User · Action · Type · Target Entity · IP Address · Notes`.
- **UI:** `AdminAudit` → "تصدير Excel" (currently `alert(...)`).

### 6.2 Audit log filter metadata
```
GET /api/v1/admin/audit-logs/filters
```
**Response:**
```json
{
  "actionTypes": [
    { "value": "users",         "labelAr": "مستخدمين",  "labelEn": "Users" },
    { "value": "approvals",     "labelAr": "اعتمادات",   "labelEn": "Approvals" },
    { "value": "subscriptions", "labelAr": "اشتراكات",   "labelEn": "Subscriptions" },
    { "value": "rejection",     "labelAr": "رفض",        "labelEn": "Rejection" },
    { "value": "export",        "labelAr": "تصدير",      "labelEn": "Export" },
    { "value": "inventory",     "labelAr": "مخزون",      "labelEn": "Inventory" },
    { "value": "permissions",   "labelAr": "صلاحيات",    "labelEn": "Permissions" },
    { "value": "purchases",     "labelAr": "مشتريات",    "labelEn": "Purchases" }
  ]
}
```

---

## 7. Notification preferences

The `BACKEND_API_SPEC.md §6.4.13` lists `/notifications/preferences` but it's **not in `ASAB_FRONTEND_INTEGRATION.md §11`**. Add to the index and confirm shape.

### 7.1 Get preferences
```
GET /api/v1/notifications/preferences
```
**Response:**
```json
{
  "channels": {
    "inApp":  { "enabled": true },
    "email":  { "enabled": true,  "address": "user@company.sa" },
    "push":   { "enabled": false },
    "whatsapp": { "enabled": false }
  },
  "events": {
    "operation.created":         { "inApp": true,  "email": false, "push": false },
    "operation.status_changed":  { "inApp": true,  "email": false, "push": false },
    "approval.pending":          { "inApp": true,  "email": true,  "push": true  },
    "invoice.paid":              { "inApp": true,  "email": true,  "push": false },
    "subscription.expiring":     { "inApp": true,  "email": true,  "push": false },
    "quota.warning":             { "inApp": true,  "email": true,  "push": false },
    "reminder.responded":        { "inApp": true,  "email": false, "push": false }
  },
  "quietHours": { "enabled": false, "startsAt": "22:00", "endsAt": "07:00" }
}
```

### 7.2 Update preferences
```
PATCH /api/v1/notifications/preferences
```
**Body:** partial shape of 7.1 (any subset).
**Response:** the full updated preferences object.

---

## 8. Forgot-password OTP resend

`/auth/forgot-password` exists. Frontend needs a "resend OTP" path after the initial request (rate-limited).

### 8.1 Resend reset OTP/email
```
POST /api/v1/auth/forgot-password/resend
```
**Body:** `{ "email": "user@company.sa" }`
**Response:** `{ "ok": true, "nextResendAvailableAt": "2026-06-02T10:35:00Z" }`
**Errors:**
- `RATE_LIMITED` (429): `{ messageAr: "يرجى الانتظار قبل إعادة الإرسال", details: { nextResendAvailableAt } }`
- `NOT_FOUND` (404): silently return `ok:true` to avoid user enumeration.

**UI:** `ForgotPasswordPage` "إعادة إرسال" button (no endpoint currently).

---

## 9. Inventory daily reconciliation summary

The accountant inventory page has a **reconciliation tab** that surfaces daily variance per item per branch, with employee allocation. Current `/accountant/inventory/branches/{branchId}/daily-reconciliation` exists in spec §6.3 but **not in §11 index** and the response shape isn't defined.

### 9.1 Daily reconciliation snapshot
```
GET /api/v1/accountant/inventory/branches/{branchId}/daily-reconciliation?date=2026-06-01
```
**Response:**
```json
{
  "branchId": "br_...",
  "branchName": "فرع الرياض - العليا",
  "date": "2026-06-01",
  "items": [
    {
      "itemId": "it_...",
      "itemName": "دجاج طازج",
      "unit": "كجم",
      "expectedQty": 50,
      "actualQty": 47.5,
      "varianceQty": 2.5,
      "variancePct": 5.0,
      "varianceValueHalalas": 87500,
      "status": "flagged",
      "allocatedTo": [
        { "employeeId": "emp_...", "employeeName": "أحمد سعيد", "qty": 1.5, "valueHalalas": 52500 },
        { "employeeId": "emp_...", "employeeName": "خالد محمد", "qty": 1.0, "valueHalalas": 35000 }
      ]
    }
  ],
  "totalVarianceValueHalalas": 87500,
  "unassignedVarianceValueHalalas": 0
}
```

### 9.2 Save daily variance allocation
```
POST /api/v1/accountant/inventory/branches/{branchId}/daily-variance-allocation
```
**Body:**
```json
{
  "date": "2026-06-01",
  "items": [
    {
      "itemId": "it_...",
      "allocations": [
        { "employeeId": "emp_...", "qty": 1.5 },
        { "employeeId": "emp_...", "qty": 1.0 }
      ]
    }
  ]
}
```
**Response:** the updated 9.1 response.
**Side-effects:**
- Posts to employee statement (employee_movements) as a debit.
- Emits `inventory.variance_allocated` event on `operations.brand.{id}`.

---

## 10. Sales variance employee allocation

When a sales operation has a cash variance, accountant assigns it to one or more employees. The current `POST /operations/{id}/sales-variance/assign` exists but **response shape is undocumented** and the **employee lookup endpoint** for the branch is needed (already added — `GET /company/me/branches/{branchId}/employees/lookup`).

### 10.1 Sales variance assign — formalize body and response
```
POST /api/v1/operations/{operationId}/sales-variance/assign
```
**Body:**
```json
{
  "allocations": [
    { "employeeId": "emp_...", "amountHalalas": 150000 },
    { "employeeId": "emp_...", "amountHalalas":  85000 }
  ],
  "notes": "تم تخصيص الفارق على شفت المساء"
}
```
**Response:**
```json
{
  "operationId": "op_...",
  "varianceTotalHalalas": 235000,
  "allocations": [
    {
      "id": "alloc_...",
      "employeeId": "emp_...",
      "employeeName": "أحمد سعيد",
      "amountHalalas": 150000,
      "appliedAt": "2026-06-01T15:30:00Z"
    }
  ],
  "remainingUnallocatedHalalas": 0
}
```
**Validation:** `sum(allocations.amountHalalas) === operation.varianceHalalas` else `422 VALIDATION_ERROR`.

---

## 11. Cross-cutting enhancements

### 11.1 Saved filter presets
The frontend has complex filter combinations on Sales/Expenses/Purchases pages. Users repeatedly apply the same filters.
```
GET    /api/v1/users/me/saved-filters?page=acc-sales
POST   /api/v1/users/me/saved-filters
DELETE /api/v1/users/me/saved-filters/{id}
```
**POST body:**
```json
{
  "name": "مراجعة فرع جدة",
  "page": "acc-sales",
  "params": { "brandId": "br_...", "branchId": "br_...", "status": "pending" }
}
```
**GET response:**
```json
{
  "data": [
    { "id": "sf_...", "name": "مراجعة فرع جدة", "page": "acc-sales",
      "params": { "brandId": "...", "branchId": "...", "status": "pending" },
      "createdAt": "2026-05-15T10:00:00Z" }
  ]
}
```

### 11.2 Activity heatmap (per-accountant)
For the accountant dashboard, a heatmap of activity hours.
```
GET /api/v1/accountant/dashboard/activity-heatmap?dateFrom=&dateTo=
```
**Response:**
```json
{
  "hours": [
    { "hour":  9, "count": 23 },
    { "hour": 10, "count": 41 },
    { "hour": 11, "count": 38 }
  ],
  "byDay": [
    { "day": 0, "dayAr": "الأحد", "totalCount": 142 },
    { "day": 1, "dayAr": "الاثنين", "totalCount": 168 }
  ]
}
```

### 11.3 Quick-stat widget refresh
For the global header KPIs (notifications + quick metrics).
```
GET /api/v1/users/me/quick-stats
```
**Response:**
```json
{
  "pendingApprovalsCount": 15,
  "unreadNotificationsCount": 3,
  "todayOperationsCount": 28,
  "openRemindersCount": 5
}
```
**Polling:** every 60 seconds OR refresh on relevant Pusher events.

### 11.4 Saved column preferences (table layout)
For data-heavy tables, users want to hide/reorder columns persistently.
```
GET   /api/v1/users/me/table-prefs?table=ops-list
PUT   /api/v1/users/me/table-prefs
```
**PUT body:**
```json
{
  "table": "ops-list",
  "visibleColumns": ["publicId", "branch", "amount", "status", "submittedAt"],
  "columnOrder": ["publicId", "branch", "amount", "status", "submittedAt"],
  "pageSize": 50
}
```

### 11.5 Bulk reminder broadcast (already exists, needs response shape)
```
POST /api/v1/reminders/broadcast
```
**Body:**
```json
{
  "messageAr": "تذكير: تقرير الفرع اليومي قبل الساعة 11 مساءً",
  "messageEn": "Reminder: Daily branch report due before 11 PM",
  "audience": "all-branch-managers",
  "branchIds": []
}
```
**Response:** `{ "sentCount": 24, "failedCount": 0, "broadcastId": "br_..." }`
**Audiences:** `all-branch-managers · all-accountants · all-suppliers · specific-branches (with branchIds)`.

---

## Summary table — net new endpoints

| # | Method · Path | Purpose | Priority |
|---|---------------|---------|---------|
| 1 | `GET /company/me/operations/export?moduleKey=` | Sales/expenses/purchases bulk Excel | **High** |
| 2 | `GET /company/me/assets/export` | Assets register Excel | High |
| 3 | `GET /company/me/accountant/reminders/export` | Reminders Excel | Medium |
| 4 | `GET /operations/export?status=` | Head ops export | High |
| 5 | `GET /asab/supplier/items/export` | Supplier catalog Excel | Medium |
| 6 | `GET /company/me/procurement/items/export` | Procurement catalog Excel | Medium |
| 7 | `GET /company/me/suppliers/export` | Suppliers Excel | Medium |
| 8 | `GET /asab/supplier/orders/export?status=` | Supplier orders Excel | Medium |
| 9 | `GET /pipeline/overview` (define shape) | Head pipeline tiles | **High** |
| 10 | `GET /modules/aggregation` (define shape) | Module rollup | High |
| 11 | `GET /exceptions` (define shape) | Exception panel | High |
| 12 | `GET /lookups/exceptions` (define shape) | Filter dropdown | Low |
| 13 | `GET /head/dashboard` (enrich shape) | Weekly chart + perf KPIs | **High** |
| 14 | `GET /head/accountants/performance` (enrich shape) | Performance cards | **High** |
| 15 | `GET /head/movements/recent` | Activity feed | Medium |
| 16 | `GET /company/me/dashboard/brand-performance` | Per-brand tiles | **High** |
| 17 | `GET /company/me/dashboard` (enrich) | Top-level KPIs + totals | **High** |
| 18 | `GET /admin/audit-logs/export` | Audit Excel | Medium |
| 19 | `GET /admin/audit-logs/filters` | Filter dropdowns | Low |
| 20 | `GET /notifications/preferences` | Notification settings page | Medium |
| 21 | `PATCH /notifications/preferences` | Save settings | Medium |
| 22 | `POST /auth/forgot-password/resend` | OTP resend | Medium |
| 23 | `GET /accountant/inventory/branches/{id}/daily-reconciliation` (define shape) | Reconciliation tab | High |
| 24 | `POST /accountant/inventory/branches/{id}/daily-variance-allocation` (define body) | Allocate to employees | High |
| 25 | `POST /operations/{id}/sales-variance/assign` (define body+response) | Cash variance allocation | High |
| 26 | `GET/POST/DELETE /users/me/saved-filters` | Persistent filter presets | Low |
| 27 | `GET /accountant/dashboard/activity-heatmap` | Heatmap widget | Low |
| 28 | `GET /users/me/quick-stats` | Header polling | Medium |
| 29 | `GET/PUT /users/me/table-prefs` | Column preferences | Low |
| 30 | `POST /reminders/broadcast` (response shape) | Bulk broadcast | Medium |

**Total net-new endpoints to add:** **30**
**Net-new shapes to define on existing endpoints:** **8** (pipeline, aggregation, exceptions, head dashboard, accountants performance, dashboard enrich, daily reconciliation, sales-variance)

---

## Out of scope (not requested)

- Background ERP sync configuration (admin)
- Webhook management UI (currently no UI)
- API key management (currently no UI)
- Per-tenant white-label / theme assets (no UI)
- Email template editor (no UI)

When the backend implements the endpoints above, the frontend will swap fallbacks automatically — the wiring pattern already in place is:
```ts
const X_FALLBACK = [...];
const x = (((apiData as any)?.length>0) ? apiData : X_FALLBACK) as typeof X_FALLBACK;
```
Once the API returns the expected shape, `apiData` wins and the fallback becomes dead code (safe to remove later).
