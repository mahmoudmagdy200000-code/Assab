# FE Wiring — T10 Head Accountant & ERP Export — «رئيس الحسابات والتصدير لـ ERP»

> Backend module status: ✅ ready for integration · Delivered 2026-07-12 · Tests: 16 green (queue + ERP lifecycle) + existing head suites
> Base URL: `/api/v1` · Auth: `Authorization: Bearer <token>` (Sanctum, guard `asab`)
> Envelopes (`AsabResponse`): success = bare object, or `{ "data": [...], "meta": {...} }` for lists.
> Errors: `{ "error": { "code", "message", "messageAr", "details" }, "requestId" }` + HTTP status.
> Money: integer **halalas** (`amount` == `amountHalalas`). Downloads stream binary (no envelope).
> **Read [FE-T03](FE-T03-operations-pipeline.md) first** — every list row is a pipeline operation; the lifecycle stepper/audit-trail come from there.

## Surfaces + roles

| Path prefix | Role | Notes |
|---|---|---|
| `/head/*`, `/operations/*`, `/erp/batches*` | `head` (some `accountant,head`) | platform head SPA |
| `/company/me/head/*`, `/company/me/operations/*`, `/company/me/erp/preview` | `head` | company portal head |
| `/admin/erp/*` | `admin` only | platform admin ERP screen (ERP-2, cross-company) |
| `/operations/{id}/final-approve`, `bulk-final-approve`, `return-for-review`, `bulk-return` | `head` | on BOTH surfaces; company head can now final-approve too |

All `/company/me/*` and the head mutations carry `asab.idempotency` (send an `Idempotency-Key` on retries) + audit.

---

# A. Head dashboard & queues (HEAD-1 / HEAD-2)

## A1. Dashboard — `GET /head/dashboard` · `GET /company/me/head/dashboard`

```json
{ "kpis": {
    "awaiting": 3, "finalApproved": 2, "erpPosted": 40, "rejected": 1,
    "accountantsActive": { "active": 4, "total": 5 },
    "performanceRatePct": 92, "totalReviewedThisMonth": 120, "avgReviewTimeMinutes": 8.4, "vsLastMonthDeltaPct": 6.2 },
  "pipeline": [ {"stageId":"submit","count":5}, {"stageId":"review","count":5}, {"stageId":"approved","count":3},
                {"stageId":"final","count":2}, {"stageId":"erp","count":40}, {"stageId":"reports","count":0} ],
  "brandPerformance": [
    { "brandId":"…","name":"براند","abbr":"BR","color":"#111",
      "salesHalalas":400000,"expensesHalalas":120000,"netHalalas":280000,"pctOfTarget":40.0 } ],
  "weeklyPerformance": [ {"day":"Sun","dayAr":"الأحد","thisWeek":12,"lastWeek":9} … ] }
```
- **`accountantsActive`** = `{active, total}` company accountants (HEAD-1.1 «المحاسبون النشطون n/n»).
- **`brandPerformance`** is now **real** (was hardcoded zeros): month sales/expenses per brand + `pctOfTarget` vs the sum of the brand's branch `asab_monthly_target`. `pctOfTarget` is 0 when no target is set.
- Company surface adds `pipelineCounts` (7-key funnel), `monthlySalesHalalas`, `salesDeltaPct`, `awaitingFinalApprovalPreview[5]`.

## A2. Queues — `GET /head/operations/{pending|final-approved|rejected}`

Shared filters: `moduleKey`, `brandId`, `accountantId`, `dateFrom`, `dateTo`, `erpPosted` (bool), `page`, `pageSize`.
Paginated rows (`present`):
```json
{ "id":"…","publicId":"SAL-…","accountantId":"…","accountantName":"محمد","brandId":"…","brandName":"براند",
  "branchId":"…","branchName":"فرع أ","moduleKey":"sales","moduleLabel":"المبيعات",
  "amount":100000,"amountHalalas":100000,"match":"exact","status":"approved","rejectReason":null,
  "erpPosted":false,"erpBatchId":null,"attachmentCount":2,"diffNote":null,"submittedAt":"…","operationDate":"…" }
```
`meta.summary = { count, totalAmount }`. `final-approved?erpPosted=false` = «بانتظار الترحيل»; `rejected` rows carry `rejectReason`.

