# FE Wiring — T03 Operations Pipeline (مسار العمليات)

> Backend module status: ✅ ready for integration · Delivered 2026-07-10 · Tests: 36 green
> Base URL: `/api/v1` · Auth: `Authorization: Bearer <token>` (Sanctum, guard `asab`)
> Response envelopes (`AsabResponse`): success = bare JSON object, or `{ "data": [...], "meta": {...} }`
> for lists. Errors: `{ "error": { "code", "message", "messageAr", "details" }, "requestId" }`.
> Money: all amounts are integer **halalas** — divide by 100 and format `ar-SA` client-side.

## Screens covered (prototype mapping)

| Prototype screen | Endpoints |
|---|---|
| Accountant inbox «قائمة العمليات المعلقة» | `GET /operations` (+ `meta.summary` → the 4 KPI counters) |
| Operation detail / lifecycle stepper «دورة حياة العملية» | `GET /operations/{id}`, `GET /operations/{id}/audit-trail` |
| Approve / Reject modal / «طلب توضيح» | `POST /operations/{id}/approve`, `/reject`, `/request-clarification` |
| Head final-approval «الاعتماد النهائي» | `POST /operations/{id}/final-approve` |
| Bulk «موافقة جماعية» | `POST /operations/bulk-approve` |
| Reject modal reason dropdown | `GET /lookups/rejection-reasons?moduleKey=` |
| Any status/origin/match/stage badge | labels ride on every operation payload; full maps at `GET /lookups/operation-enums` |
| Pipeline funnel widget «مسار العمليات — رؤية شاملة» | `GET /pipeline/overview` |
| Module aggregation grid | `GET /modules/aggregation` |
| **Main-dashboard branch/day state chips** («لا بيانات / غير مكتمل / جاهز للتجميع / مُجمَّع / جاهز لـ ERP / مُصدَّر») | `GET /pipeline/daily-rollup` |
| Correction («عملية تعديل») | `POST /operations/{id}/correction` |

## Conventions for this module

- Roles: reads = any tenant role; `approve` / `reject` / `request-clarification` / `correction` / `bulk-approve` = `accountant,head`; `final-approve` = `head` only.
- `{id}` accepts **either** the uuid **or** the `publicId` (`OPS-2401`) on show / approve / reject / final-approve / correction / audit-trail / request-clarification.
- Out-of-scope or other-tenant records answer **404** (never 403 — existence is not leaked).
- Pagination: `?page=1&pageSize=20` (max 100) → `meta: {page, pageSize, total, totalPages}`.
- Canonical paths are `/v1/operations/*` and `/v1/pipeline/*`. The `/v1/company/me/operations*` copies are accountant-surface aliases to the same handlers — prefer the canonical path.
- `GET /operations/export` is registered **before** `/operations/{id}` — never send `export` as an id.

---

## Endpoints

### 1. List operations (accountant inbox)

