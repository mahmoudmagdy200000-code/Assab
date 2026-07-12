# FE Wiring — T11 Procurement (مدير المشتريات) — «المشتريات»

> Backend module status: ✅ ready for integration · Delivered 2026-07-12
> Base URL: `/api/v1` · Auth: `Authorization: Bearer <token>` (Sanctum, guard `asab`)
> Envelopes (`AsabResponse`): single = **bare object** (no `{success,...}` wrapper — this module predates the `BaseController` format); lists = `{ "data": [...], "meta": {...} }`.
> Errors: `{ "error": { "code", "message", "messageAr", "details" }, "requestId" }` + HTTP status.
> Binary exports (xlsx/csv) stream raw, no JSON envelope.
> Supersedes `docs/PROCUREMENT_DASHBOARD_API.md` — this is the source of truth.

## 0. TWO pipelines — never mix formatters or screens

Procurement has two coexisting order worlds. Wire the primary «جديدة/مجمعة/مرسلة» screens to the **bridge**; the Operations family backs manual dashboard-created orders only.

| | Bridge family | Operations family |
|---|---|---|
| Paths | `.../procurement/purchase-orders*` | `.../procurement/orders*`, `items*`, `suppliers*` |
| Store | legacy mobile `purchase_orders` | `asab_operations` |
| **Money** | **SAR floats** (`totalAmount`, `unitPrice`) | **integer halalas** (`totalHalalas`, `amount`) — ÷100 |
| Meaning | the REAL branch→procurement loop (mobile app orders) | orders the manager types on the dashboard |

Two surfaces, same handlers: **company** `/api/v1/company/me/procurement/*` (recommended — honours `Idempotency-Key`, audited) and **platform** `/api/v1/procurement/*` (identical, no idempotency/audit). Role for everything below: `procurement` (suppliers read is any company role).

---

## Screens covered

| Prototype screen | Endpoints |
|---|---|
| لوحة التحكم (KPIs) | `GET .../procurement/overview` |
| الطلبات الجديدة (من الفروع) | bridge `purchase-orders?status=incoming` → show / approve / partial-approve / reject / bulk-approve |
| الطلبات المجمعة — حسب المورد / المدينة | bridge `purchase-orders/grouped?by=supplier\|city` |
| الطلبات المجمعة — **حسب الصنف** (بطاقة التجميع) | bridge `purchase-orders/grouped?by=item` |
| «تجميع وإرسال للمورد» | bridge `purchase-orders/grouped/send` |
| المرسلة للموردين + التتبع | bridge `purchase-orders/sent` + `purchase-orders/groups/{groupId}` |
| «أمر شراء جديد» modal | Operations `POST .../procurement/orders` |
| الأصناف (CRUD + سجل الأسعار) | `GET/POST/PATCH/DELETE .../procurement/items`, `items/{id}/price-history`, `items/export` |
| الموردون (CRUD + تقييم + تفعيل) | `.../suppliers*` (+ `procurement/suppliers*` aliases) |

---

## 1. Overview KPIs — `GET .../procurement/overview`

Bare object. KPI cards mix Operations counts and the REAL bridge pipeline + monthly savings (T11.8).

```json
{
  "kpis": {
    "newOrders": 4, "consolidated": 2, "sentToSuppliers": 1, "ordersValueThisWeek": 128000,
    "incoming": 7, "readyToSend": 3, "sentAwaitingConfirmation": 2
  },
  "monthlySavings": { "amount": 1600.0, "pctOfPurchases": 11.11, "trendPct": 100.0 },
  "newOrders": [
    { "id": "019f…", "publicId": "PUR-0042", "branchId": "019f…", "total": 32000,
      "urgency": "urgent", "urgencyLabel": "عاجل" }
  ]
}
```

- `kpis.newOrders/consolidated/sentToSuppliers/ordersValueThisWeek` = Operations family (halalas).
- `kpis.incoming` = real branch orders awaiting a decision · `readyToSend` = confirmed & ungrouped · `sentAwaitingConfirmation` = sent, supplier not yet acting. **Use these for the headline cards, not the Operations counts.**
- `monthlySavings.amount` is **SAR** (bridge world). `trendPct` = vs last month (100 when last month was 0 and this month > 0).
- `newOrders[].urgency` ∈ `normal|urgent`; render `urgencyLabel` (عادي/عاجل). Old builds read a dead `عاجل`-from-`match` field — gone.

