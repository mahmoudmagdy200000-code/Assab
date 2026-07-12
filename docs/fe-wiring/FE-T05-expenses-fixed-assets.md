# FE Wiring — T05 Expenses & Fixed Assets (المصروفات والأصول الثابتة)

> Backend module status: ✅ ready for integration
> Base URL: `/api/v1` · Auth: `Authorization: Bearer <token>` (Sanctum, guard `asab`)
> Response envelopes (`AsabResponse`): success = bare JSON object, or `{ "data": [...], "meta": {...} }`
> for paginated lists. Errors: `{ "error": { "code", "message", "messageAr", "details" }, "requestId" }`
> with the HTTP status — 401 unauthenticated, 403 role/tenant denied, 404 not found (incl. cross-tenant),
> 409 conflict/locked, 422 validation.
> Binary downloads (Excel/CSV) stream raw with no JSON envelope.
> Money: all amounts are integer **halalas** — divide by 100, format `ar-SA` client-side.

Two API surfaces, same handlers:
- **Company SPA** → `/api/v1/company/me/*` (middleware `asab.tenant` + `asab.idempotency` + `asab.audit`, role `accountant`). **This is the canonical surface for the company dashboard.**
- **Platform** → `/api/v1/accountant/*` (role `accountant,head`). Same controllers, used by the internal ASAB staff console.

Where an endpoint exists on both, the company path is documented as canonical and the platform twin noted.

## Screens covered (prototype mapping)

| Prototype screen | Endpoints |
|---|---|
| المصروفات — statement table with expandable invoice rows, VAT columns, توثيق toggle, «أصل ثابت» button | §1 operation detail, §2 KPIs, §3 verify, §4 review modal, §5 attachments |
| تحويل لأصل ثابت — 2-step wizard (name/category/useful-life → depreciation summary) | §6 convert-to-asset-draft |
| مسودات الأصول — pending drafts panel under the expenses table | §7 drafts list, §8 confirm/discard |
| سجل الأصول الثابتة — register: KPI tiles, category pills, search, edit drawer | §9 list, §10 create, §11 edit, §12 export, §13 import, §14 categories/enums |

## Conventions for this module

- Idempotency: send `Idempotency-Key: <uuid>` on every `company/me` POST/PATCH/DELETE marked ⚠️.
- Money is integer halalas everywhere. VAT is **15%**, computed server-side; never recompute on the client.
- `{invoiceId}` in every `expense-invoices/*` path is the expenses **operation** id or public id (`EXP-0007`) — not a standalone invoice entity. The invoice inside the statement is chosen by `invoiceIndex` (0-based).

---

## Expenses

### 1. Expenses statement detail

`GET /api/v1/company/me/operations/{id}` · Roles: `accountant`
Platform twin: `GET /api/v1/operations/{id}` (`accountant,head`).

For an expenses operation the response adds an `expenses` block on top of the shared operation fields. VAT split, match badge and totals are **computed on read** — they are never stored, so they can never drift from the amounts.

Response `200` (expenses fields only):

```json
{
  "id": "9d1f…", "publicId": "EXP-0007", "moduleKey": "expenses",
  "status": "pending", "amount": 34500,
  "payload": { "invoices": [ … ] },
  "expenses": {
    "invoices": [
      {
        "index": 0,
        "invNum": "INV-1", "vendor": "مؤسسة الغذاء", "desc": "خضروات", "date": "2026-07-01",
        "amountHalalas": 11500, "preTaxHalalas": 10000, "vat15Halalas": 1500, "inclTaxHalalas": 11500,
        "verified": false, "verifiedAt": null, "verifiedBy": null,
        "convertedToAsset": false, "convertedLabelAr": null, "assetDraftId": null,
        "documentAmountHalalas": null, "documentVendor": null, "documentInvNum": null, "documentDate": null,
        "matchStatus": "missing", "matchLabelAr": "مفقودة", "matchIcon": "❌",
        "deltaHalalas": null, "deltaNoteAr": null,
        "attachments": [], "attachmentCount": 0
      }
    ],
    "totals": { "invoiceCount": 2, "preTaxHalalas": 30000, "vat15Halalas": 4500, "inclTaxHalalas": 34500 },
    "verifiedCount": 0, "allVerified": false, "allVerifiedBadgeAr": null,
    "matchSummary": { "matched": 0, "mismatch": 0, "missing": 2 },
    "isLocked": false
  }
}
```