`GET /api/v1/operations` · Roles: any tenant role (rows are scoped to the caller's assigned branches)

| Param | Type | Notes |
|---|---|---|
| `moduleKey` | string / CSV | `sales,expenses,purchases,inventory,shifts,employees,cash,waste` |
| `status` | string / CSV | `pending,approved,rejected,final-approved` |
| `branchId` | string / CSV | |
| `match` | string / CSV | `exact,review,diff` |
| `origin` | string / CSV | **new** — `mobile,procurement,system` |
| `dateFrom` / `dateTo` | date (`YYYY-MM-DD`) | **new** — filters `operationDate` |
| `search` | string | matches `publicId` |
| `page` / `pageSize` | int | pageSize capped at 100 |

Response `200`:

```json
{
  "data": [
    {
      "id": "9c1f…", "publicId": "OPS-2401", "branchId": "b-1", "moduleKey": "sales",
      "sourceModule": null, "sourceId": null,
      "amount": 1834000,
      "match": "exact", "matchLabelAr": "متطابق",
      "diffNote": null,
      "origin": "mobile", "originLabelAr": "تطبيق الفرع", "originIcon": "📱",
      "attachmentCount": 3,
      "status": "pending", "statusLabelAr": "قيد المراجعة", "statusLabelShortAr": "معلق",
      "stage": { "key": "review", "step": 2, "icon": "🔍", "labelAr": "قيد المراجعة", "labelShortAr": "المراجعة" },
      "rejectReason": null, "rejectReasonKey": null,
      "isConditional": false, "isCorrection": false, "erpPosted": false,
      "operationDate": "2026-07-10T00:00:00+03:00",
      "submittedAt": "2026-07-10T09:15:00+03:00",
      "approvedAt": null, "finalApprovedAt": null, "createdAt": "2026-07-10T09:15:00+03:00"
    }
  ],
  "meta": {
    "page": 1, "pageSize": 20, "total": 13, "totalPages": 1,
    "summary": { "total": 45, "pending": 13, "approved": 32, "finalApproved": 20, "rejected": 3 }
  }
}
```

Notes for FE:
- `meta.summary` feeds the inbox KPI counters. It honours `moduleKey` but ignores the other filters (it is the module-wide picture, not the filtered page).
- Enum keys are unchanged; every enum now carries its Arabic label alongside (`statusLabelAr`, `originLabelAr`, `matchLabelAr`) plus a ready-made `stage` badge («م3 · الموافقة» = `stage.step` + `stage.labelShortAr`). **Do not hardcode label maps.**

### 2. Operation detail

`GET /api/v1/operations/{id}` · Roles: any tenant role

Returns the row above plus:

```json
{
  "payload": { "channels": [ … ] },
  "auditTrail": [
    { "stageId": "submit", "action": "أُنشئ السجل: OPS-2401", "by": "أحمد الشمري", "time": "2026-07-10T09:15:00+03:00", "note": null }
  ]
}
```

### 3. Audit trail (lifecycle drawer)

`GET /api/v1/operations/{id}/audit-trail`

```json
{ "data": [ { "icon": "🔍", "stageId": "review", "action": "طلب توضيح: أرفق تقرير POS", "by": "أحمد محمد", "time": "…", "note": "أرفق تقرير POS", "isTerminal": false } ] }
```

`isTerminal` is true only on the last step when it is `final` or `rejected`.

### 4. Approve (accountant, م2 → م3) ⚠️ idempotent

`POST /api/v1/operations/{id}/approve` · Roles: `accountant,head`

Body: `{ "note": "optional" }` → `200` with the updated operation.
Errors: `409 OP_NOT_PENDING` (`details: {currentStatus, requiredStatus}`).

Side effects: audit step «راجعه المحاسب ووافق عليه — أُرسل لرئيس الحسابات», head-role notification, realtime `operation.status_changed`.

### 5. Reject (returns to the branch manager) ⚠️ idempotent

`POST /api/v1/operations/{id}/reject` · Roles: `accountant,head`

```json
{ "reason": "missing_invoice", "details": "الفاتورة غير واضحة" }
```

| Field | Type | Required | Validation |
|---|---|---|---|
| `reason` | string | yes | a **key** from `GET /lookups/rejection-reasons?moduleKey=<op module>` |
| `details` (alias `notes`) | string | no | ≤1000, free text shown on the audit step |

- `200` → operation with `status: "rejected"`, `rejectReason` (canonical Arabic label), `rejectReasonKey`.
- `422 INVALID_REJECT_REASON` → `details.allowed` lists the valid keys for that module.
- `422 VALIDATION_ERROR` when `reason` is absent.
- `409 OP_ALREADY_FINAL` on a `final-approved` or already-`rejected` record.

> **Migration note:** the raw Arabic label (e.g. `"تناقض في المبالغ"`) is still accepted for one release and resolved back to its key — but send the key.

### 6. Request clarification «طلب توضيح» (non-terminal) — **new**

`POST /api/v1/operations/{id}/request-clarification` · Roles: `accountant,head`

```json
{ "message": "أرفق تقرير POS" }
```

- `200` → the operation, **status unchanged** (this is not a rejection).
- Writes a `review` audit step («طلب توضيح: …») and notifies the submitter (`operation.clarification_requested`).
- `409 OP_ALREADY_FINAL` on a locked or rejected record.

### 7. Final approve (head, م3 → م4) ⚠️ idempotent

`POST /api/v1/operations/{id}/final-approve` · Roles: `head`

```json
{ "isConditional": true, "conditionalNote": "يلزم إرفاق كشف البنك",
  "conditions": [ { "text": "إرفاق الكشف", "dueAt": "2026-07-11T00:00:00+03:00" } ] }
```

- All fields optional; `conditionalNote` is **required when** `isConditional` is true (422 otherwise).
- There is no `/conditional-approve` endpoint — conditional approval is this flag.
- `409 OP_NOT_APPROVED` when the record is not in `approved`.
- On success the record is **locked** («مُغلق»): further reject / edit / clarification attempts answer `409 OP_ALREADY_FINAL`.

### 8. Bulk approve «موافقة جماعية» ⚠️ idempotent

`POST /api/v1/operations/bulk-approve` · Roles: `accountant,head`

Body `{ "operationIds": ["OPS-2401", "9c1f…"] }` →

```json
{ "approved": ["OPS-2401"], "failed": [ { "id": "9c1f…", "code": "OP_NOT_PENDING" } ] }
```

Ids outside the caller's assigned branches are silently dropped before the service runs (zero-trust) — they appear in neither array.

> ⚠️ This endpoint performs `pending → approved` only. The head's «اعتماد الكل» on the final queue needs bulk **final**-approve, which is **T10.1** (not yet built).

### 9. Correction («عملية تعديل»)

`POST /api/v1/operations/{id}/correction` · Roles: `accountant,head`

Body `{ "reason": "خطأ في المبلغ", "notes": "…", "correctedFields": { "amount": 90000, "diffNote": "…" } }`
(legacy `{ correctionReason, amount, diffNote }` also accepted).

`201` returns a **linkage object**, not the operation:

```json
{ "originalOperationId": "…", "correctionOperationId": "…", "publicId": "OPS-0042", "status": "pending", "createdAt": "…" }
```

The new record is `pending`, `origin: "system"`, `match: "review"`, `isCorrection: true`, `correctiveRefId` = original. Corrections are legal on locked records — that is their purpose. Re-fetch the new id if you need its body.

### 10. Pipeline funnel

`GET /api/v1/pipeline/overview` · admin may add `?companyId=`

```json
{
  "stages": [
    { "key": "submitted", "labelAr": "تم الرفع", "count": 45 },
    { "key": "pending", "labelAr": "بانتظار المراجعة", "count": 13 },
    { "key": "approved", "labelAr": "معتمد محاسب", "count": 12 },
    { "key": "final-approved", "labelAr": "اعتماد نهائي", "count": 17 },
    { "key": "erp-posted", "labelAr": "مُرحَّل ERP", "count": 5 },
    { "key": "rejected", "labelAr": "مرفوض", "count": 3 }
  ],
  "throughputToday": { "submittedCount": 8, "completedCount": 5 },
  "avgCycleTimeHours": 4.2
}
```

Counts are now restricted to the caller's assigned branches (a brand-scoped accountant no longer sees company-wide numbers).

### 11. Module aggregation grid

`GET /api/v1/modules/aggregation?dateFrom=&dateTo=` → `{ "modules": [ { "moduleKey": "sales", "moduleLabelAr": "المبيعات", "totalCount": 42, "totalAmountHalalas": 50958000, "pendingCount": 14, "approvedCount": 20, "rejectedCount": 1, "finalApprovedCount": 7 } ] }`

### 12. Daily rollup — branch/day state chips (§5.2c) — **new**

`GET /api/v1/pipeline/daily-rollup` · Roles: any tenant role · admin may add `?companyId=`

| Param | Type | Notes |
|---|---|---|
| `date` | date | default **today** (Asia/Riyadh). Returns **every in-scope branch**, including ones with no uploads (`empty`). |
| `dateFrom` / `dateTo` | date | range mode: returns **only** branch-days that carry operations. `dateTo >= dateFrom`. |
| `branchId` | string | |
| `brandId` | string | |

```json
{
  "data": [
    {
      "branchId": "b-1", "branchName": "فرع الرياض - العليا", "date": "2026-07-10",
      "state": { "key": "ready_erp", "labelAr": "جاهز لـ ERP", "subLabelAr": "دفعة جاهزة للإرسال", "step": 4 },
      "counts": { "total": 5, "pending": 0, "approved": 0, "finalApproved": 5, "rejected": 0, "erpPosted": 0 },
      "totalAmountHalalas": 4523000
    }
  ],
  "meta": {
    "dateFrom": "2026-07-10", "dateTo": "2026-07-10", "branchDays": 12,
    "byState": { "empty": 3, "incomplete": 4, "ready_consolidation": 2, "consolidated": 1, "ready_erp": 1, "exported": 1, "erp_imported": 0 }
  }
}
```

**Derivation** (server-side, never stored):

| Condition | State |
|---|---|
| no operations that day | `empty` لا بيانات |
| any `pending` | `incomplete` غير مكتمل |
| only `rejected` rows survive | `incomplete` (the branch still owes data) |
| `approved` only | `ready_consolidation` جاهز للتجميع |
| `approved` + `final-approved` mix | `consolidated` مُجمَّع |
| all `final-approved`, not all posted | `ready_erp` جاهز لـ ERP |
| all `final-approved` **and** all `erpPosted` | `exported` مُصدَّر |

`erp_imported` («مُستورَد في ERP ★») is a **reserved future stage** — the API never returns it; render it as the greyed-out 7th chip.

Errors: `422 COMPANY_REQUIRED` when a platform admin calls it without `?companyId` and has no own company.

### 13. Rejection-reason lookup — **new**

`GET /api/v1/lookups/rejection-reasons?moduleKey=sales`

```json
{ "data": [ { "key": "incomplete_data", "value": "incomplete_data", "labelAr": "بيانات غير مكتملة" } ] }
```

7 generic reasons; `moduleKey=sales` appends 2 more (see enum table below).

### 14. Enum catalogue — **new**

`GET /api/v1/lookups/operation-enums` → every map in one call:
`{ status[], stages[], origin[], match[], rollup[], rejectionReasons[], rejectionReasonsByModule{} }`, each entry `{key, labelAr, …}`. Fetch once at boot and bind filters/badges from it.

### 15. Operations export

`GET /api/v1/operations/export?format=xlsx|csv&moduleKey=&status=&branchId=&brandId=&dateFrom=&dateTo=`
Roles: `accountant,head`. Streams a binary (no JSON envelope). Now restricted to the caller's assigned branches.

---

## Enums (key ↔ Arabic label)

**Status** (`status`, `statusLabelAr`)

| Key | labelAr | labelShortAr |
|---|---|---|
| `pending` | قيد المراجعة | معلق |
| `approved` | تمت الموافقة | مقبول (sales screens: «معتمد - مرحلة 1») |
| `rejected` | مرفوض | مرفوض |
| `final-approved` | معتمد نهائياً | نهائي (locked badge: «مُغلق») |

**Stage** (`stage`, and `stageId` in the audit trail)

| Key | step | icon | labelAr | labelShortAr |
|---|---|---|---|---|
| `submit` | 1 | 📱 | رُفع من الفرع | الرفع |
| `review` | 2 | 🔍 | قيد المراجعة | المراجعة |
| `approved` | 3 | ✓ | موافق عليه | الموافقة |
| `final` | 4 | 🔒 | معتمد نهائياً | الاعتماد |
| `erp` | 5 | 🔗 | مُرحَّل لـ ERP | ERP |
| `reports` | 6 | 📊 | تقارير ERP (قراءة) | التقارير — future stage |
| `rejected` | -1 | ✗ | مرفوض | مرفوض — off-pipeline |

**Origin** (`origin`, `originLabelAr`, `originIcon`): `mobile` 📱 تطبيق الفرع · `procurement` 🛒 سير المشتريات · `system` ⚙ استيراد النظام

**Match** (`match`, `matchLabelAr`): `exact` متطابق · `review` يحتاج مراجعة · `diff` فرق في الكمية
(the code emits exactly `exact|review|diff`; the SRS aliases `needs-review`/`qty-diff` are never returned)

**Rollup** (`state.key`): see §12 table.

**Rejection reasons** — generic: `incomplete_data` بيانات غير مكتملة · `missing_invoice` فاتورة مفقودة أو غير واضحة · `amount_mismatch` تناقض في المبالغ · `quantity_diff` فرق في الكميات · `invalid_date` تاريخ غير صحيح · `unapproved_supplier` مورد غير معتمد · `other` أخرى.
**sales** adds: `missing_pos_report` تقرير POS مفقود · `missing_bank_statement` كشف البنك غير مرفق.

## Error codes

| HTTP | code | When |
|---|---|---|
| 409 | `OP_NOT_PENDING` | approve on a non-pending record |
| 409 | `OP_NOT_APPROVED` | final-approve on a non-approved record |
| 409 | `OP_ALREADY_FINAL` | reject / clarify / edit / delete a locked (`final-approved`) or `rejected` record |
| 422 | `INVALID_REJECT_REASON` | reason key not in the module's list (`details.allowed`) |
| 422 | `OP_STATUS_TRANSITION_FORBIDDEN` | procurement edit tried to set `final-approved` (use `/final-approve`) |
| 422 | `REJECT_REASON_REQUIRED` | procurement edit set `status: rejected` with no `reason` |
| 422 | `COMPANY_REQUIRED` | admin called daily-rollup with no company |
| 422 | `VALIDATION_ERROR` | field validation (`error.details` = Laravel errors bag) |
| 404 | `NOT_FOUND` | unknown id **or** outside the caller's tenant/branch scope |

## Behaviour changes shipped in T03 (read before wiring)

1. **Additive labels.** Every enum field keeps its bare key; the Arabic label arrives beside it (`statusLabelAr`, `originLabelAr`, `matchLabelAr`) plus a `stage` object. Nothing was renamed. *(This deviates from the T03.1 task text, which proposed replacing `status` with a `{key,labelAr}` object — rejected as needlessly breaking.)*
2. **`reject` now takes a reason key**, not free text. Arabic labels still work for one release.
3. **`origin` is trustworthy.** Dashboard-created purchase orders are `procurement` (previously mislabelled `mobile`); a backfill migration fixes historical rows.
4. **Locked records are truly locked.** `PATCH /company/me/procurement/orders/{id}` and its DELETE now answer `409` on `final-approved`/`rejected`; status changes there run through the pipeline (`approved` and `rejected` only).
5. **Assigned-branch scoping** now applies to `pipeline/overview`, `modules/aggregation`, `daily-rollup` and `operations/export` — a brand-scoped accountant's numbers shrink accordingly.

## Still missing (owned by other tasks — do not wait on T03)

- Bulk **final**-approve + return-for-review + grouped head queue → **T10**.
- Sales channel reconciliation payload, day-completeness banner → **T04**.
- Operation attachments listing endpoint → **T04.25**.

## Test accounts / seed data

Tests build their own fixtures (`tests/Feature/OperationsPipelineTest.php`, `tests/Feature/DailyRollupTest.php`).
A shared demo tenant seeder («مجموعة التاج») is Epic-8 work; until then use `AsabOperationSeeder`.