---

## 2. Bridge pipeline (SAR) — الطلبات الجديدة → المرسلة

Full detail in the endpoint table. All ids accept **UUID or orderNumber**.

### 2.1 List / detail / decisions

| Method | Path | Purpose |
|---|---|---|
| GET | `purchase-orders?status=incoming\|<status>&branchId=&priority=high\|normal&page=&pageSize=` | الطلبات الجديدة (paginated) |
| GET | `purchase-orders/{id}` | detail + line items + consumption context «بيانات الاستهلاك» |
| POST | `purchase-orders/{id}/approve` | confirm all lines → `confirmed` · 409 `ORDER_NOT_DECIDABLE` |
| POST | `purchase-orders/{id}/partial-approve` `{items:[{orderItemId,quantity}],note?}` | per-line qty; `0` rejects a line (note → rejection reason); all-zero = full reject |
| POST | `purchase-orders/{id}/reject` `{reason}` | reason required (422 without) |
| POST | `purchase-orders/bulk-approve` `{orderIds:[…]}` | «اعتماد الكل» — per-order failures + not-found reported, never fatal |
| GET | `purchase-orders/approved-by-me` | «الطلبات المعتمدة» |

`status=incoming` = `pending`+`emergency`+`variance`. `capacity: null` on a grouped item = supplier didn't declare stock → render «—», not a warning.

### 2.2 Consolidation preview — `GET purchase-orders/grouped?by=supplier|city|item`

- `by=supplier` (default) → `{ suppliers:[…], unassignedOrders:[…] }` — per-item qty vs supplier declared stock (`capacity`, `capacityPct`, `exceeded`).
- `by=city` → `{ cities:[…] }`.
- **`by=item`** (T11.7 — the SRS core value loop) → `{ items:[…] }`, one card per catalog item:

```json
{ "items": [{
  "itemId": "019f…", "name": "صدر دجاج", "unit": "kg",
  "requestsCount": 2, "branchesCount": 2,
  "branchLines": [
    { "branchId": "019f…", "branchName": "فرع الرياض", "qty": 200, "unit": "kg" },
    { "branchId": "019f…", "branchName": "فرع جدة",    "qty": 120, "unit": "kg" }
  ],
  "totalQuantity": 320,
  "suggestedSupplier": { "id": "019f…", "name": "مورد الأصناف", "unitPrice": 40.0 },
  "unitPrice": 40.0, "totalCost": 12800.0,
  "savings": 1600.0, "savingsPct": 11.11,
  "status": "new", "orderIds": ["019f…","019f…"]
}]}
```

- `suggestedSupplier` = cheapest **available** supplier offer for the item; `null` when none priced.
- `savings`/`savingsPct` = `null` when there is no suggested price (render «—», not 0). SAR.
- Render the card as «{requestsCount} طلبات من {branchesCount} فروع» + the per-branch lines + suggested supplier + savings badge.

### 2.3 Send batch — `POST purchase-orders/grouped/send`

Body `{ supplierId, orderIds?, expectedDeliveryDate? }`. `orderIds` omitted = all consolidatable for the supplier. `201`:

```json
{ "groupId": "019f…", "groupNumber": "GRP-20260712-AB12", "supplierId": "019f…",
  "ordersCount": 2, "savings": 1600.0, "savingsPct": 11.11, "eta": "2026-08-01", "sentAt": "2026-07-12T…Z" }
```

- Savings are snapshotted onto the batch at send (T11.8). `eta` = supplied `expectedDeliveryDate`, else the earliest member-line `next_supply_date`, else `null` (T11.9).
- `409 CONSOLIDATION_FAILED` on supplier mismatch / empty set / ids outside tenant.

### 2.4 Sent + tracking

- `GET purchase-orders/sent` → `{ data:[…] }` (max 100). Each row: `groupId, groupNumber, supplierId, supplierName, ordersCount, totalAmount, status, statusLabel, savings, savingsPct, eta, sentAt`.
- `GET purchase-orders/groups/{groupId}` → header + aggregated `items` + member `orders`.
- **Group status is derived live — poll to refresh chips.**