FE notes:
- Render one expandable row per `expenses.invoices[]`. The three money columns are `preTaxHalalas` (الصافي) / `vat15Halalas` (الضريبة ١٥٪) / `inclTaxHalalas` (الإجمالي).
- `matchStatus` drives the row badge: `matched` ✅ مطابقة · `mismatch` ⚠️ غير مطابقة · `missing` ❌ مفقودة. On `mismatch`, show `deltaNoteAr` verbatim («⚠ فرق: 200.00 ر.س عن الفاتورة الأصلية»).
- `allVerified=true` → show the header badge `allVerifiedBadgeAr` («✅ كل الفواتير موثّقة»).
- `convertedToAsset=true` → stamp the row «محوّل» (`convertedLabelAr`) and disable its «أصل ثابت» button.
- `isLocked=true` (operation `final-approved`) → all توثيق/convert/review actions return 409; disable them.
- Legacy single-invoice statements (pre-T05) auto-fold to a one-row `invoices[]`, so the shape is uniform.

### 2. Expenses KPI strip

`GET /api/v1/company/me/expenses/kpis` · Roles: `accountant`
Platform twin: `GET /api/v1/accountant/expenses/kpis` (`accountant,head`).

Query: `dateFrom` (date, default today), `dateTo` (date, default = dateFrom, must be ≥ dateFrom).

Response `200`:

```json
{
  "dateFrom": "2026-07-10", "dateTo": "2026-07-10",
  "statementCount": 2, "invoiceCount": 4,
  "totalHalalas": 46000, "preTaxHalalas": 40000, "vat15Halalas": 6000,
  "pendingStatementCount": 1,
  "pendingInvoices": { "total": 3, "matched": 1, "mismatch": 1, "missing": 1 },
  "verifiedInvoiceCount": 0, "unverifiedInvoiceCount": 4, "convertedInvoiceCount": 0
}
```

FE notes: `pendingInvoices` is the headline split the ACC-2.1 tiles render (قيد المراجعة → مطابقة / غير مطابقة / مفقودة). Scoped accountants get only their assigned branches.

### 3. توثيق — verify / unverify one invoice ⚠️ idempotent

`POST /api/v1/company/me/expense-invoices/{invoiceId}/verify` · Roles: `accountant`
`DELETE /api/v1/company/me/expense-invoices/{invoiceId}/verify` (unverify)

Body: `{ "invoiceIndex": 0 }` — integer ≥ 0, default 0. Required in practice whenever the statement has more than one invoice.

Response `200`:

```json
{ "operationId": "9d1f…", "invoiceIndex": 0, "verified": true, "verifiedAt": "2026-07-10T09:12:00+03:00",
  "allVerified": false, "allVerifiedBadgeAr": null, "verifiedCount": 1, "invoiceCount": 2 }
```

Errors: `422 INVOICE_INDEX_OUT_OF_RANGE` (bad index) · `409 OP_ALREADY_FINAL` (locked) · `404` (cross-tenant / outside assigned branches).

### 4. Review modal — record the attached document ⚠️ idempotent

`PATCH /api/v1/company/me/expense-invoices/{invoiceId}/invoices/{invoiceIndex}` · Roles: `accountant`

Body (all optional; send what the accountant typed off the paper invoice):

| Field | Type | Notes |
|---|---|---|
| `documentAmountHalalas` | int\|null | drives `matched`/`mismatch` + the delta |
| `documentVendor` | string\|null | |
| `documentInvNum` | string\|null | |
| `documentDate` | date\|null | |

Response `200` = the ACC-2.3 side-by-side model:

```json
{
  "invoice": { "index": 0, "matchStatus": "mismatch", "deltaHalalas": 500, "deltaNoteAr": "⚠ فرق: 5.00 ر.س عن الفاتورة الأصلية", … },
  "rows": [
    { "field": "invNum", "labelAr": "رقم الفاتورة", "entered": "INV-1", "document": "INV-1", "matches": true },
    { "field": "amountHalalas", "labelAr": "المبلغ شامل الضريبة", "entered": 11500, "document": 12000, "matches": false }
  ],
  "deltaHalalas": 500, "deltaNoteAr": "⚠ فرق: 5.00 ر.س عن الفاتورة الأصلية"
}
```

FE notes: render `rows` as the two-column «المُدخل / الفاتورة الأصلية» table; highlight any row with `matches:false`.

### 5. Invoice attachments (the 3 ACC-2 photos)

`GET /api/v1/company/me/expense-invoices/{invoiceId}/attachments` · Roles: `accountant`

Query: optional `invoiceIndex` — narrows to one invoice.

Without `invoiceIndex`, grouped per invoice:

