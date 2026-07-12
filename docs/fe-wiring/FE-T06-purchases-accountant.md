# FE Wiring — T06 Purchases (Accountant 3-way Match) — «المشتريات»

> Backend module status: ✅ ready for integration · Delivered 2026-07-12 · Tests: 24 green
> Base URL: `/api/v1` · Auth: `Authorization: Bearer <token>` (Sanctum, guard `asab`)
> Envelopes (`AsabResponse`): success = bare object, or `{ "data": [...], "meta": {...} }` for lists.
> Errors: `{ "error": { "code", "message", "messageAr", "details" }, "requestId" }` + the HTTP status.
> Money: integer **halalas** everywhere (`unitPriceHalalas`, `totalHalalas`, `amount`). Divide by 100, format `ar-SA`.
> Quantities are **decimals** (kg/L) — `ordQty`, `rcvQty`, `diffQty` may be fractional; JSON serialises whole numbers without a `.0`.
> **Read [FE-T03](FE-T03-operations-pipeline.md) first** — purchases ride the shared pipeline; list/detail/approve/reject/final-approve/audit-trail/correction/notes all live there.

## The 3-way match model (read this before wiring the table)

A purchase order is verified along **three legs**. Two are live today; the third is reserved so the payload never has to change when the supplier-invoice feed lands:

| Leg | Field pair | Compared |
|---|---|---|
| Quantity | `ordQty` (ordered) vs `rcvQty` (received) | goods actually delivered |
| Price | `orderedUnitPriceHalalas` (PO) vs `unitPriceHalalas` (invoice) | what the supplier billed per unit |
| _(reserved)_ invoiced qty | — | future supplier-invoice feed |

`rcvQty` is `null` until the goods are received (or an accountant enters it). **A `null` received qty is `pending`, never a shortfall** — do not render it as a red diff.

Per-line badge `lineMatch.key`:

| key | labelAr | icon | when |
|---|---|---|---|
| `matched` | مطابق | ✅ | received == ordered **and** invoice price == PO price |
| `diff` | فرق | ⚠️ | a confirmed disagreement on quantity **or** price |
| `pending` | بانتظار الاستلام | ⏳ | not yet received (`rcvQty === null`) |

Operation-level `match` (the row badge) rolls the lines up: `diff` if any line is `diff`, else `review` if any line is `pending`, else `exact`. Editing a line recomputes it **both ways** (fixing a diff returns the op to `exact` and clears `diffNote`).

## Screens covered

| Prototype screen (ACC-3) | Endpoints |
|---|---|
| «بيانات المشتريات» tab: KPI header | `GET /company/me/operations?moduleKey=purchases` → `meta.summary.purchases` |
| PO rows table (المورد / تاريخ الاستلام / عدد الأصناف / فرق) | same call → `data[].purchaseRow` |
| Filters: مورد / التاريخ / المصدر | same call → `?supplierId=…&dateFrom=…&dateTo=…&source=…` |
| Detail modal: 3-way match table + summary tiles + mismatch banner | `GET /company/me/operations/{id}` → `purchases` |
| Detail modal: attachments | same call → `purchases.attachments[]` (or shared `GET /operations/{id}/attachments`) |
| «توثيق» button | `POST /company/me/operations/{id}/document` |
| Edit line (سعر/كمية) | `PATCH /company/me/operations/{id}/purchase-lines/{rowId}` |
| «رفض مع ذكر السبب» / approve / close | T03: `POST /operations/{id}/reject`, `/approve`, head `/final-approve` |
| «الموردون المعتمدون» tab: supplier cards | `GET /company/me/suppliers` |
| «المرتجعات» tab | `GET /company/me/purchases/returns` |
| Enum bootstrap (labels/badges/sources) | `GET /lookups/purchase-enums` |

## Conventions

- Two surfaces, same handlers: company portal `/v1/company/me/*` (role `accountant`, `Idempotency-Key` honoured on mutations) and internal `/v1/*` (role `accountant,head`). **`document`, `purchase-lines`, `purchases/returns` exist on both**; the company SPA uses the `/company/me/*` twin.
- Detail, reject, audit-trail, correction have **no `/company/me` alias** — call shared `/api/v1/operations/{id}…` (same auth/tenant middleware). See FE-T03.
- `{id}` accepts uuid or `publicId` (`PUR-0042`). `{rowId}` is the line's `rowId` (see below).
- Everything is assigned-branch scoped: an accountant on brand A never sees brand B's purchases, and out-of-scope ids answer **404**.

---

## 1. List + KPI — `GET /company/me/operations?moduleKey=purchases`

