# FE Wiring — T07 Inventory & Waste — «المخزون والهدر»

> Backend module status: ✅ ready for integration · Delivered 2026-07-12 · Tests: 21 green
> Base URL: `/api/v1` · Auth: `Authorization: Bearer <token>` (Sanctum, guard `asab`)
> Envelopes (`AsabResponse`): success = bare object, or `{ "data": [...], "meta": {...} }` for lists.
> Errors: `{ "error": { "code", "message", "messageAr", "details" }, "requestId" }` + HTTP status.
> Money: integer **halalas** (`amount`, `value`, `valueHalalas`, `unitPriceHalalas`, `*Halalas`). Divide by 100.
> Quantities are **decimals** (kg/L). **Read [FE-T03](FE-T03-operations-pipeline.md) first** — inventory & waste are pipeline operations (`module_key=inventory|waste`); approve/reject/final-approve/audit-trail come from there.

## Two surfaces, one handler set

| Surface | Prefix | Role | Notes |
|---|---|---|---|
| Company portal | `/v1/company/me/*` | `accountant` | `Idempotency-Key` honoured on mutations |
| Internal/accountant | `/v1/accountant/*` | `accountant,head` | daily-reconciliation + daily-variance-allocation live **only** here |

**Canonical vs alias** (send the canonical):

| Canonical | Alias |
|---|---|
| `POST .../inventory/branches/{id}/flag-items` (company) | `.../flagged-items`; accountant `.../items/flag` |
| `POST .../inventory/branches/{id}/mark-confirmed` | `.../confirm` |
| body `itemIndices` | `itemIndexes` |
| company `GET/PUT .../branches/{id}/inventory-list` | accountant `GET/PUT .../inventory/branches/{id}/daily-list` |

## Screens covered

| Prototype (ACC-4 / ACC-5) | Endpoint |
|---|---|
| Inventory review: branch cards + monthly compare table + % chips | `GET /company/me/inventory/branches?type=monthly` → `branches[].items[]` |
| KPI header (low items · waste today · waste rate · anomalies) | same call → `summary` |
| 🚩 flag branch / click-to-flag items / «إرسال (n)» | `POST .../flag`, `.../flag-items`, `.../send-notification` |
| «أكّده الفرع» | `POST .../mark-confirmed` |
| Daily equation table (فتح…إغلاق) + stock status | `GET /accountant/inventory/branches/{id}/daily-reconciliation` |
| «تحميل فرق الجرد على موظفين» | `POST /accountant/inventory/branches/{id}/daily-variance-allocation` |
| Item-selection / daily-list config («تحديد أصناف الجرد») | `GET .../inventory/items?search=` → `PUT .../inventory-list` |
| Waste table + KPIs | `GET /company/me/waste` |
| Product row: هدر/تالف · موظف/مطعم toggle | `PATCH /company/me/waste/{id}/products/{idx}` |
| «تحميل على موظفين» panel | `PUT /company/me/waste/{id}/products/{idx}/allocations` |
| Approve / bulk-approve / reject | `POST .../waste/{id}/approve`, `.../bulk-approve`, `.../reject` |
| Excel exports | `GET .../inventory/export`, `.../waste/export` (`?format=xlsx|csv`) |
| Enum bootstrap | `GET /lookups/purchase-enums`-style — waste labels below |

---

## 1. Inventory review — `GET /company/me/inventory/branches` (alias `/accountant/inventory`)

Query: `type` (`monthly` default | `daily`), `branchId`.

```json
{ "branches": [ {
    "branchId": "019f…", "operationId": "019f…", "status": "pending",
    "items": [ {
      "itemId": "sku-1", "itemName": "أرز", "unit": "كجم",
      "prevQty": 100, "currQty": 40, "changePct": -60,
      "chip": "red", "isAnomaly": true, "isLow": false
    } ],
    "anomalyCount": 1, "isFlagged": false, "branchConfirmed": false, "flaggedItemIndices": []
  } ],
  "summary": {
    "totalSubmissions": 3, "uploadedCount": 3, "pendingCount": 2, "completedBranches": 1,
    "anomalyAlerts": 1, "lowItems": 0, "normalItems": 5,
    "totalWasteTodayHalalas": 120000, "wasteRatePct": 3.2
  } }
```

- `changePct` per **BR-08** (`(curr − prev) / prev × 100`); `null` when no previous month. **`isAnomaly` is computed server-side** (|change| > 50%) — do not trust any payload flag.
- `chip`: `red` (< −30%), `green` (> +30%), `neutral`, or `null` (no baseline).
- `type=daily` omits the monthly baseline (`prevQty=null`).

## 2. Daily equation — `GET /accountant/inventory/branches/{branchId}/daily-reconciliation?date=YYYY-MM-DD`

```json
{ "branchId": "…", "date": "2026-07-12",
  "items": [ {
    "itemId": "sku-1", "itemName": "أرز", "unit": "كجم",
    "opening": 100, "received": 50, "consumed": 30, "waste": 5, "transfers": 0,
    "expectedClosing": 115, "actualClosing": 110, "equationMatch": false,
    "minLevel": 120, "stockStatus": { "key": "low", "labelAr": "منخفض" },
    "varianceQty": 5, "variancePct": 4.35, "varianceValueHalalas": 5000,
    "status": "ok", "allocatedTo": [ … ]
  } ],
  "totalVarianceValueHalalas": 5000, "unassignedVarianceValueHalalas": 5000 }
```

Equation: `expectedClosing = فتح + مشتريات − استهلاك − هدر ± تحويلات`. `equationMatch` is `null` when the branch didn't submit the equation terms (falls back to the `expectedQty` variance). `stockStatus`: `critical` «حرج» (≤ half of min), `low` «منخفض» (< min), `normal` «طبيعي».

