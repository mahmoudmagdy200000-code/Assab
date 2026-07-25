# Frontend Handoff — Dashboard Fixes & WhatsApp Send-to-Supplier

> **For:** Frontend team, to wire the endpoints changed in the 2026-07-23 session.
> **Scope:** the 6 confirmed dashboard bugs that were fixed (BUG-1/2/3/4/7/8) + the new WhatsApp Send-to-Supplier (FR-PUR-1).
> **All changes are additive** — existing fields are untouched, only new fields were added. Nothing below breaks a current integration.

---

## 0. Conventions (read first)

### Auth & headers
```
Authorization: Bearer <sanctum-token>
Accept-Language: ar        # or en — controls Arabic/English messages
Accept: application/json
```

### Response envelopes
Three shapes are used. Know which one an endpoint returns:

| Helper | Shape | Used by (below) |
|---|---|---|
| **object** (`ok`/`created`) | payload at the **root** (no wrapper) | distribution, shift-config save, send-to-supplier |
| **list** (`listResponse`) | `{ "data": [ … ] }` | brands, branches, shift-configs |
| **paginated** | `{ "data": [ … ], "meta": { page, pageSize, total, totalPages } }` | users |

### Error shape (all endpoints)
```json
{ "error": { "code": "NOT_FOUND", "message": "…", "messageAr": "…", "details": {} } }
```
HTTP status carries the category (400/404/409/422). `code` is the stable machine key; show `messageAr`/`message` to the user.

Legend below: **⭐ = new field added this session.** Everything else already existed.

---

## 1. BUG-1 — Brand modules count

**`GET /api/v1/admin/brands`** · optional `?companyId=<uuid>` · returns **list**.

The Modules widget was reading a value that came back `0`. Use **`moduleCount`**. Modules are a fixed catalog of 9 (`sales, expenses, purchases, inventory, waste, assets, shifts, employees, cash`); a brand with an empty `modules` array is granted the full set, so `moduleCount` is `9` in that case.

```json
{
  "data": [
    {
      "id": "b-uuid",
      "companyId": "c-uuid",
      "name": "Bazooka",
      "abbr": "BZ",
      "color": "#ff5500",
      "owner": "Ahmed",
      "ownerEmail": "owner@bazooka.com",
      "ownerUserId": "u-uuid",
      "plan": "gold",
      "subStatus": "active",
      "daysLeft": 210,
      "modules": ["sales", "expenses"],
      "moduleCount": 2,                        // ⭐ use this for the widget
      "status": "active",
      "restaurants": [                          // present on the list (withChildren)
        {
          "id": "r-uuid",
          "name": "Bazooka - Riyadh",
          "city": "الرياض",
          "status": "active",
          "accountants": 2,                    // ⭐ BUG-2, see §2
          "branches": [
            { "id": "br-uuid", "name": "Main Branch", "manager": "Sara" }
          ]
        }
      ]
    }
  ]
}
```
> A brand with `"modules": []` returns `"moduleCount": 9`.
> The same object (without `restaurants`) is returned by `POST /admin/brands`, `PATCH /admin/brands/{id}`.

---

## 2. BUG-2 — Accountant / restaurant counts (no more "zero")

Accountants are assigned at **brand** level, so the old per-restaurant column stayed `0`. Counts are now **derived** from the brand-scoped assignments.

**a) In the brand tree** (`GET /api/v1/admin/brands`, shown in §1): each `restaurants[].accountants` is the real count.

**b) Restaurant object** (returned by `POST /api/v1/admin/brands/{brandId}/restaurants`, `PATCH /api/v1/admin/restaurants/{id}`):
```json
{
  "id": "r-uuid",
  "brandId": "b-uuid",
  "companyId": "c-uuid",
  "name": "Bazooka - Riyadh",
  "city": "الرياض",
  "accountantCount": 2,                        // ⭐ derived, no longer stale 0
  "status": "active"
}
```

---

## 3. BUG-3 — Users list shows names, not raw IDs

**`GET /api/v1/admin/users`** · returns **paginated**.
Query: `page`, `pageSize` (≤100), `search`, `status`, `companyId`, `role` (or `roleFilter`), `brand`.

