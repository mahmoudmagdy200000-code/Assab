# FE Wiring — T13 Supplier (المورد) — «بوابة المورد» (hidden v1)

> Backend module status: ✅ ready for integration · Delivered 2026-07-13
> Base URL: `/api/v1` · Auth: `Authorization: Bearer <token>` (Sanctum, guard `asab`, role `supplier`)
> Envelopes (`AsabResponse`): single = **bare object**; lists = `{ "data": [...] }`; paginated = `{ "data": [...], "meta": { page, pageSize, total, totalPages } }`.
> Errors: `{ "error": { "code", "message", "messageAr", "details" }, "requestId" }` + HTTP status — 401 unauthenticated, 403 wrong role, 404 not found / cross-supplier / cross-tenant, 409 state conflict, 422 validation.
> Money: **integer halalas** everywhere (`total`, `price`, `priceHalalas`, `totalRevenue`). ÷100, format `ar-SA`.

## ⚠️ Feature-flagged — hidden in v1

The **entire** supplier area sits behind `FEATURE_ASAB_SUPPLIER_PORTAL` (default **off**). When off, the whole `/api/v1/asab/supplier/*` group is **not registered** → every path returns **404, not 403**. FE must gate the whole supplier section on a single build/feature flag, not per-screen.

- Namespace is `/api/v1/asab/supplier/*`. Do **NOT** call `/api/v1/supplier/*` — that is the separate legacy mobile portal (different auth guard), untouched by this work.
- Ownership is implicit: everything is scoped to the logged-in supplier user (`asab_suppliers.user_id` for orders, `supplier_user_id` for items). **FE never sends a supplier id.**

## Screens covered

| Prototype screen | Endpoints |
|---|---|
| لوحة المورد (KPIs + آخر الطلبات) | `GET /asab/supplier/overview` |
| الطلبات الواردة (+ tabs: pending/accepted/delivered/rejected) | `GET /asab/supplier/orders?status=` |
| قبول / رفض / تم التسليم | `POST .../orders/{id}/accept` · `.../reject` · `.../mark-delivered` |
| تصدير الطلبات | `GET /asab/supplier/orders/export` |
| أصنافي (CRUD + toggle) | `GET/POST /asab/supplier/items` · `PATCH/DELETE .../items/{id}` · `POST .../items/{id}/toggle-active` |
| تصدير الأصناف | `GET /asab/supplier/items/export` |
| تقارير (deferred — SUP-3) | `GET /asab/supplier/reports` (aggregates only) |

---

## Shared shapes

**Order row** — returned by overview `recentOrders[]`, the orders list, and every accept/reject/deliver response:

```json
{
  "id": "019f…", "publicId": "PUR-A1B2C3", "total": 1000,
  "status": "accepted",
  "statusKey": "accepted", "statusLabel": "مقبول",
  "from": "فرع الملز",
  "itemsText": "طماطم ×5 كجم",
  "orderDate": "2026-07-13T09:00:00+03:00",
  "deliveryDate": "2026-08-01"
}
```

- `status` is the raw DB value; **render `statusKey` + `statusLabel`** (see enum below). `status` may hold a procurement synonym (`approved`/`confirmed`/`final-approved`) that all fold to `statusKey: "accepted"`.
- `from` = branch name for a branch-originated request, else «مدير المشتريات».
- `itemsText` = human summary of the order lines; for procurement orders that carry only item ids it falls back to «n صنف». May be `null`.
- `deliveryDate` = supplier-promised date (from accept), else the order's requested delivery date, else `null`.

**Item row** — returned by the items list and every item write:

```json
{
  "id": "019f…", "code": "T-9", "name": "طماطم", "unit": "كجم",
  "price": 750, "priceHalalas": 750,
  "minQty": 5, "maxQty": null, "available": true,
  "leadTimeDays": 2, "status": "active"
}
```

- `price` and `priceHalalas` are the **same** integer-halalas value. Send `priceHalalas` (or `price`); read either.
- `available` (boolean) mirrors `status` (`active نشط` / `inactive موقوف`).

---

## 1. Overview — `GET /asab/supplier/overview`

Bare object.

```json
{
  "kpis": {
    "newOrders": 1,
    "acceptedThisMonth": 1,
    "totalSalesThisMonth": 2000,
    "totalSalesTrendPct": 12.5,
    "activeItems": 1,
    "totalItems": 2
  },
  "recentOrders": [ /* order rows, newest first, max 8 */ ]
}
```

KPI card mapping (SUP-1.4): `newOrders` → «طلبات جديدة» · `acceptedThisMonth` → «مقبولة هذا الشهر» · `totalSalesThisMonth` (+`totalSalesTrendPct`) → «إجمالي مبيعاتي» · `activeItems`/`totalItems` → «أصناف نشطة n/m».