```json
{ "data": [
    { "invoiceIndex": 0, "attachments": [ { "id": "…", "filename": "inv.jpg", "publicUrl": "https://…", "label": "invoice:0:receipt", "invoiceIndex": 0 } ] },
    { "invoiceIndex": 1, "attachments": [] },
    { "invoiceIndex": null, "attachments": [ … statement-level docs … ] }
  ],
  "meta": { "total": 3 } }
```

With `?invoiceIndex=1` → flat `{ "data": [ … invoice 1's rows … ], "meta": { "invoiceIndex": 1 } }`.

FE notes: an attachment is bound to an invoice when its `label` is prefixed `invoice:{index}:…` (e.g. `invoice:0:receipt`, `invoice:0:stamp`, `invoice:0:totals`). Anything else is a statement-level document under `invoiceIndex:null`. For a single-invoice statement, unlabelled documents are attributed to invoice 0 automatically.

### Expenses upload contract (branch side, for reference)

`POST /api/v1/admin/branches/{branchId}/upload/expenses` now **requires** `invoices[]`; the statement total is the sum of the rows, never a client number.

| Field | Type | Required |
|---|---|---|
| `invoices` | array (min 1) | yes |
| `invoices[].invNum` | string ≤64 | yes |
| `invoices[].vendor` | string ≤200 | yes |
| `invoices[].desc` | string ≤500 | yes |
| `invoices[].date` | date | yes |
| `invoices[].amountHalalas` | int ≥0 (tax-inclusive) | yes |
| `invoices[].vatHalalas` | int ≥0 | no — omit for the 15% split; send for zero-rated/legacy |

Uploading expenses without a valid `invoices[]` → `422`.

---

## Fixed-asset conversion

### 6. Convert an invoice to an asset draft (wizard) ⚠️ idempotent

`POST /api/v1/company/me/expense-invoices/{invoiceId}/convert-to-asset-draft` · Roles: `accountant`
Platform twin: `POST /api/v1/accountant/expense-invoices/{invoiceId}/convert-to-asset` (same handler).

Body:

| Field | Type | Required | Validation |
|---|---|---|---|
| `invoiceIndex` | int | no | ≥0, default 0 |
| `assetName` | string | yes | ≤200 |
| `category` | string | yes | ≤32 (an `asset-categories` key) |
| `usefulLifeMonths` | int | yes | **one of 24, 36, 48, 60, 72, 84** |
| `targetBranches` | string[] | yes | ≥1, each must be in the caller's assigned scope |
| `custodian` | string | yes | ≤200 |
| `qty` | int | yes | ≥1 |
| `notes` | string | no | |
| `amount` | int | no | fallback only; the draft value is the invoice's pre-tax amount |

Response `201` = the draft (see §7 shape). Key fields: `amountHalalas` = the invoice **pre-tax** amount (VAT is not capitalised), `annualDepreciationHalalas`, `monthlyDepreciationHalalas` for the wizard step-2 summary.

Errors:
- `409 INVOICE_ALREADY_CONVERTED` («الفاتورة محوّلة مسبقاً») — an invoice converts **once**.
- `422 INVOICE_INDEX_OUT_OF_RANGE` · `422` (bad `usefulLifeMonths`) · `404` (foreign statement, non-expenses op, out-of-scope target branch).

On success the source invoice flips to `convertedToAsset:true` with `assetDraftId` set — re-fetch §1 to grey out the row.

### 7. Drafts panel

`GET /api/v1/accountant/asset-drafts` · Roles: `accountant,head`
(also embedded in the register response §9 under `meta.drafts`.)

```json
{ "data": [
  {
    "id": "…", "draftId": "DRAFT-AB12CD34", "expenseOpId": "9d1f…",
    "invNum": "INV-1", "vendor": "مؤسسة الغذاء", "desc": "ثلاجة",
    "expenseBranch": "فرع العليا", "expenseDate": "2026-07-01T00:00:00+03:00",
    "assetName": "ثلاجة عرض", "category": "kitchen", "categoryLabelAr": "معدات مطبخ",
    "amount": 10000, "amountHalalas": 10000,
    "usefulLifeMonths": 48, "annualDepreciationHalalas": 2500, "monthlyDepreciationHalalas": 208,
    "qty": 1, "targetBranches": ["…"], "custodian": "أحمد",
    "status": "draft", "statusLabelAr": "في انتظار التأكيد", "createdAt": "…"
  }
] }
```

Only `status='draft'` rows appear; scoped accountants see only drafts targeting an assigned branch.

### 8. Confirm / discard a draft ⚠️ idempotent