## A3. Grouped pending — `GET /head/operations/pending?view=grouped`

HEAD-2.1 accountant × module groups (server-side, true totals across pages):
```json
{ "groups": [
    { "accountantId":"…","accountantName":"محمد","moduleKey":"sales","moduleLabelAr":"المبيعات",
      "count":2,"totalAmount":150000,"hasDiffs":true,
      "operations":[ {…row…} ], "moreCount":0 } ],
  "summary": { "count":3,"totalAmount":180000,"groupCount":2 } }
```
`hasDiffs=true` ⇒ render «⚠ يوجد فروق». Same filters as A2. `groupPreview` (default 5, ≤20) caps the nested ops; `moreCount` is the remainder.

## A4. Group actions

| Action | Endpoint | Body | Result |
|---|---|---|---|
| «اعتماد الكل» | `POST /operations/bulk-final-approve` | `{ operationIds[], isConditional?, conditionalNote? }` | `{ finalApproved:[publicId…], failed:[{id,code}] }` — non-approved ids fail `OP_NOT_APPROVED` |
| «إرجاع للمراجعة» | `POST /operations/{id}/return-for-review` | `{ note? }` | op → `pending`, accountant notified; `409 OP_NOT_APPROVED` otherwise |
| group return | `POST /operations/bulk-return` | `{ operationIds[], note? }` | `{ returned:[…], failed:[…] }` |
| single final-approve | `POST /operations/{id}/final-approve` | `{ isConditional?, conditionalNote (required_if), conditions[]? }` | op → `final-approved` 🔒 |