Shared pipeline list (FE-T03 §list) with purchases extras. Query: `page`, `pageSize` (≤100), `status`, `branchId`, `dateFrom`, `dateTo`, `search` (public_id), **`supplierId`**, **`source`** (`supplier|branch|procurement`).

> ⚠️ `source` is a **derived** filter applied to the current page (not a SQL column) — paginate with the other filters first, then read `data`. `supplierId`/date filters are full-set.

Each `data[]` row carries the generic envelope (FE-T03 `present()`) **plus**:

```json
{
  "id": "019f…", "publicId": "PUR-0042", "moduleKey": "purchases",
  "match": "diff", "matchLabelAr": "مراجعة/فرق", "status": "pending",
  "amount": 540000,
  "purchaseRow": {
    "supplierId": "019f…", "supplierName": "مورد الخضار",
    "orderSource": { "key": "procurement", "labelAr": "مدير المشتريات" },
    "receiveDate": "2026-07-13",
    "itemCount": 3,
    "orderedTotalHalalas": 540000,
    "receivedTotalHalalas": 480000,
    "hasDiff": true,
    "isDocumented": false
  }
}
```

`meta.summary` is the generic status counts **plus** `purchases` (only when `moduleKey=purchases`):

```json
"summary": {
  "total": 12, "pending": 5, "approved": 4, "finalApproved": 2, "rejected": 1,
  "purchases": {
    "todayTotalHalalas": 600000,   // every order dated today, any status
    "pendingReview": 5,
    "qtyDiscrepancies": 3,          // open (pending|approved) ops with match=diff
    "approvedToday": 1
  }
}
```

## 2. Detail (3-way match) — `GET /company/me/operations/{id}`

Shared `show()` (FE-T03) + a `purchases` block when `moduleKey=purchases`:

```json
"purchases": {
  "supplierId": "019f…", "supplierName": "مورد الخضار",
  "orderSource": { "key": "procurement", "labelAr": "مدير المشتريات" },
  "urgency": "normal", "deliveryDate": "2026-07-13", "description": null,
  "purchaseItems": [
    {
      "rowId": "itm-1", "item": "طماطم", "itemId": "itm-1", "unit": "كجم",
      "ordQty": 10, "rcvQty": 8,
      "unitPriceHalalas": 5000, "orderedUnitPriceHalalas": 5000,
      "totalHalalas": 50000, "receivedValueHalalas": 40000,
      "diffQty": -2, "qtyMatched": false, "priceMatched": true, "received": true,
      "lineMatch": { "key": "diff", "labelAr": "فرق", "icon": "⚠️" },
      "diffNoteAr": "نقص في الكمية: 2 كجم (طماطم)"
    }
  ],
  "summary": {
    "lineCount": 1,
    "orderedValueHalalas": 50000, "receivedValueHalalas": 40000,
    "qtyDiscrepancyCount": 1, "priceDiscrepancyCount": 0, "pendingReceiptCount": 0,
    "isMatched": false, "hasMismatch": true
  },
  "isDocumented": false, "documentation": null,
  "attachments": [ { "id": "…", "filename": "invoice.pdf", "publicUrl": "…", "label": "invoice", "verifiedAt": null } ]
}
```

Render the mismatch banner when `summary.hasMismatch`; the «مطابق تام» state when `summary.isMatched`. `rowId` is the key you pass to the line editor.

## 3. توثيق (document) — `POST /company/me/operations/{id}/document`

Body (optional): `{ "note": "طوبقت الفاتورة" }`. Marks the order documented before it goes to the head. **Idempotent** — safe to retry; re-calling refreshes the timestamp, never duplicates. Status is unchanged.

Response: the shared operation `present()` object (FE-T03). `purchases.isDocumented` flips to `true`; detail `documentation` becomes `{ documentedAt, documentedById, documentedBy, note }`.

Errors: `409 OP_ALREADY_FINAL` on a locked (final-approved / rejected) op · `403` for non-accountant/head · `404` out of scope.

## 4. Line edit — `PATCH /company/me/operations/{id}/purchase-lines/{rowId}`

Body (all optional, at least one): `ordQty` (numeric ≥0), `rcvQty` (numeric ≥0 **or `null`**), `unitPriceHalalas` (int ≥0).

```json
// PATCH …/purchase-lines/itm-1  { "unitPriceHalalas": 5500 }
{ "operationId": "019f…", "rowId": "itm-1",
  "row": { "rowId": "itm-1", "ordQty": 10, "rcvQty": 10, "unitPriceHalalas": 5500,
           "totalHalalas": 55000, "priceMatched": false, "qtyMatched": true,
           "lineMatch": { "key": "diff", "labelAr": "فرق", "icon": "⚠️" } },
  "match": "diff", "amount": 55000 }
```