`POST /api/v1/company/me/asset-drafts/{draftId}/confirm` → `201 { "createdAssets": [ …asset objects (§9)… ] }`
`POST /api/v1/company/me/asset-drafts/{draftId}/discard` → `204`
(Platform: confirm same path; discard is `DELETE /api/v1/accountant/asset-drafts/{draftId}`.)

Confirm mints **`targetBranches.length × qty`** assets (status `pending_branch`) in one transaction, each carrying the invoice snapshot (`invNum`, pre-tax cost, useful life, custodian).

Errors — a draft leaves `draft` exactly once:
- `409 DRAFT_ALREADY_CONFIRMED` («تم تأكيد المسودة مسبقاً») — second confirm mints **nothing**.
- `409 DRAFT_ALREADY_DISCARDED` («تم تجاهل المسودة مسبقاً»).
- `404` — cross-tenant / out-of-scope draft.

---

## Fixed-assets register

### 9. Register list

`GET /api/v1/company/me/assets` · Roles: `accountant`
Platform twin: `GET /api/v1/accountant/assets`.

Query: `search` (name / public_id / custodian LIKE), `category`, `status`, `branchId`, `page`, `pageSize` (≤100).

Paginated envelope — KPI tiles ride in `meta.summary`, the drafts panel in `meta.drafts`:

```json
{
  "data": [
    { "id": "…", "publicId": "FA-0001", "name": "ثلاجة عرض",
      "category": "kitchen", "categoryLabelAr": "معدات مطبخ", "branchId": "…",
      "cost": 100000, "priceHalalas": 100000, "bookValue": 100000, "bookValueHalalas": 100000,
      "usefulLifeMonths": 60, "monthlyDepreciationHalalas": 1667, "annualDepreciationHalalas": 20000,
      "status": "pending_branch", "statusLabelAr": "بانتظار تأكيد الفرع",
      "invNum": "INV-1", "serial": "SN-9", "purchaseDate": "2026-01-05T00:00:00+03:00",
      "custodian": "أحمد", "notes": null }
  ],
  "meta": {
    "page": 1, "pageSize": 20, "total": 42, "totalPages": 3,
    "summary": { "pendingAccountant": 3, "pendingBranch": 12, "confirmed": 20, "active": 5,
                 "maintenance": 1, "retired": 1, "total": 42, "bookValueTotal": 8400000 },
    "drafts": [ … §7 rows … ]
  }
}
```

> ⚠️ Contract change vs pre-T05: this endpoint used to return `{data, summary, drafts}` **unpaginated**. It is now a paginated envelope with `summary`/`drafts` in `meta`. Code against the paginated shape.

### 10. Register create ⚠️ idempotent

`POST /api/v1/company/me/assets` · Roles: `accountant`

| Field | Type | Required | Validation |
|---|---|---|---|
| `name` | string | yes | ≤200 |
| `category` | string | yes | ≤32 |
| `branchId` | string | yes | must be in assigned scope |
| `cost` (or `priceHalalas`) | int | yes | ≥0 (halalas) |
| `usefulLifeMonths` | int | yes | **one of 24/36/48/60/72/84** |
| `invNum` | string | no | ≤64 |
| `serial` | string | no | ≤64 |
| `purchaseDate` | date | no | defaults to now |
| `custodian` | string | no | ≤200 |
| `notes` | string | no | |

Response `201` = the asset (§9 shape). `publicId` is `FA-000N` **per company** (two companies both start at `FA-0001`). Status starts `pending_branch`; a `assetConfirmationNeeded` broadcast fires. `422` on a bad `usefulLifeMonths`; `404` on an out-of-scope `branchId`.

### 11. Register edit ⚠️ idempotent

`PATCH /api/v1/company/me/assets/{id}` · Roles: `accountant`

Only keys you send are written — `custodian:null` / `branchId:null` **clear** the field (fixes the pre-T05 bug where nulls were dropped).

| Field | Type | Validation |
|---|---|---|
| `name` | string | ≤200 |
| `category` | string | ≤32 |
| `status` | string | **one of** `active,maintenance,retired,pending_branch,pending_accountant,confirmed` |
| `bookValue` (or `bookValueHalalas`) | int | ≥0 |
| `custodian` | string\|null | ≤200 |
| `serial` | string\|null | ≤64 |
| `purchaseDate` | date\|null | |
| `branchId` | string\|null | in assigned scope |
| `note` | string\|null | |

Response `200` echoes `status`+`statusLabelAr`, `category`+`categoryLabelAr`, `serial`, `purchaseDate`, `bookValue`. `422` on an invalid `status`; `404` cross-tenant.

### 12. Register export

`GET /api/v1/company/me/assets/export?format=xlsx|csv&category=&branchId=` · Roles: `accountant`