All head-only; out-of-scope ids are dropped by the zero-trust branch filter before the service runs (they simply won't appear in `finalApproved`/`returned`).

## A5. Other head reads (unchanged)

`GET /head/accountants/performance?dateFrom=&dateTo=` · `GET /head/movements/recent?limit=` · `GET /head/reports/internal` · `GET /head/reports/owner`. See [FE-T03]/earlier docs; performance keys: `rate/prevRate/rating(0-5)/approved/pending/reviewed/branches/avgTime/level+levelLabelAr+levelCls`.

---

# B. ERP export (ERP-1 / ERP-3)

## The batch model (read before wiring)

A batch groups the final-approved operations of **one (day × module)** and moves:
```
ready ──export──▶ exported          (ops stamped erp_posted, م5 step)
   │
   └─ export fails ─▶ failed ──retry──▶ exported
```
A **ready** batch is seeded automatically the moment an op is final-approved (so admin/head can see what's waiting). Batch number format: **`EXP-YYYY-MM-DD-nnn`**. Status enum: `ready` «جاهز للتصدير» · `exported` «تم التصدير لـ ERP» · `failed` «فشل التصدير» (legacy rows may read `success` → treat as exported). **Do not hardcode `success`.**

## B1. Preflight + eligible — `GET /head/erp/preflight` · `GET /head/erp/eligible-operations`

Preflight: `{ checks:[{ok,labelAr,labelEn,severity}], canProceed, warningCount }`.
Eligible (now **paginated**): `{ data:[…op rows…], meta:{ page,pageSize,total,totalPages, total:{count,amount,branches}, operations:[…legacy alias…] } }`. Filters: `moduleKey`, `branchId`, `dateFrom`, `dateTo`.

## B2. Export («ترحيل API») — `POST /erp/batches`  (head, idempotent)

Body `{ operationIds?: [], filters?: {moduleKey,branchId,dateFrom,dateTo} }`. Splits the selection into one batch per (day × module) and posts each.
```json
{ "batches": [ { "id":"…","batchId":"EXP-2026-07-12-001","moduleKey":"sales","status":"exported",
                 "operationCount":1,"totalAmount":100000,"branchCount":1,"readyAt":"…","completedAt":"…" } ],
  "count": 1, "totalAmountHalalas": 100000,
  "id":"…","batchId":"EXP-2026-07-12-001","status":"exported","createdAt":"…" }
```
No eligible ops → `422 NO_ELIGIBLE_OPS`. (Top-level `id/batchId/status/createdAt` mirror the first batch for back-compat.)

## B3. Batch log + status + retry

- `GET /head/erp/batches?moduleKey=&status=&dateFrom=&dateTo=` — paginated, **tenant-scoped** (a head sees only its company). Rows = the B2 batch shape + `statusLabelAr`.
- `GET /erp/batches/{batchId}/status` — single batch (head/admin).
- `POST /erp/batches/{batchId}/retry` — re-post a `failed` batch → `exported`; `409 BATCH_NOT_FAILED` otherwise (head/admin, idempotent).

## B4. Downloads — `GET /erp/batches/{batchId}/download.{json|csv|xlsx}`  (head/admin)

json = `{batchId, operations:[…]}`; csv = attachment; **xlsx is now a real spreadsheet** (`application/vnd.openxmlformats-…sheet`), no longer CSV-in-disguise.

## B5. Company surface — `POST /company/me/operations/{id}/post-to-erp` · `GET /company/me/erp/preview`

post-to-erp → `{ batchId, queuedOpCount, totalHalalas }`. **Note:** the ERP unit is the whole **(day × module)** batch — posting one operation exports its entire day×module batch (so `queuedOpCount` may exceed 1). This is by design (ERP-1); it never strands sibling operations unposted. preview (now **paginated**):
```json
{ "data":[…op rows…],
  "meta": { "page":1,"pageSize":50,"total":12,"totalPages":1,"count":12,"totalAmountHalalas":940000,"branches":3,
            "perRestaurant":[ {"restaurantId":"…","name":"مطعم","count":4,"amountHalalas":300000} ] } }
```
Filters: `moduleKey`, `restaurantId`, `branchId`, `status` (override), `period:{type:today|week|month|custom, from, to}`.

---

# C. Admin ERP screen (ERP-2) — `admin` only, cross-company

- `GET /admin/erp/summary` → `{ connection:{ ok, label:"متصل ونشط", lastExportAt, lastExportCount }, kpis:{ ready, exportedToday, awaitingHead, failed } }` (`awaitingHead` = approved ops not yet final-approved).
- `GET /admin/erp/batches?companyId=&moduleKey=&status=&dateFrom=&dateTo=` — paginated cross-company log (rows include `companyId`).
- `POST /admin/erp/export` → `{ batchIds?: [], allReady?: true }` exports the selected (or all `ready`) batches → `{ exported:[batchId…], failed:[…], count }`. One of `batchIds`/`allReady` required (else 422).

---

## Enums (key → labelAr, render verbatim)

- **Operation status**: `pending` «قيد المراجعة» · `approved` «معتمد - مرحلة 1» · `final-approved` «معتمد نهائياً» 🔒 · `rejected` «مرفوض».
- **ERP batch status**: `ready` «جاهز للتصدير» · `exported` «تم التصدير لـ ERP» · `failed` «فشل التصدير».
- **Pipeline stages**: `submit/review/approved/final/erp/reports` (icons 📋👀✓🔒📤📊).
- **Module labels**: sales المبيعات · expenses المصروفات · purchases المشتريات · inventory المخزون · waste الهدر · assets الأصول · shifts الورديات · employees الموظفين · cash النقدية.
- **Performance level**: `excellent` «ممتاز» · `good` «جيد» · `acceptable` «مقبول» · `needs_improvement` «يحتاج تحسين» (`levelCls` = css token; `rating` 0–5).

## Realtime

`erp.batch.completed` fires on `operations.*` rooms when a batch flips to `exported`. Final-approve seeds a ready batch silently (no push).

## Deferred / cross-references

- **HEAD-6 company-portal head reminders CREATE/DELETE** → **T16.8** (reminders domain). List/patch/mark-all-done already work on the portal.
- **HEAD-7 financial-report download cards (P&L per brand, PDF)** → **T15.18**. `reports/internal` currently gives per-module CSV links only.
- The ERP connector post is a **synchronous mock** (`config('asab.erp.force_fail')` flips it for the failed→retry path in tests); a real async/queued connector replaces `ErpBatchService::postToConnector` without changing the API.