**Group status (post-T11.10):**

| key | Arabic | when |
|---|---|---|
| `sent` | أُرسل للمورد | just sent, supplier hasn't acted yet |
| `confirmed` | مؤكد | reserved for explicit supplier acceptance (supplier portal — future) |
| `preparing` | قيد التحضير | a member order reached preparing |
| `on_the_way` | في الطريق | a member order dispatched |
| `delivered` | تم التسليم | all delivered/closed |

> Fix vs old behaviour: a freshly-sent batch used to read «مؤكد» before the supplier did anything. It now reads `sent`.

---

## 3. Operations family (halalas) — manual orders + catalog + suppliers

### 3.1 «أمر شراء جديد» — `POST .../procurement/orders`

Body: `{ supplierId, brandId?, branchId?, urgency?(normal|urgent), deliveryDate|deadline?, items:[{itemId?, qty?, unitPriceHalalas?, totalHalalas?}] }`.
- `totalHalalas` per line wins; else `qty × unitPriceHalalas`. Prices validated `integer ≥ 0` (negative → 422) (T11.14).
- `brandId` is persisted into the payload (T11.14); the row's `origin` column is stamped `procurement` (chip «🛒 سير المشتريات»).
- `201 { id, publicId, status, totalHalalas }`.

### 3.2 Consolidate / send (Operations)

| Method | Path | Notes |
|---|---|---|
| POST | `orders/consolidate` `{orderIds:[…], supplierId}` | Every id must resolve to a **pending** op, else `422 CONSOLIDATION_FAILED` with `details.missing` / `details.invalid`. Returns `{ consolidatedGroupId, orderCount }` (matched count) (T11.3). |
| POST | `orders/grouped/{groupId}/send` (canonical) · `grouped/{groupId}/send` (alias) | Unknown group → `404 NOT_FOUND`. Returns `{ groupId, batchId:"PO-BATCH-…", sent }` (T11.3). |
| POST | `orders/approve` `{orderIds?, branch?, supplier?}` | bulk approve; atomic. |
| POST | `orders/{id}/approve` · `orders/{id}/reject` `{reason}` | pipeline transitions. |
| POST | `orders/{id}/partial-reject` `{reason, rejectedItemIds:[…], note?}` | `rejectedItemIds` must be a subset of the order's payload items (else `422 INVALID_ITEM_IDS`); status → `partial_reject` («مرفوض جزئياً»), writes an audit step (T11.5). |
| PATCH | `orders/{id}` | edit payload / drive status via the pipeline. **`status=final-approved` → `409 OP_ALREADY_FINAL`** (only the head accountant may finalise) (T11.4). |
| DELETE | `orders/{id}` | soft-delete; a `final-approved`/`rejected` op is locked → `409 OP_ALREADY_FINAL` (T11.4). |
| GET | `orders/grouped` · `orders/sent` | supplier-grouping preview / sent list. **Bounded to 100** with `meta.limit` (T11.6). `orders/sent` rows carry `eta`, `inTransit`, `etaLabel`. |

### 3.3 Items — `.../procurement/items`

- `GET items?search=&category=&supplierId=&page=&pageSize=` → paginated. Row (T11.11):
  `{ id, code, name, unit, category, brandId, brandName, supplierId, supplierName, supplierCount, lastPriceHalalas, available, status }`.
  `supplierCount` = distinct suppliers that have priced the item.
- `POST items` `{ name, unit, lastPriceHalalas|defaultPriceHalalas?, category?, supplierId?, brandId?, code? }` — write-through to the mobile catalog; seeds a price-history point.
- `PATCH items/{id}` `{ name?, unit?, lastPriceHalalas?, status?, brandId?, supplierId? }` — a price change appends an attributed history point.
- `DELETE items/{id}` — deactivates bridged mobile rows, then soft-deletes.
- `GET items/{id}/price-history` → `{ data:[{ supplierId, supplierName, priceHalalas, recordedAt }] }`, newest first. **`supplierId`/`supplierName` are now populated** from the item's supplier at write time (T11.12) — enables «مقارنة الأسعار» per supplier (legacy rows may still be null).
- `GET items/export?format=xlsx|csv` — binary.

