# FE Wiring — T12 Branch Manager (مدير الفرع) — «مدير الفرع»

> Backend module status: ✅ ready for integration · Delivered 2026-07-12
> Base URL: `/api/v1` · Auth: `Authorization: Bearer <token>` (Sanctum, guard `asab`, role `branch`)
> Envelopes (`AsabResponse`): single = **bare object**; lists = `{ "data": [...], "meta"? }`; paginated = `{ "data": [...], "meta": { page, pageSize, total, totalPages } }`.
> Errors: `{ "error": { "code", "message", "messageAr", "details" }, "requestId" }` + HTTP status.
> Money: **integer halalas** everywhere (`salaryHalalas`, `salesHalalas`, `targetHalalas`, `priceHalalas`). ÷100, format `ar-SA`.

## Two surfaces

- **Company (canonical for mutations)** `/api/v1/company/me/branch/*` — carries `Idempotency-Key` + audit.
- **Platform (reads + legacy)** `/api/v1/branch/*` — same handlers, no idempotency/audit.

Branch resolution: platform honours `?branchId` **only if** it's in the caller's role assignment (else falls back to the assigned branch); company surface uses the first branch of the `branch` role assignment. Out-of-scope ids read as the assigned branch / 404 — never a leak.

## Screens covered

| Prototype screen | Endpoints |
|---|---|
| نظرة عامة (hero + KPIs + مهام اليوم + طاقم اليوم) | `GET /branch/overview` |
| رفع البيانات (نماذج + شاشة نجاح) | `POST /company/me/branch/upload` (canonical) · `POST /branch/upload/{reportType}` · `GET /branch/upload/status` |
| الموظفون (جدول + بحث + إضافة) | `GET /branch/employees` · `POST /company/me/branch/employees` |
| الأصناف + «تسجيل جرد» | `GET /branch/inventory-items` · `POST /company/me/branch/items/count` |
| الموردون + «طلب مورد جديد» | `GET /branch/suppliers` · `POST /company/me/branch/suppliers/request-new` |
| طلبات الشراء | `GET/POST /company/me/branch/purchase-requests` |
| شفتات الفرع | `GET .../shifts/active` · `POST .../shifts/open` · `POST .../shifts/{id}/close` |
| إعدادات الفرع | `GET /branch/settings` · `PUT /company/me/branch/settings` (PATCH alias) |

---

## 1. Overview — `GET /branch/overview` (and `/company/me/branch/overview`)

Bare object (BRM-1). Full landing screen in one call.

```json
{
  "branch": { "id": "019f…", "name": "فرع العليا" },
  "hero": { "targetHalalas": 1000000, "actualHalalas": 200000, "achievementPct": 20 },
  "kpis": {
    "todaySales": 200000, "todaySalesTrendPct": 12.5, "todayOrders": 3,
    "monthSales": 200000, "monthExpenses": 40000, "netProfit": 160000,
    "activeEmployees": 5, "requiredReportsCount": 6
  },
  "tasksOfDay": [
    { "id": "upload-morning-sales", "label": "رفع مبيعات اليوم", "state": "completed", "stateLabel": "مكتمل" },
    { "id": "upload-expenses", "label": "رفع المصروفات", "state": "pending", "stateLabel": "معلق" },
    { "id": "daily-inventory-count", "label": "جرد المخزون اليومي", "state": "pending", "stateLabel": "معلق" },
    { "id": "close-evening-shift", "label": "إغلاق الوردية المسائية", "state": "later", "stateLabel": "لاحقاً" }
  ],
  "crew": [
    { "id": "019f…", "name": "أحمد", "role": "Chef", "shift": "morning", "attendanceStatus": "present", "attendanceLabel": "حاضر" }
  ],
  "requiredReports": [ { "id": "sales", "name": "تقرير المبيعات", "required": true, "uploadedToday": true, "lastStatus": "success" } ]
}
```