The raw id arrays (`brands`/`restaurants`/`branches`) are **kept unchanged**. New parallel `*Named` arrays carry `{id, name}` for display. `reportsTo` is now `{id, name}` too.

```json
{
  "data": [
    {
      "id": "u-uuid",
      "name": "محاسب 1",
      "email": "acc@bazooka.com",
      "phone": null,
      "role": "محاسب",
      "roleKey": "accountant",
      "brands": ["b-uuid"],                                        // unchanged (ids)
      "restaurants": [],
      "branches": [],
      "brandsNamed": [{ "id": "b-uuid", "name": "Bazooka" }],      // ⭐ render these
      "restaurantsNamed": [],                                      // ⭐
      "branchesNamed": [],                                         // ⭐
      "modules": ["sales", "expenses"],
      "scope": "brand",
      "reportsTo": { "id": "h-uuid", "name": "Ahmed Awad" },       // ⭐ {id,name} (was bare uuid)
      "status": "active",
      "lastLoginAt": "2026-07-20T09:12:00+00:00",
      "createdAt": "2026-07-01T10:00:00+00:00"
    }
  ],
  "meta": { "page": 1, "pageSize": 20, "total": 5, "totalPages": 1 }
}
```
> Same object shape (no `meta`) is returned by `POST /admin/users`, `PATCH /admin/users/{id}`, activate/deactivate.

---

## 4. BUG-4 — Distribution summary (heads ↔ accountants ↔ restaurants, by name)

**`GET /api/v1/admin/distribution`** · returns **object** (root).

Accountant coverage is resolved **through their brands** (was empty → the "zero restaurants" bug). New: `restaurantsNamed` per accountant and a top-level `restaurantNames` id→name map so any id in the payload resolves to a name.

```json
{
  "heads": [
    { "id": "h-uuid", "name": "Ahmed Awad", "avatar": "A", "accountantCount": 3 }
  ],
  "accountants": [
    {
      "id": "u-uuid",
      "name": "محاسب 1",
      "avatar": "م",
      "headId": "h-uuid",
      "restaurants": ["r-uuid", "r2-uuid"],                       // ids (unchanged)
      "restaurantsNamed": [                                        // ⭐ render these
        { "id": "r-uuid", "name": "Bazooka - Riyadh" },
        { "id": "r2-uuid", "name": "Bazooka - Jeddah" }
      ]
    }
  ],
  "allRestaurants": ["r-uuid", "r2-uuid", "r3-uuid"],
  "restaurantNames": {                                            // ⭐ id → name map
    "r-uuid": "Bazooka - Riyadh",
    "r2-uuid": "Bazooka - Jeddah",
    "r3-uuid": "Other - Dammam"
  },
  "assignedRestaurants": ["r-uuid", "r2-uuid"],                   // now reflects real coverage
  "freeRestaurants": ["r3-uuid"],
  "accModules": { "u-uuid": { "Bazooka - Riyadh": ["sales","expenses"] } }
}
```

---

## 5. BUG-7 — Shift settings load the correct (assigned) brand

**`GET /api/v1/company/me/shifts/configs`** · returns **list**.
The list is now **scoped to the accountant's assigned brand(s)** — no more placeholder/wrong brand. (Admin / company-wide roles still see all company brands.)

```json
{
  "data": [
    {
      "brandId": "b-uuid",
      "brandName": "Bazooka",
      "numShifts": 4,
      "durationHours": 8,
      "firstShiftStart": "08:00",
      "openingFloatHalalas": 50000,
      "shifts": [
        { "index": 1, "window": "08:00-16:00" },
        { "index": 2, "window": "16:00-00:00" }
      ],
      "morningWindow": "08:00-16:00",
      "eveningWindow": "16:00-00:00"
    }
  ]
}
```

**`PUT /api/v1/company/me/brands/{brandId}/shift-config`** — save. Body (meeting model):
```json
{ "numShifts": 4, "durationHours": 8, "firstShiftStart": "08:00", "openingFloatHalalas": 50000 }
```
Legacy body still accepted: `{ "morningWindow": "08:00-16:00", "eveningWindow": "16:00-00:00", "openingFloatHalalas": 50000 }`.
Returns the same object as one row above. **Zero-trust:** a scoped accountant configuring a brand outside their assignment gets **404** (`NOT_FOUND`).

