# Procurement Manager Dashboard — API Integration Guide (مدير المشتريات)

> **Audience:** frontend team wiring the purchasing-manager dashboard.
> **Branch:** `asab-admin-backend` · **Last verified against code:** 2026-07-08
> All shapes below were extracted literally from the controllers/services — field names are exact.

---

## 0. TL;DR — which endpoint family do I use?

There are **two order pipelines**. Do not mix them:

| Family | Base path segment | Data source | Use for |
|---|---|---|---|
| **Purchase-orders bridge** ⭐ | `.../procurement/purchase-orders*` | Real branch orders from the **mobile app** (`purchase_orders`) | **الطلبات الجديدة، الطلبات المجمعة، المرسلة للموردين، لوحة التحكم lists** — this is the main flow |
| Operations pipeline | `.../procurement/orders*` | Dashboard-created manual purchase requests (`asab_operations`) | Manual orders created from the dashboard itself (`POST orders`), plus `overview` KPIs |

**⭐ = wire the UI screens to the bridge family.** Decisions made there hit the same database rows the mobile app reads, so the branch and the supplier see the result instantly.

---

## 1. Conventions

### 1.1 Base URLs & surfaces

Every endpoint is mounted twice with identical behavior:

- **Company surface (recommended):** `/api/v1/company/me/procurement/...`
  Middleware: `auth:sanctum` → `asab.tenant` → `asab.idempotency` → `asab.audit` → `asab.role:procurement`
- **Platform surface:** `/api/v1/procurement/...`
  Middleware: `auth:sanctum` → `asab.tenant` → `asab.role:procurement` (no idempotency/audit)

Use the **company surface** — it supports the `Idempotency-Key` header on mutations.

### 1.2 Headers

| Header | Value | Notes |
|---|---|---|
| `Authorization` | `Bearer <accessToken>` | required everywhere |
| `Accept-Language` | `ar` or `en` | switches `messageAr`/locale; default `en` |
| `Idempotency-Key` | any UUID | optional, mutations on `/company/me/*` only; same key within 24h replays the stored response instead of re-running |
| `Content-Type` | `application/json` | |

### 1.3 Auth flow

```
POST /api/v1/auth/login          { email, password }             → 200 { accessToken, refreshToken, expiresIn: 900, user }
POST /api/v1/auth/login          (2FA users, step 1)             → 200 { requires2fa: true, twoFactorToken }
POST /api/v1/auth/login          { twoFactorToken, code }        → 200 { accessToken, refreshToken, expiresIn, user }
POST /api/v1/auth/refresh        { refreshToken }                → 200 { accessToken, refreshToken, expiresIn }   // refresh token ROTATES — store both
GET  /api/v1/auth/me                                             → 200 user + permissions map
POST /api/v1/auth/logout                                         → 204
```

- `user.roles[]` contains `{ key: "procurement", scope, brandIds, restaurantIds, branchIds, moduleKeys }` — check `key === "procurement"` to route to this dashboard.
- `expiresIn` (900s) is advisory — refresh proactively; refresh tokens are single-use.
- A missing/invalid bearer token returns Laravel's default `401 {"message": "Unauthenticated."}` (NOT the error envelope below) — treat any 401 as logged-out.

Auth error codes: `INVALID_CREDENTIALS` 401 · `USER_INACTIVE` 403 · `TWO_FACTOR_INVALID_CODE` 422 · `TWO_FACTOR_TOKEN_INVALID` 401 · `INVALID_TOKEN` 401 (refresh).

### 1.4 Response envelopes

| Kind | Shape |
|---|---|
| Single object (200/201) | the object **at the JSON root** — no `success`/`data` wrapper |
| Paginated list (200) | `{ "data": [...], "meta": { "page", "pageSize", "total", "totalPages" } }` |
| Plain list (200) | `{ "data": [...] }` |
| No content (204) | `null` body |
| **Error** | `{ "error": { "code", "message", "messageAr"?, "details"? }, "requestId": "req_..." }` |

