# FE Wiring — T15 Reports & Distribution (التقارير)

> Backend module status: 🟡 partial — RPT-3 owner dispatch + typed reports + builder + upload verification shipped 2026-07-13. **RPT-1 ③ ERP-Excel P&L parse (T15.2) + RPT-2 manager table (T15.4) are DEFERRED** (pending the external ERP Excel column format).
> Base URL: `/api/v1` · Auth: `Authorization: Bearer <token>` (Sanctum, guard `asab`)
> Envelopes (`AsabResponse`): single = **bare object**; lists = `{ "data": [...], "meta"? }`; paginated = `{ "data": [...], "meta": { page, pageSize, total, totalPages } }`.
> Errors: `{ "error": { "code", "message", "messageAr", "details" }, "requestId" }` + HTTP status — 401 unauth, 403 wrong role/tenant, 404 not found, 409 conflict, 422 validation.
> Money: **integer halalas** everywhere. ÷100, format `ar-SA`. Margin fields are pre-rounded floats.
> Binary downloads (PDF/xlsx/csv) stream raw — no JSON envelope.

## What shipped this pass

| Task | Endpoint(s) | Change |
|---|---|---|
| T15.7 | `POST /reports/*` (10 typed) | De-stubbed supplier-performance / menu-engineering / breakeven; payroll honors filters; **whole block now role-gated `accountant,head,company-admin`** |
| T15.6 | `POST /admin/reports/generate` | Honors `brandIds`/`restaurantIds` (resolved to branches); `format=pdf\|xlsx` **streams a binary** |
| T15.8 | `GET /reports/builder/saved`, `POST /reports/builder/{id}/run` | Saved definitions are now listable + replayable |
| T15.5 | `POST /company/me/reports/distributions/{id}/viewed` | Read-receipt hook → `viewed`/`viewed_at` |
| T15.1 | `POST /admin/reports/{key}/upload` | Extension guard (422) + parse dry-run → `status: verified\|failed` |
| T15.3 | `POST /admin/reports/{key}/send` | Owner email (queued, PDF/Excel attached) + in-app to the **brand owner**; `method`/`format`/`coverMessage` |
| T15.18 | `GET /head/reports/internal` | `meta.financialReports` HEAD-7 cards |

---

## 1. Typed reports — `POST /reports/{name}` · Roles: `accountant, head, company-admin`

`profit-loss`, `sales-summary`, `expense-summary`, `inventory-valuation`, `payroll`, `waste-analysis`, `supplier-performance`, `menu-engineering`, `breakeven`, `cash-flow`.

Body (all optional): `{ "from": "2026-07-01", "to": "2026-07-31", "branchIds": ["…"] }`. Response: `{ reportId, generatedAt, data: {…}, downloadUrl: null }`.

- **Role-gated (NFR-2):** branch / procurement / supplier tokens → **403**. Unauth → 401.
- `supplier-performance` → `data.suppliers[] = { supplierId, supplierName, orderCount, totalHalalas }` (ranked by spend).
- `menu-engineering` → `data.items[] = { name, qty, revenue }` (from sales `payload.items`).
- `breakeven` → `data = { revenue, fixedCosts, variableCosts, contributionMarginPct, breakevenRevenue, marginOfSafety }`.
- `payroll` → `data = { totalPayroll, headcount }`; honors `branchIds` and `to` (staff hired on/before period end).

## 2. Admin generate — `POST /admin/reports/generate` · Role: `admin`

Body: `{ reportKey, period:{from,to}?, brandIds?, restaurantIds?, branchIds?, format? }`. `reportKey ∈ pl|sales-channel|smart-compare|profit-cash|breakeven|op-profit|menu-eng`.

- `format` omitted/`json` → JSON `{ reportId, generatedAt, format:"json", downloadUrl:null, reportKey, period, data }`.
- `format=pdf|xlsx` → **streams the binary file directly** (Content-Disposition attachment) — FE fetches and saves the blob; there is no separate downloadUrl to poll.
- Scope: `brandIds`/`restaurantIds` are resolved down to branch ids server-side; a scope that matches no branch returns an empty report (not the whole tenant).

## 3. Report builder — `/reports/builder/*` · Roles: `accountant, head, company-admin`

- `GET fields` — buildable dimensions/metrics/filters (labels from API; never hardcode).
- `POST preview` — `{ dimensions?, metrics?, filters?, dateRange? }` → `{ rows, totals, rowCount, capped }`. Unknown dimension/metric → **422** `حقل غير معروف`.
- `POST save` — `{ name, descriptionAr?, definition }` → `201 { id, name, createdAt }`.
- `GET saved` — **new** — paginated tenant list `{ data:[{ id, name, descriptionAr, definition, createdAt }], meta }`.
- `POST {id}/run` — **new** — replays a saved definition through preview (same shape). Foreign-tenant id → **404**.

## 4. Distribution send — `POST /admin/reports/{reportKey}/send` · Role: `admin`