### 3.4 Suppliers — `.../suppliers*` (canonical) + `.../procurement/suppliers*` (alias)

- `GET suppliers?search=&category=&status=&page=&pageSize=` → paginated. Row (T11.13):
  `{ id, name, category, contactName, contactPhone, contactEmail, paymentTerms, rating, status, isActive, itemsCount, monthlyOrderCount, lifetimeOrdersCount, lifetimeSpend }`.
  `rating` is **0–50** (stars × 10). `lifetimeSpend` is SAR (bridge SAR + Operations halalas→SAR).
  `meta.kpis` = `{ activeSuppliers, totalPurchases (SAR), avgRating (0–5 stars) }`.
- `POST suppliers` `{ name, category?, contactName?, contactPhone|phone?, contactEmail|email?, commercialReg?, paymentTerms?, brandId? }` (role `procurement,company-admin`) — provisions a login-capable mobile supplier. `422 EMAIL_CONFLICT` if the email belongs to another company.
- `PATCH suppliers/{id}` · `POST suppliers/{id}/toggle-active` (role `procurement,company-admin`).
- `POST suppliers/{id}/ratings` `{ rating:1..5, comment? }` (role `procurement,branch`) — recomputes avg (`6` → 422).
- `GET suppliers/export?format=xlsx|csv` (any company role).

---

## 4. Canonical vs alias (call the canonical)

| Purpose | Canonical | Alias |
|---|---|---|
| Group send (Operations) | `POST .../procurement/orders/grouped/{groupId}/send` | `.../procurement/grouped/{groupId}/send` |
| Suppliers list/CRUD | `.../company/me/suppliers*` | `.../company/me/procurement/suppliers*` |
| Bulk approve (Operations) | `POST .../procurement/orders/approve` | — |
| Whole module | company `/company/me/procurement/*` (idempotency + audit) | platform `/procurement/*` (identical, no idempotency) |

---

## 5. Enums (key ↔ Arabic)

**Bridge order status:** `pending` معلق · `emergency` طارئ · `variance` فرق كميات · `confirmed` معتمد/مؤكد · `rejected` مرفوض · `cancelled_by_branch` ملغي من الفرع · `cancelled_by_supplier` ملغي من المورد · `preparing` قيد التحضير · `on_the_way` في الطريق · `delivered` تم التسليم · `closed` مغلق.

**Group (derived) status:** `sent` أُرسل للمورد · `confirmed` مؤكد · `preparing` قيد التحضير · `on_the_way` في الطريق · `delivered` تم التسليم.

**Operations status:** `pending` طلبات جديدة · `approved` تم التجميع · `final-approved` أُرسل للمورد · `rejected` مرفوض · `partial_reject` مرفوض جزئياً.

**urgency:** `normal` عادي · `urgent` عاجل ⚡ · **orderType:** `direct_supplier` مورد مباشر · `via_purchasing_officer` عبر مسؤول المشتريات · `multiple_sources` مصادر متعددة.

**Item line status:** `pending` معلق · `confirmed` مؤكد · `rejected` مرفوض · `partial` جزئي.

---

## 6. Quirks the FE MUST honour

- **Money split**: bridge = SAR floats, Operations + catalog + suppliers = integer halalas. Never share a formatter across the two.
- Single-object responses have **no `{success}` wrapper** (object at JSON root); lists = `{data, meta?}`; errors = `{error:{code,message,messageAr}, requestId}`.
- `{id}` on bridge routes accepts UUID **or** `orderNumber`; Operations `{id}` accepts UUID or `publicId` (`PUR-0042`).
- `capacity: null` = supplier didn't declare stock → «—», not a warning. Supplier `rating`/`avgRating` in list rows is 0–50 (÷10 for stars).
- Group/sent status is **derived live** — poll to refresh chips.
- List `pageSize` hard cap 100; `orders/grouped` + `orders/sent` (Operations) are bounded to 100 with `meta.limit`.
- Two disjoint feeds land in Operations `orders`: dashboard `POST .../orders` **and** branch `POST company/me/branch/purchase-requests`. Neither enters the bridge pipeline — the real branch loop is the **bridge** `purchase-orders*`. Watch both queues if the UI merges them.