Common error codes: `VALIDATION_ERROR` 422 (field messages in `error.details`) · `NOT_FOUND` 404 · `UNAUTHORIZED` 401 · `WRONG_ROLE` 403 · `USER_INACTIVE` 403 · `WRONG_TENANT` 403.

### 1.5 Money & pagination

- **Bridge family** (`purchase-orders*`): amounts are **floats in SAR** (`totalAmount`, `unitPrice`, `totalPrice`).
- **Operations family** (`orders*`) and items/suppliers CRUD: amounts are **integer halalas** (`total`, `totalHalalas`, `lastPriceHalalas`, `priceHalalas`). Divide by 100 for SAR.
- Pagination params everywhere: `page` (default 1), `pageSize` (default 20, hard cap 100).

### 1.6 Tenant isolation (automatic)

Every query is scoped to the logged-in manager's company (branch tree). Orders from another company return **404**, never leak. Internal branch transfers never appear on this surface.

---

## 2. Screen-by-screen wiring

### 2.1 لوحة التحكم (Dashboard)

| UI element | Endpoint |
|---|---|
| KPI cards (طلبات جديدة / مجمعة / أرسلت / قيمة الطلبات) | `GET .../procurement/overview` → `{ kpis: { newOrders, consolidated, sentToSuppliers, ordersValueThisWeek /* halalas */ }, newOrders: [...] }` ⚠️ counts the Operations pipeline only |
| KPI "طلبات جديدة من الفروع" (real branch orders) | `GET .../procurement/purchase-orders?status=incoming&pageSize=1` → use `meta.total` |
| قائمة الطلبات الجديدة من الفروع | `GET .../procurement/purchase-orders?status=incoming&pageSize=5` |
| زر اعتماد | `POST .../purchase-orders/{id}/approve` |
| زر تجميع الطلبات | navigate to الطلبات المجمعة screen (§2.3) |

### 2.2 الطلبات الجديدة (New orders — branch requests)

All on the **bridge** family.

**List:**
```
GET .../procurement/purchase-orders?status=incoming&branchId=&priority=high&page=1&pageSize=20
```
- `status`: `incoming` (default — means `pending + emergency + variance`, i.e. awaiting decision) or one exact value of: `pending, emergency, variance, confirmed, rejected, cancelled, cancelled_by_branch, cancelled_by_supplier, preparing, on_the_way, delivered, closed`. (`draft` is never visible.)
- `priority`: `high` (= عاجل) | `normal` (= عادي).
- Sorted `submittedAt` DESC. Group by branch/supplier client-side (`branchName`, `supplierId`).

**Order object (list rows and decision responses):**
```json
{
  "id": "uuid",
  "orderNumber": "PO-20261014-AB12",
  "branchId": "uuid",
  "branchName": "فرع الرياض - العليا",
  "orderType": "direct_supplier | via_purchasing_officer | multiple_sources",
  "status": "pending",
  "statusLabel": "Pending",
  "priority": "high | normal | null",
  "supplierId": "uuid | null",
  "totalAmount": 4800.0,
  "totalItems": 4,
  "rejectionReason": null,
  "submittedAt": "2026-10-14T09:30:00+03:00",
  "decidedAt": null
}
```
⚠️ `branchName` is `null` in the responses of approve/partial-approve/reject (relation not loaded there) — keep it from the list row.

**Details + بيانات الاستهلاك (expand before approving):**
```
GET .../procurement/purchase-orders/{id}        // {id} = uuid OR orderNumber
```
Response = order object above **plus**:
```json
"items": [{
  "orderItemId": "uuid",
  "itemId": "uuid | null",
  "name": "دجاج طازج",
  "unit": "kg",
  "quantityOrdered": 50.0,
  "quantityConfirmed": null,
  "unitPrice": 32.0,
  "totalPrice": 1600.0,
  "status": "pending",
  "dailyConsumption": 7.0,      // استهلاك يومي — null if branch didn't report
  "remainingBalance": 12.0,     // المخزون المتبقي
  "weekendForecast": 14.0,
  "nextSupplyDate": "2026-10-16"
}]
```
"موصى به (7 أيام)" = `dailyConsumption * 7` (client-side). "آخر سعر" → `GET .../procurement/items/{id}/price-history` (per catalog item, halalas).