Body:

```json
{
  "period": { "from": "2026-07-01", "to": "2026-07-31" },
  "restaurantIds": ["…"],
  "method": "both",
  "format": "pdf",
  "coverMessage": "تفضلوا التقرير الشهري"
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `period.from` / `period.to` | date | yes | |
| `restaurantIds` | string[] | no | omit → **all active restaurants** in the tenant (bulk «إرسال الكل») |
| `method` | `email\|inApp\|both` | one of method/channels | preferred |
| `channels` | `["email","inApp"]` | — | legacy alias for `method` (back-compat) |
| `format` | `pdf\|excel\|both` | no | default `pdf`; attachment format(s) |
| `coverMessage` | string ≤2000 | no | prepended to the owner email + used as the in-app body |

Response: `{ reportKey, sentAt, sentCount, recipients:[{ restaurantId, owner, email, sent, sentDate }] }`.

- **BRO-1.2:** the in-app notification goes to the **brand owner** (`AsabBrand.owner_user_id`), not the sending admin. Email (queued, PDF/Excel attached) goes to `AsabBrand.owner_email`.
- **Idempotent** per `(reportKey, restaurant, period.from)` — re-sending the same period updates the row instead of duplicating.
- Email is **queued** (`SendOwnerReportJob`); the response returns immediately after recording the distributions.

## 5. Read receipt — `POST /company/me/reports/distributions/{id}/viewed` · any company role

No body. Sets `viewed=true`/`viewed_at`. Foreign-tenant id → **404**. Response `{ id, viewed:true, viewedAt }`. The admin `GET /admin/reports/{key}/status` then reports `viewed=true` for that restaurant.

## 6. Upload verification — `POST /admin/reports/{reportKey}/upload` · Role: `admin`

Multipart `file` (≤20MB, extension ∈ `.xlsx/.xls/.csv` → else **422**). A parse dry-run decides the wizard step ② state:

```json
{ "uploadId": "…", "reportKey": "pl", "status": "verified", "rowCount": 12, "errors": [] }
```

- `status: verified` («✓ تم التحقق») — readable sheet with a header + ≥1 data row.
- `status: failed` — corrupt/empty sheet; `errors[]` carries Arabic reasons. **FE must not advance to step ③ on `failed`.**
- ⚠️ **The structured P&L preview (revenue-by-channel incl. aggregators / expense lines / net / margin) is NOT built yet (T15.2 deferred).** `GET …/preview?uploadId=` still returns the generic flattened `{ rows:[{label,value,type,header}] }` cell dump. Do not build the P&L step ③ against a structured contract until T15.2 lands.

## 7. HEAD-7 cards — `GET /head/reports/internal` · Role: `head`

`data[]` = per-module cards (unchanged). **New:** `meta.financialReports[]`:

```json
[
  { "key": "pl-by-brand", "labelAr": "قائمة الدخل لكل علامة", "reportKey": "pl", "method": "POST", "endpoint": "/api/v1/reports/profit-loss", "formats": ["json","pdf","xlsx"], "note": "مرّر brandIds…" },
  { "key": "branch-compare", "labelAr": "مقارنة الفروع", "reportKey": "pl", "method": "POST", "endpoint": "/api/v1/reports/profit-loss", "formats": ["json"], "note": "…v1" }
]
```

## Enums (key ↔ Arabic)

- **Admin report catalog** (`GET /admin/reports/catalog`): `pl` الأرباح والخسائر · `sales-channel` المبيعات حسب القناة · `smart-compare` المقارنة الذكية · `profit-cash` الربح والنقدية · `breakeven` نقطة التعادل · `op-profit` ربح التشغيل · `menu-eng` هندسة القائمة. `category ∈ core|specialized`.
- **Sales channels** (`sales-channel.data.channels`): `cash` نقدي · `bank` شبكة/بنك · `delivery` تطبيقات التوصيل (the aggregator bucket) · `unclassified` غير مصنف.
- **P&L lines** (`pl.data.lines`): الإيرادات / تكلفة البضاعة / مصاريف تشغيلية / الهدر / صافي الربح.
- **Upload status:** `verified` تم التحقق · `failed` فشل التحقق.
- **Send method:** `email` · `inApp` · `both`. **format:** `pdf` · `excel` · `both`.

## Deferred (do not wire yet)

- **T15.2 RPT-1 ③** structured P&L parse from the uploaded ERP Excel + `asab_parsed_reports` persistence — blocked on the ERP export's column layout.
- **T15.4 RPT-2/RPT-4** manager table (revenue/net/margin per restaurant×month) + wizard KPIs — depend on T15.2's parsed data.
- Note: `GET /admin/reports/{key}/status` already tracks sent/viewed per restaurant and can back a basic distribution table today; the financial columns await T15.2.

## Test accounts

- `admin` (platform), `accountant` / `head` / `company-admin` (company). Brand owner delivery needs `AsabBrand.owner_user_id` + `owner_email` set.