---

## 6. BUG-8 — Cashier/employee branch picker must be scoped

**No backend change** — the endpoint already supports the filter. **The FE fix is to pass it.**

**`GET /api/v1/admin/branches?brandId=<uuid>&restaurantId=<uuid>`** · returns **list**.
When creating a Cashier/employee under a brand → restaurant, the branch dropdown MUST call this with `brandId` (and `restaurantId` when a restaurant is chosen). Without the filter it lists **every branch system-wide** (the reported bug).

```json
{
  "data": [
    {
      "id": "br-uuid",
      "restaurantId": "r-uuid",
      "brandId": "b-uuid",
      "companyId": "c-uuid",
      "name": "Main Branch",
      "manager": "Sara",
      "managerUserId": "u-uuid",
      "address": "…",
      "city": "الرياض",
      "phone": "0112223333",
      "status": "active"
    }
  ]
}
```

---

## 7. NEW — WhatsApp Send-to-Supplier (FR-PUR-1)

**`POST /api/v1/procurement/purchase-orders/grouped/send`** · returns **object** (root).
_(Also available at `/api/v1/company/me/procurement/purchase-orders/grouped/send` — same behavior.)_

Body:
```json
{
  "supplierId": "s-uuid",
  "orderIds": ["po-uuid", "po2-uuid"],        // optional; omit = all consolidatable for the supplier
  "expectedDeliveryDate": "2026-07-30"        // optional
}
```

Response now includes a **`whatsapp`** block. Open `whatsapp.url` (e.g. `window.open(url)`) to launch WhatsApp with the order pre-filled — the officer taps send in their own WhatsApp.

```json
{
  "groupId": "g-uuid",
  "groupNumber": "GRP-20260723-AB12",
  "supplierId": "s-uuid",
  "ordersCount": 2,
  "savings": 120.50,
  "savingsPct": 4.2,
  "eta": "2026-07-30",
  "sentAt": "2026-07-23T18:40:00+00:00",
  "whatsapp": {                                          // ⭐ NEW
    "channel": "whatsapp",
    "reference": "GRP-20260723-AB12",                    // the order number shown to the supplier
    "supplierName": "شركة الدواجن الوطنية",
    "phone": "966553421100",                             // normalized E.164 (no +)
    "message": "طلب شراء رقم: GRP-20260723-AB12\nالمورد: …\n━━━━━━━━━━\n1. صدر دجاج × 320 كجم\n━━━━━━━━━━\nموعد التسليم المتوقع: 2026-07-30",
    "url": "https://wa.me/966553421100?text=%D8%B7%D9%84%D8%A8...",
    "deliverable": true                                  // false when the supplier has no phone
  }
}
```

**FE handling:**
- `whatsapp.deliverable === true` → show the "Send via WhatsApp" button, open `whatsapp.url`.
- `whatsapp.deliverable === false` (no phone / `url` is `null`) → disable the button, prompt to add the supplier's phone. The order is still consolidated/sent regardless.
- The order/reference number to display is `groupNumber` (= `whatsapp.reference`).

Phone normalization handled server-side (Saudi default): `0553421100`, `+966 55 342 1100`, `00966…`, and bare `553421100` all become `966553421100`.

---

## 8. Not done yet (still open)

| Bug | Why blocked |
|---|---|
| **BUG-5** — permission/scope edits revert after refresh | Needs a reproduction: which screen, which value reverts. Assignment edits (`PATCH /admin/accountants/{id}/assignments`, `PATCH /admin/users/{id}`) persist in tests — need the exact failing flow. |
| **BUG-6** — uploaded data (e.g. employees) not saved | Needs to confirm the upload write path + `asab_upload_status` durability. |
| **BUG-9** — mobile Items/Expenses pickers return empty | Consumer side of the brand-level upload; depends on BUG-6 + the employees-level decision (restaurant vs branch). |

These three are **persistence/repro-dependent** and were intentionally not guessed. See `docs/tasks/dashboard-mobile-linking-FRD.md` §14 for the open questions that unblock them.