- `hero.achievementPct` = monthSales ÷ `branches.asab_monthly_target` (0 when no target).
- `tasksOfDay[].state` ∈ `completed مكتمل | pending معلق | later لاحقاً`. `close-evening-shift`: `pending` while a shift is open, `completed` once a shift started today is no longer active, `later` when no shift yet.
- `crew` = active employees of the branch (attendance is a future data source — everyone active reads `present`); empty array when none.

## 2. Upload — daily reports (BRM-2)

**Canonical:** `POST /company/me/branch/upload` (flat body + multipart). Also accepts the legacy nested `{sales:{totalHalalas},expenses:{totalHalalas}}`.
**Platform:** `POST /branch/upload/{reportType}` — now validated + accepts attachments too.

Flat body: `{ reportType, date?, shift?, salesHalalas?, expensesHalalas?, expenseNote? }` + multipart `attachments[]` (≤10MB/file). `reportType` ∈ `sales|inventory|cash|waste|purchases|expenses`.

- Validation: unknown `reportType` → **400** `INVALID_INPUT`; bad `shift` → **422**; negative amount → 422. Expenses total is the **sum of its invoices**, never a picked number.
- `shift` ∈ `صباحي|مسائي|كامل اليوم` (also accepts `morning|evening|full_day`).
- Creates a **pending Operation** (approval pipeline: ApprovalStep + accountant notification + realtime). `origin` stays `mobile` (§5.2b business channel = branch submission); a new `channel: "dashboard"` records the physical surface.

Response `201` (platform):

```json
{ "id":"019f…", "publicId":"PUR-0042", "moduleKey":"sales", "status":"pending",
  "origin":"mobile", "channel":"dashboard",
  "attachments":[{ "id":"…","filename":"report.pdf","mimeType":"application/pdf","size":12034,"publicUrl":"…","uploadedAt":"…" }] }
```

Company response is a superset with `operations:[…]`, `uploadId`, `createdOperationId`, `uploadedAt`. `POST /company/me/branch/upload/sign-attachment` returns a **local** direct-upload URL (`/api/v1/uploads/direct`), not S3.

## 3. Employees (BRM-3.1)

- `GET /branch/employees?search=&status=&page=&pageSize=` → **paginated** (`{data, meta}`). Row: `{ id, empNumber, name, role, monthlySalary, shiftType, nationalId, hireDate, status }`. `search` matches name/empNumber/role.
- `POST /company/me/branch/employees` `{ name, role, salaryHalalas, shift?, nationalId?, hireDate?, email?, phone? }` — auto `EMP-####` (highest suffix + 1, unique per company, collision-safe), cashier role → mobile login provisioning. Response includes `empNumber` (+ `cashier` provisioning block when applicable).
- Platform `POST /branch/employees` takes an explicit `empNumber`.

## 4. Items + count (BRM-4)

- `GET /branch/inventory-items` → `{ items:[…], configuredBy }`. Row: `{ id, code, name, unit, cat, category, priceHalalas, minLevel, expectedQty, stockStatus, stockStatusLabel }`.
  - `stockStatus` ∈ `ok كافٍ | low منخفض | critical حرج`: `expectedQty ≤ minLevel` → critical, `≤ 1.5×minLevel` → low, else ok (defaults to `ok` when no threshold set).
  - Source: the branch's configured daily list, else the brand's sales catalog. Tenant-scoped.
- `POST /company/me/branch/items/count` `{ counts:[{ inventoryItemId, actualQty }] }` — creates a pending `inventory` op (`countType:daily`).
  - Ids **must** be on the branch's countable list → else **422** `INVALID_ITEM_IDS` (`details.unknown`).
  - Each line stores `expectedQty` (from the catalog) for the accountant's variance view.
  - One daily count per branch per day → a second → **409** `DAILY_COUNT_EXISTS`.

## 5. Suppliers (BRM-3.3)