**Actions:**

| زر | Request | Success | Errors |
|---|---|---|---|
| اعتماد / اعتماد بعد المراجعة | `POST .../purchase-orders/{id}/approve` (no body) | 200 order object, `status: "confirmed"` | 409 `ORDER_NOT_DECIDABLE`, 404 |
| رفض جزئي | `POST .../purchase-orders/{id}/partial-approve` body `{ "items": [{ "orderItemId": "...", "quantity": 4 }, { "orderItemId": "...", "quantity": 0 }], "note": "سعر أعلى من المتفق عليه" }` — quantity `0` rejects that line; **all zeros = full rejection**; quantities capped at ordered | 200 order object | 409 `ORDER_NOT_DECIDABLE` (also fired for foreign `orderItemId`s), 422 |
| رفض كلي | `POST .../purchase-orders/{id}/reject` body `{ "reason": "..." }` (required, max 500) | 200 order object, `status: "rejected"` | 409, 422 |
| اعتماد الكل | `POST .../purchase-orders/bulk-approve` body `{ "orderIds": ["...", "..."] }` | 200 `{ "approved": [ids], "failed": [{ "id", "reason" }], "count": 2 }` — per-order failures are collected, never 409 | 422 |

**"كم اعتمادها هذه الجلسة"** — keep a client-side counter, or refetch `GET .../purchase-orders/approved-by-me` (paginated, orders decided by the current manager, `decided_at` DESC).

### 2.3 الطلبات المجمعة (Aggregated orders)

Live preview over **confirmed + not-yet-sent** orders. Nothing is persisted until "إرسال".

**بالمورد (default):**
```
GET .../procurement/purchase-orders/grouped
```
```json
{
  "suppliers": [{
    "supplierId": "uuid",
    "supplierName": "شركة الدواجن الوطنية",
    "ordersCount": 12,
    "branchesCount": 3,
    "cities": ["الرياض", "جدة"],
    "urgentCount": 2,
    "totalAmount": 28400.0,
    "itemsCount": 3,
    "capacityExceeded": true,
    "orderIds": ["uuid", "..."],
    "items": [{
      "itemId": "uuid | null",
      "name": "صدر دجاج",
      "unit": "kg",
      "totalQuantity": 320.0,
      "unitPrice": 45.0,
      "capacity": 300.0,        // الطاقة القصوى = supplier's declared stock; null = لم يُعلن (اعرض «—»)
      "capacityPct": 107,       // null when capacity is null
      "exceeded": true,         // null when capacity is null
      "excessQuantity": 20.0    // null unless exceeded
    }]
  }],
  "unassignedOrders": [{ "id": "uuid", "orderNumber": "...", "branchName": "...", "totalAmount": 0.0 }]
}
```
- `capacity` comes from the supplier's own declared stock (`supplier_products.stock_quantity`) — the supplier maintains it from their portal. `null` means "no data", **not** "exceeded".
- `capacityExceeded: true` on the supplier card → show تحذير تجاوز الطاقة banner.
- `unassignedOrders` = confirmed officer orders with no supplier yet — offer a supplier picker; they can be included in a send (below), which assigns the supplier.

**بالمدينة:**
```
GET .../procurement/purchase-orders/grouped?by=city
```
```json
{ "cities": [{ "city": "الرياض", "ordersCount": 3, "urgentCount": 0, "totalAmount": 8900.0,
               "suppliers": ["شركة الدواجن الوطنية", "مطاحن الملك"], "orderIds": ["..."] }] }
```
(`city: "غير محدد"` when the branch has no city.)