## 3. Daily variance allocation — `POST /accountant/inventory/branches/{branchId}/daily-variance-allocation`

Body: `{ date, items: [ { itemId, allocations: [ { employeeId|empNumber, qty } ] } ] }`. Posts «تحميل فرق جرد يومي» debits (value = qty × unit price). **Idempotent (T07.5)**: re-posting the same date replaces the prior debit set — retries and re-saves never double-charge. `404 «لا يوجد جرد لهذا التاريخ»` when no submission exists; `422` unknown employee / employee of another branch.

## 4. Waste list — `GET /company/me/waste` (alias `/accountant/waste`)

Paginated. Query: `status`, `branchId`, `search` (public_id / product), `page`, `pageSize` (≤100).

```json
{ "data": [ {
    "id":"…", "publicId":"WD-0007", "branchId":"…", "brandId":"…", "brandName":"براند",
    "date":"2026-07-12T…", "status":"pending", "amount":35000,
    "productsCount": 2, "employeeChargedHalalas": 30000,
    "products": [ { "name":"دجاج", "classification":"هدر", "responsibility":"موظف", "value":30000, "empAllocs":[…] } ]
  } ],
  "meta": { "page":1, "pageSize":20, "total":1, "summary": {
     "total":1, "pendingReview":1, "approvedThisMonth":0,
     "totalLossesHalalas":35000, "chargedToEmployeesHalalas":30000 } } }
```

`employeeChargedHalalas` = «منه على موظفين» (Σ empAllocs of «موظف» products).

## 5. Classify — `PATCH /company/me/waste/{id}/products/{idx}`

Body: `{ classification?: "هدر"|"تالف", responsibility?: "موظف"|"مطعم" }`. `422` on any other value; `404` bad index; `409` on a locked op.

## 6. Allocations — `PUT /company/me/waste/{id}/products/{idx}/allocations`

Body: `{ empAllocs: [ { employeeId|empNumber, amountHalalas } ] }`. Same contract as the sales shortfall panel: each employee resolved in the op's branch, and for a **«موظف»** product the amounts must **sum to the product `value`** — else `422 «يجب أن يساوي مجموع التخصيصات قيمة الفارق»`. `422` unknown employee. Stored normalised with resolved `employeeId`.

## 7. Approve / bulk-approve — `POST /company/me/waste/{id}/approve` · `.../bulk-approve`

Approving **posts «خصم هدر» debits** for every «موظف» product's allocations, atomically with the status change (T07.7). «مطعم» products post nothing. **Idempotent**: a re-approve is `409` and posts nothing extra; the ledger post reverses any prior set first. These debits surface on the employee statement (ACC-7) and feed the «منه على موظفين» KPI. Reject (`POST .../reject`, `reason` required) posts nothing. `bulk-approve` body: `{ entryIds?: [...] }` or `{ branchId }` (all pending of a branch) → `{ approved: [publicId…], failed: [{id,code}] }`.

## 8. Daily-list config + instant push — `PUT /company/me/branches/{branchId}/inventory-list`

Body: `{ items: [catalogItemId…] }`. Replaces the branch's count list, then **pushes (T07.1)**: a realtime `inventory.daily_list_updated` on `reminders.branch.{branchId}` **and** a durable notification to the branch's branch-manager users. `pushedAt` in the response reflects a push that actually happened. Item selection feeds from `GET /company/me/inventory/items?search=&category=&brandId=`.

## 9. Flag / send / confirm

- `POST .../inventory/branches/{id}/flag` `{ flagged: bool }`
- `POST .../inventory/branches/{id}/flag-items` `{ itemIndices: [int], note? }`
- `POST .../inventory/branches/{id}/send-notification` (company) / `.../send-confirmation` (accountant) → realtime **+ durable notification** to the branch manager (T07.9)
- `POST .../inventory/branches/{id}/mark-confirmed` `{ confirmed?: bool }` → stamps `branchReconfirmedAt`; the review list's `branchConfirmed` flips true. `404` when the branch has no submission.

## 10. Exports — `GET .../inventory/export` · `.../waste/export`

`?format=xlsx|csv`, binary stream (no envelope). Available on **both** surfaces now. Branch-scoped: a branch-restricted accountant's file contains only assigned branches (waste export is now scope-guarded — T07.11).

## Enums (key = labelAr, stored as the Arabic string itself)

- **classification**: `هدر` · `تالف`
- **responsibility**: `موظف` (charges the employee ledger) · `مطعم`
- **chip**: `red` (< −30%) · `green` (> +30%) · `neutral` · `null`
- **stockStatus**: `critical` حرج · `low` منخفض · `normal` طبيعي
- **op status**: `pending` قيد المراجعة · `approved` أُرسل لرئيس الحسابات · `final-approved` مُغلق · `rejected` مرفوض

## Realtime channels

- `reminders.branch.{branchId}` → `inventory.flag_sent`, `inventory.daily_list_updated`
- `operations.brand.{brandId}` → `inventory.variance_allocated`

## Still missing / deferred (not in T07)

- **Daily equation flow terms** (opening/received/consumed/waste/transfers) are read from the branch's inventory submission payload — not re-derived by joining the day's sales/purchases/waste ops. When a branch omits them, `equationMatch=null` and the variance falls back to `expectedQty`.
- Waste export has no «منه على موظفين» column yet (value present in the JSON list).
- `idempotency` middleware not added to the accountant route group — the daily-variance double-post is fixed at the data layer (reverse-then-insert), which is retry-safe regardless.