- `GET /branch/suppliers` → `{ data:[…] }`. Active suppliers **plus** the branch's still-pending «طلب مورد جديد» rows. Row: `{ id, name, category, contactName, contactPhone, contactEmail, commercialReg, address, paymentTerms, rating, status, statusLabel, isActive, isRequest }`.
  - `isRequest:true` rows are pending requests — chip `statusLabel` = `قيد المراجعة`; approved suppliers show `معتمد`.
- `POST /company/me/branch/suppliers/request-new` `{ name, category?, contactPhone?, reason? }` — **persists** a request (was fire-and-forget) + notifies procurement. `201 { id, name, status:"pending_review", statusLabel:"قيد المراجعة" }`.
- Procurement side (role `procurement`): `GET /company/me/procurement/supplier-requests`, `POST /company/me/procurement/supplier-requests/{id}/approve` (promotes the request into a real supplier, flips the chip to `معتمد`).

## 6. Purchase requests (BRM-6)

- `GET /company/me/branch/purchase-requests` → `{ data:[…] }` (cap 100). Row: `{ id, item, qty, unit, status, urgency, date }`. (Now filtered by the branch-request marker, decoupled from `origin`.)
- `POST /company/me/branch/purchase-requests` `{ item|itemName, qty, unit, urgency|priority?(normal|urgent), notes? }` — pending op + procurement notification + realtime.

## 7. Shifts (BRM-5) — see also FE-T08

- `GET .../shifts/active` → active shift or `null`. `POST .../shifts/open` `{ cashierEmpNumber|cashierId?, openingCashHalalas|registerOpeningHalalas? }` — persists the cashier, derives shift no/type from brand config, defaults the float. Duplicate → **409** `SHIFT_ALREADY_OPEN`. `POST .../shifts/{id}/close` → see FE-T08.

## 8. Settings (BRM-7 — resolved)

- `GET /branch/settings` → prefs + admin-owned identity (`branchName`, `phone`, `address`) + `readOnlyFields:['branchName','phone','address']` + `shiftConfig.readOnly:true`.
- `PUT /company/me/branch/settings` (canonical; `PATCH` alias, platform is PATCH-only) — editable: `manager, taxNumber, bankAccount, cashLimitHalalas, wasteThreshold, autoReminders, requireImages`. **Identity + shift timings are read-only** and silently ignored (no 422). *(Client meeting overrides the SRS draft — name/phone are admin-owned.)*

## 9. Enums (key ↔ Arabic)

- Report names: `sales` تقرير المبيعات · `inventory` جرد المخزون اليومي · `cash` تقرير النقدية · `waste` تقرير الهدر · `purchases` المشتريات · `expenses` المصروفات.
- Task states: `completed` مكتمل · `pending` معلق · `later` لاحقاً.
- Urgency: `normal` عادي · `urgent` عاجل ⚡.
- Stock status: `ok` كافٍ · `low` منخفض · `critical` حرج.
- Supplier chips: `approved` معتمد · `pending_review` قيد المراجعة · `rejected` مرفوض.
- Shift values: `صباحي` · `مسائي` · `كامل اليوم`.

## 10. Quirks the FE must honour

- `origin` (`mobile|procurement|system`) is the business channel; `channel` (`mobile_app|dashboard`) is the physical surface — dashboard branch uploads are `origin:mobile` + `channel:dashboard`.
- Settings identity + shift timings are read-only — render disabled; sending them is a silent no-op (not 422).
- Employees list is now paginated (`{data, meta}`); items/suppliers are simple `{data}` lists; overview is a bare object.
- Request aliases already live: employees `salaryHalalas`→salary, `shift`→shiftType; purchase-requests `itemName`→item, `priority`→urgency; shifts/open `cashierId`→cashierEmpNumber, `registerOpeningHalalas`→openingCashHalalas. Send the canonical (first-listed) name.
- Everything is assigned-branch + tenant scoped: company B's data answers 404, never a leak.