**إرسال للمورد:**
```
POST .../procurement/purchase-orders/grouped/send
Body: { "supplierId": "uuid", "orderIds": ["..."]? }   // omit orderIds = send ALL of that supplier's consolidatable orders
```
- 201 `{ "groupId": "uuid", "groupNumber": "GRP-20261014-AB12", "supplierId": "uuid", "ordersCount": 12, "sentAt": "ISO-8601" }`
- 409 `CONSOLIDATION_FAILED` (nothing to send / an order belongs to another supplier / ids not visible) · 404 unknown supplier · 422.
- After a successful send the orders disappear from the preview and appear in §2.4.

### 2.4 المرسلة للموردين (Sent to suppliers)

```
GET .../procurement/purchase-orders/sent
```
```json
{ "data": [{
  "groupId": "uuid",
  "groupNumber": "GRP-20261014-AB12",
  "supplierId": "uuid",
  "supplierName": "شركة الدواجن الوطنية",
  "ordersCount": 12,
  "totalAmount": 28400.0,
  "status": "confirmed | preparing | on_the_way | delivered",
  "sentAt": "ISO-8601"
}] }
```
Status→label: `confirmed` = مؤكد · `preparing` = قيد التحضير · `on_the_way` = في الطريق · `delivered` = تم التسليم. The status is **derived live from the member orders** (updates automatically as the supplier moves orders in the mobile app). Newest first, max 100.

**زر تتبع / تفاصيل:**
```
GET .../procurement/purchase-orders/groups/{groupId}
```
→ group header + `items` (same aggregated-item shape as §2.3) + `orders: [{ id, orderNumber, branchId, branchName, city, status, statusLabel, totalAmount }]`.

### 2.5 الأصناف (Items catalog)

| UI | Endpoint |
|---|---|
| إضافة صنف | `POST .../procurement/items` body `{ name*, unit*, lastPriceHalalas?, category?, supplierId?, code? }` → 201 `{ id, name, unit, category, supplierId, lastPriceHalalas }` |
| تعديل | `PATCH .../procurement/items/{id}` `{ name?, unit?, lastPriceHalalas?, status? }` — a price change auto-appends to price history |
| حذف | `DELETE .../procurement/items/{id}` → 204 |
| تاريخ الأسعار | `GET .../procurement/items/{id}/price-history` → `{ data: [{ supplierId, supplierName, priceHalalas, recordedAt }] }` |
| Excel | `GET .../procurement/items/export?format=xlsx|csv` → binary download (prices in SAR) |
| التصنيفات dropdown | `GET /api/v1/company/me/lookups/supplier-categories` → static list (food/beverages/packaging/equipment/services with Arabic names) |

⚠️ **Known gap:** `GET .../procurement/items` (the list) currently returns only `{ id, name }` from a *different* legacy table — items you create via POST will **not** appear in it, and the columns الاستهلاك الشهري / المخزون الحالي / آخر طلب have no backend source yet. Until the enrichment lands, build the table from your own created items + price-history, or hide those columns. (Backend follow-up is tracked.)

### 2.6 الموردون (Suppliers)

| UI | Endpoint |
|---|---|
| إضافة مورد | `POST /api/v1/company/me/suppliers` body `{ name*, category?, contactName?, contactPhone?, contactEmail?, commercialReg?, paymentTerms?, brandId? }` → 201 |
| تعديل | `PATCH /api/v1/company/me/suppliers/{id}` |
| إخفاء / تفعيل | `POST /api/v1/company/me/suppliers/{id}/toggle-active` → 200 `{ id, isActive, status }` |
| تقييم (نجوم) | `POST /api/v1/company/me/suppliers/{id}/ratings` body `{ rating: 1..5, comment? }` → 201 `{ supplierId, ratingAvg }` — **ratingAvg is 0–50 (= stars × 10)**, divide by 10 for stars |
| Excel | `GET /api/v1/company/me/suppliers/export?format=xlsx|csv` |