- `acceptedThisMonth` counts accepted-synonym orders whose acceptance landed in the current month **and year**.
- `totalSalesThisMonth` sums **fulfilled** orders only (accepted + delivered) for the current month/year — pending and rejected never inflate it.
- `totalSalesTrendPct` = this-month vs last-month change (%). `0` when no prior sales, `100` when growing from zero.

## 2. Orders list — `GET /asab/supplier/orders`

Paginated. Query: `?status=&page=1&pageSize=20` (`pageSize` capped at 100).

```json
{ "data": [ /* order rows */ ], "meta": { "page": 1, "pageSize": 20, "total": 3, "totalPages": 1 } }
```

- **Separate lists (SUP-1.3):** call with `?status=pending|accepted|delivered|rejected` — one tab per call. `?status=accepted` folds every accepted-synonym raw status, so procurement-`approved` rows appear under the accepted tab; the four lists are disjoint. Omit `status` for all orders.

## 3. Accept — `POST /asab/supplier/orders/{id}/accept`

Body (all optional): `{ "deliveryDate": "2026-08-01", "note": "..." }`. Returns the updated order row.

- Guard: only a `pending` order can be accepted → otherwise **409 `ORDER_NOT_PENDING`**.

## 4. Reject — `POST /asab/supplier/orders/{id}/reject`

Body: `{ "reason": "سعر مرتفع", "note"?: "..." }`.

| Field | Type | Required | Validation |
|---|---|---|---|
| `reason` | string | **yes** | ≤500 chars — FE must not submit without it (**422** otherwise) |
| `note` | string | no | free text |

- Guard: only a `pending` order can be rejected → **409 `ORDER_NOT_PENDING`**. Returns the updated order row.

## 5. Mark delivered — `POST /asab/supplier/orders/{id}/mark-delivered`

Body (all optional): `{ "deliveredAt": "2026-08-02T10:00:00+03:00", "deliveryNote": "..." }`. Returns the updated order row.

- Guard: only an **accepted** order can be delivered → **409 `ORDER_NOT_ACCEPTED`** (blocks pending/rejected → delivered).

## 6. Orders export — `GET /asab/supplier/orders/export`

`?status=accepted|rejected|pending|delivered&format=xlsx|csv`. Streams a binary file (Arabic headings: رقم الطلب / الفرع / الإجمالي (ر.س) / الحالة / التاريخ) — **no JSON envelope**. `status` uses the same canonical folding as the list.

## 7. Items list — `GET /asab/supplier/items`

`{ "data": [ /* item rows, sorted by name */ ] }`. Own catalog only.

## 8. Create item — `POST /asab/supplier/items` → `201`

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | yes | ≤200 |
| `priceHalalas` **or** `price` | integer ≥0 | yes (at least one) | integer halalas; omitting both → **422** |
| `code` | string | no | ≤32 |
| `unit` | string | no | ≤16 |
| `minQty` / `maxQty` | integer ≥0 | no | «أقل كمية للطلب» |
| `available` | boolean | no | default `true`; syncs `status` |
| `leadTimeDays` | integer ≥0 | no | |

## 9. Update item — `PATCH /asab/supplier/items/{id}`

Any subset of the create fields, **including `code`** (fix a typo without delete+recreate). Sending `available:false` sets `status:"inactive"`. Returns the updated item row.

## 10. Toggle active — `POST /asab/supplier/items/{id}/toggle-active`

No body. Flips `status` نشط↔موقوف and keeps `available` in sync. Returns the item row.

## 11. Delete item — `DELETE /asab/supplier/items/{id}` → `204`

Soft delete, own catalog only.

## 12. Items export — `GET /asab/supplier/items/export?format=xlsx|csv`

Binary file (headings: رمز الصنف / الاسم / الوحدة / السعر (ر.س) / أقل كمية / نشط).

## 13. Reports — `GET /asab/supplier/reports` (SUP-3 deferred)

```json
{ "totalRevenue": 3000, "orderCount": 1, "averageOrderValue": 3000 }
```

- Aggregates over **fulfilled** orders (accepted + delivered) only. SUP-3 breakdowns (topItems / topBranches / monthly) are **deferred** and intentionally **omitted** — do not render placeholders for them.

---

## Enums — order status (key ↔ Arabic label)

| `statusKey` | Arabic (`statusLabel`) | Raw `status` values that fold here |
|---|---|---|
| `pending` | في انتظار الرد | `pending` |
| `accepted` | مقبول | `accepted`, `confirmed`, `approved`, `final-approved` |
| `delivered` | تم التسليم | `delivered` |
| `rejected` | مرفوض | `rejected` |

- SRS calls the accepted state «confirmed»; the **backend key is `accepted`**. Filter/tab on `statusKey`, never on raw `status`.

## Test accounts / seed data

- Role `supplier`: `supplier@asab.sa` (seeded via `AsabDemoSeeder`). Link a supplier record with `asab_suppliers.user_id = <that user>`.
- Portal is **off by default** — set `FEATURE_ASAB_SUPPLIER_PORTAL=true` in the environment to exercise these endpoints (see `.env.example`).