Recomputes: line `totalHalalas`, op `amount` (= Σ line totals), op `match` + `diffNote`. **A diverging `unitPriceHalalas` flips the op to `diff` even when quantity agrees** — the price leg counts. Editing back to agreement returns `match` to `exact`. Every edit writes an audit step (old→new) visible in `GET /operations/{id}/audit-trail`.

> For a legacy-linked op (`source_module='purchase'`), `rcvQty` is authoritative from the goods-receipt feed and a manual `rcvQty` here is overridden on the next read. For dashboard-native orders (procurement / branch request), your `rcvQty` is stored and used.

Errors: `404` unknown `rowId` / non-purchase op / out of scope · `409 OP_ALREADY_FINAL` locked · `403` role.

## 5. Suppliers («الموردون المعتمدون») — `GET /company/me/suppliers`

Tenant-scoped, paginated. Query: `search`, `category`, `status`, `page`, `pageSize` (≤100). Each card:

```json
{ "id": "019f…", "name": "مورد الخضار", "category": "خضروات",
  "contactName": "…", "contactPhone": "…", "contactEmail": "…", "paymentTerms": "…",
  "rating": 45, "status": "active", "isActive": true,
  "itemsCount": 12, "monthlyOrderCount": 4 }
```

`rating` is a **0–50** scale (stars × 10) — divide by 10 for the ★ display. `itemsCount` = catalog items; `monthlyOrderCount` = purchases in the last 30 days. Canonical read; `/company/me/procurement/suppliers` is a procurement-SPA alias of the same handler. **Never** use `/lookups/suppliers` (legacy store, id+name only).

## 6. Returns («المرتجعات») — `GET /company/me/purchases/returns`

Read-only window onto the legacy `return_orders`, scoped to your legacy branch set. Query: `status`, `branchId`, `dateFrom`, `dateTo`, `page`, `pageSize` (≤100).

```json
{ "data": [ {
  "id": "…", "returnNumber": "RET-1", "orderNumber": "PO-AB12",
  "supplierId": null, "supplierName": null,
  "branchId": "019f…", "branchName": "فرع أ",
  "returnDate": "2026-07-12",
  "status": "pending", "statusLabelAr": "قيد المراجعة",
  "totalReturnAmountHalalas": 15000, "refundAmountHalalas": 15000, "itemCount": 3
} ], "meta": { "page": 1, "pageSize": 20, "total": 1 } }
```

`supplierName` is best-effort (returns reference legacy supplier ids; often `null`). Role `accountant,head` only — procurement/branch/supplier tokens get `403`. Money already halalas.

## 7. Enums — `GET /lookups/purchase-enums`

```json
{ "orderSource": [ { "key": "supplier", "labelAr": "مورد" }, { "key": "branch", "labelAr": "فرع آخر" }, { "key": "procurement", "labelAr": "مدير المشتريات" } ],
  "lineMatch":  [ { "key": "matched", "labelAr": "مطابق", "icon": "✅" }, { "key": "diff", "labelAr": "فرق", "icon": "⚠️" }, { "key": "pending", "labelAr": "بانتظار الاستلام", "icon": "⏳" } ],
  "returnStatus": [ { "key": "pending", "labelAr": "قيد المراجعة" }, … ] }
```

Fetch once at boot; never hardcode the Arabic labels.

## Enum quick-reference (key → labelAr)

- **orderSource**: `supplier` مورد · `branch` فرع آخر · `procurement` مدير المشتريات
- **lineMatch**: `matched` مطابق · `diff` فرق · `pending` بانتظار الاستلام
- **op match** (row/detail badge): `exact` مطابقة · `diff` فرق · `review` مراجعة
- **op status**: `pending` قيد المراجعة · `approved` معتمد (أُرسل لرئيس الحسابات) · `final-approved` معتمد نهائياً · `rejected` مرفوض
- **returnStatus**: `draft` مسودة · `pending` قيد المراجعة · `approved` معتمد · `rejected` مرفوض · `escalated` مُصعّد · `closed` مُغلق · `resolved` مُسوّى

## Still missing / deferred (not in T06)

- **Supplier-invoice leg** (true 3-way): no data source exists yet; the invoice price is the accountant-entered `unitPriceHalalas`. When a supplier-invoice feed lands, `orderedUnitPriceHalalas` (PO) vs an `invoicedUnitPriceHalalas` slot in with no payload change.
- **Returns write actions** (approve/reject/escalate) stay in the mobile app for v1 — this surface is read-only.
- **Multi-source orders** (`order_type=multiple_sources`) resolve to a single `orderSource=procurement`; per-line supplier is not surfaced.
- Received-qty for dashboard-native orders is accountant-entered via the line editor until a receiving flow exists on the dashboard.