⚠️ **Known gaps:** `GET /api/v1/company/me/suppliers` (the read list) currently reads a legacy table (returns `{ id, name, category }` only) — newly created suppliers won't show there yet; the cards' الالتزام % / الشهري / سجل التسليمات have no backend fields yet. مقارنة الأسعار is available per item via price-history (§2.5). (Backend follow-ups are tracked.)

### 2.7 التقارير (Reports)

**Deferred by product decision (client meeting).** If you need placeholders: `GET .../procurement/reports` (catalog of 7 report keys with `labelAr`) and `GET .../procurement/reports/{key}/download?format=json|pdf|xlsx&from=&to=&branchIds[]=`.

### 2.8 الإشعارات (bell icon)

```
GET    /api/v1/company/me/notifications?page=&pageSize=&unreadOnly=1&type=
PATCH  /api/v1/company/me/notifications/{id}/read        → 204
POST   /api/v1/company/me/notifications/mark-all-read    → 204
DELETE /api/v1/company/me/notifications/{id}             → 204
```
List meta includes `unreadCount` (total unread, unaffected by filters). Item: `{ id, type, title, body, link, refType, refId, readAt, createdAt }`.

---

## 3. Secondary: Operations pipeline (dashboard-created manual orders)

Only needed if the UI keeps a "create manual purchase request" flow. Amounts in **halalas**; status vocabulary: `pending → approved → final-approved`, plus `rejected`, `partial_reject`.

```
GET    .../procurement/orders?status=&page=&pageSize=          // paginated; row: { id, publicId, branchId, total, status, diffNote, urgency: "عاجل"|"عادي", operationDate }
GET    /api/v1/procurement/orders/{id}                         // platform surface ONLY; + raw "payload"
POST   .../procurement/orders                                  // company surface; { supplierId*, items*[], branchId?, description?, urgency?: normal|urgent, deliveryDate? } → 201
PATCH  .../procurement/orders/{id}   ·  DELETE → 204
POST   .../procurement/orders/{id}/approve                     // 409 OP_NOT_PENDING if not pending
POST   .../procurement/orders/{id}/reject                      // { reason* } ; 409 OP_ALREADY_FINAL
POST   .../procurement/orders/{id}/partial-reject              // { reason*, rejectedItemIds[] }
POST   .../procurement/orders/approve                          // bulk (company surface): { orderIds?|branch?|supplier? } — atomic batch
POST   /api/v1/procurement/orders/consolidate                  // { orderIds*, supplierId* } → 201 { consolidatedGroupId, orderCount }
POST   .../procurement/grouped/{groupId}/send                  // → { groupId, sent }
GET    .../procurement/orders/grouped   ·   GET .../procurement/orders/sent
```

---

## 4. Enum → Arabic label reference

| Field | Value | Suggested label |
|---|---|---|
| `status` (bridge) | `pending` / `emergency` / `variance` | معلق / طارئ / فرق كميات |
| | `confirmed` | معتمد / مؤكد |
| | `rejected` | مرفوض |
| | `cancelled_by_branch` / `cancelled_by_supplier` | ملغي من الفرع / ملغي من المورد |
| | `preparing` / `on_the_way` / `delivered` / `closed` | قيد التحضير / في الطريق / تم التسليم / مغلق |
| `priority` | `high` / `normal` | عاجل / عادي |
| `orderType` | `direct_supplier` / `via_purchasing_officer` / `multiple_sources` | مورد مباشر / عبر مسؤول المشتريات / مصادر متعددة |
| group `status` | `confirmed` / `preparing` / `on_the_way` / `delivered` | مؤكد / قيد التحضير / في الطريق / تم التسليم |
| item `status` | `pending` / `confirmed` / `rejected` / `partial` | معلق / مؤكد / مرفوض / جزئي |

---

## 5. Error-code quick reference