Streams a binary file (no JSON). Columns: رمز الأصل · الاسم · التصنيف · الرقم التسلسلي · الفرع · تاريخ الشراء · سعر الشراء · العمر الإنتاجي · الإهلاك الشهري · الإهلاك السنوي · القيمة الدفترية · العهدة · الحالة. **Now scoped to the caller's assigned branches** (pre-T05 it exported the whole company).

### 13. Register import (Excel/CSV)

`POST /api/v1/company/me/assets/import` (multipart `file`) · Roles: `accountant`

Response `202`:

```json
{ "jobId": "job_AB12…", "parsedRows": 12, "createdRows": 11, "count": 11,
  "imported": [ { "publicId": "FA-0007", "name": "…", … } ],
  "errors": [ { "row": 4, "message": "branch outside assigned scope" },
              { "row": 7, "message": "useful life must be one of 24/36/48/60/72/84" } ] }
```

Header mapping is Arabic/English tolerant (اسم/الأصل, الفئة, القيمة/التكلفة, الفرع, العمر الإنتاجي, الرقم التسلسلي). Money cells are SAR → halalas. Out-of-scope branch rows and bad useful-life rows are rejected into `errors[]`, not created. `public_id` uses the same per-company `FA-000N` allocator as §10.

### 14. Lookups

`GET /api/v1/company/me/lookups/asset-categories` · Roles: `accountant`

```json
{ "data": [
  { "key": "kitchen", "id": "kitchen", "name": "معدات مطبخ", "labelAr": "معدات مطبخ", "depreciationRate": 20 },
  { "key": "tech", "id": "tech", "name": "تقنية وأجهزة", "depreciationRate": 25 },
  { "key": "furniture", "id": "furniture", "name": "أثاث ومفروشات", "depreciationRate": 10 },
  { "key": "vehicles", "id": "vehicles", "name": "مركبات", "depreciationRate": 20 },
  { "key": "construction", "id": "construction", "name": "صيانة وإنشاءات", "depreciationRate": 15 },
  { "key": "other", "id": "other", "name": "أخرى", "depreciationRate": 15 }
] }
```

`GET /api/v1/lookups/asset-enums` (auth only, no role gate) — the full vocabulary in one call: `categories`, `usefulLifeMonths` (with Arabic labels), `lifecycleStatus`, `workflowStatus`, `draftStatus`, and `expenses` (`vatPercent`, `invoiceMatch`, `allVerifiedBadgeAr`, `convertedLabelAr`).

---

## Enums (key ↔ Arabic label)

| Enum | Key | Arabic |
|---|---|---|
| Invoice match | `matched` / `mismatch` / `missing` | مطابقة / غير مطابقة / مفقودة |
| Asset lifecycle | `active` / `maintenance` / `retired` | نشط / صيانة / مُهلك |
| Asset workflow | `pending_branch` / `pending_accountant` / `confirmed` | بانتظار تأكيد الفرع / بانتظار تأكيد المحاسب / مؤكد |
| Draft status | `draft` / `confirmed` / `discarded` | في انتظار التأكيد / مؤكد / مُتجاهلة |
| Asset category | `kitchen` / `tech` / `furniture` / `vehicles` / `construction` / `other` | معدات مطبخ / تقنية وأجهزة / أثاث ومفروشات / مركبات / صيانة وإنشاءات / أخرى |
| Useful life (months) | `24` / `36` / `48` / `60` / `72` / `84` | سنتان / 3 سنوات / 4 سنوات / 5 سنوات / 6 سنوات / 7 سنوات |
| VAT | `15` | نسبة الضريبة ١٥٪ |
| Badges | `allVerifiedBadgeAr` / `convertedLabelAr` | ✅ كل الفواتير موثّقة / محوّل |

## Realtime / polling

- Asset broadcasts: `assetConfirmationNeeded` (new/confirmed-draft asset), `assetDraftCreated`, `assetDraftConfirmed` — refresh the register/drafts panel on receipt, or poll §9 every ~30s.
- Legacy mobile expenses appear in `GET /accountant/operations?moduleKey=expenses` automatically (two-worlds bridge) — no FE action, just list-refresh.

## Two-worlds note (bridge)

Mobile-app expenses (`Modules\Expense`) are mirrored into `asab_operations` on submit/approve, keyed on `sourceModule='expense'` + `sourceId`. They carry their invoices' own net/VAT lines (zero-rated invoices keep 0 VAT). Once the accountant acts on the dashboard record, the mobile side no longer overwrites it. Branches outside any ASAB company produce no operation.