| HTTP | `error.code` | When | UI action |
|---|---|---|---|
| 401 | `UNAUTHORIZED` or `{"message":"Unauthenticated."}` | token missing/expired | redirect to login (try refresh first) |
| 403 | `WRONG_ROLE` / `USER_INACTIVE` / `WRONG_TENANT` | wrong role / disabled account / no company | show blocked screen |
| 404 | `NOT_FOUND` | unknown id, or order belongs to another company | remove row / show غير موجود |
| 409 | `ORDER_NOT_DECIDABLE` | order already decided or lines under negotiation | refetch the order and refresh row state |
| 409 | `CONSOLIDATION_FAILED` | send with no eligible orders / supplier mismatch | refetch grouped preview |
| 409 | `OP_NOT_PENDING` / `OP_ALREADY_FINAL` | Operations pipeline decisions | refetch |
| 422 | `VALIDATION_ERROR` | bad input — field messages in `error.details` | show inline field errors |
| 429 | `RATE_LIMITED` | forgot-password resend | show cooldown (`details.nextResendAvailableAt`) |

---

## 6. Gotchas checklist for the frontend

1. Single-object responses have **no wrapper** — read keys at the JSON root. Lists use `{ data, meta? }`.
2. **Bridge = SAR floats; Operations/items/suppliers = integer halalas.** Don't mix formatters.
3. `branchName` is `null` in decision responses — reuse it from the list row.
4. `{id}` on bridge endpoints accepts the UUID **or** the `orderNumber`.
5. `capacity: null` = supplier didn't declare stock → render «—», never a warning.
6. Supplier `ratingAvg` is 0–50; divide by 10 for stars.
7. Group/`sent` status is **derived live** — poll or refetch to update tracking chips.
8. Send `Idempotency-Key` (UUID) on every mutation on `/company/me/*` to make retries safe.
9. `pageSize` is capped at 100 server-side.
10. Items/Suppliers **read lists** are legacy-backed (see §2.5/§2.6 gaps) — don't expect your created rows there until the backend follow-up lands.

---

## 7. Endpoint index (copy-paste)

```
# Bridge (branch app orders) — prefix with /api/v1/company/me  (or /api/v1)
GET    /procurement/purchase-orders
GET    /procurement/purchase-orders/{id}
GET    /procurement/purchase-orders/approved-by-me
POST   /procurement/purchase-orders/bulk-approve
POST   /procurement/purchase-orders/{id}/approve
POST   /procurement/purchase-orders/{id}/partial-approve
POST   /procurement/purchase-orders/{id}/reject
GET    /procurement/purchase-orders/grouped            ?by=supplier|city
POST   /procurement/purchase-orders/grouped/send
GET    /procurement/purchase-orders/sent
GET    /procurement/purchase-orders/groups/{groupId}

# KPIs
GET    /procurement/overview

# Items
GET    /procurement/items                 # thin (id,name) — see gap §2.5
POST   /procurement/items
PATCH  /procurement/items/{id}
DELETE /procurement/items/{id}
GET    /procurement/items/{id}/price-history
GET    /procurement/items/export          ?format=xlsx|csv

# Suppliers (note: /company/me/suppliers, not under /procurement)
GET    /suppliers                         # thin — see gap §2.6
POST   /suppliers
PATCH  /suppliers/{id}
POST   /suppliers/{id}/toggle-active
POST   /suppliers/{id}/ratings
GET    /suppliers/export                  ?format=xlsx|csv
GET    /lookups/supplier-categories

# Notifications
GET    /notifications
PATCH  /notifications/{id}/read
POST   /notifications/mark-all-read
DELETE /notifications/{id}

# Auth (prefix /api/v1)
POST   /auth/login        POST /auth/refresh       GET /auth/me
POST   /auth/logout       POST /auth/change-password
POST   /auth/forgot-password   POST /auth/forgot-password/resend   POST /auth/reset-password
```
