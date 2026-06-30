# ASAB — Frontend Wiring Prompt (UI-Gap Batch)

> Paste this whole document to the frontend team. It is the **code-verified contract** for the
> 28 endpoints wired in this batch (the previously-static buttons/toggles/forms). Every path,
> body field, response shape, role, and realtime event below was implemented and verified against
> the shipped routes/controllers (`route:list` + migrations green). Wire against it exactly.

---

## 0. Global conventions (apply to every endpoint)

- **Base URL:** all paths are under `/api/v1` (e.g. `POST {API_ORIGIN}/api/v1/admin/users`).
- **Auth:** `Authorization: Bearer <accessToken>` (Laravel Sanctum). Every endpoint is **role-guarded** (zero-trust) — a wrong role returns `403 WRONG_ROLE`, no token returns `401 UNAUTHORIZED`, inactive account `403 USER_INACTIVE`.
- **Roles:** admin paths → `admin`. Company paths (`/company/me/*`, `/accountant/*`, `/reminders/*`) → the role noted per endpoint (`company-admin | head | accountant | branch | procurement`). Supplier paths (`/asab/supplier/*`) → `supplier`.
- **Idempotency:** on mutating `/admin/*` and `/company/*` routes send a unique `Idempotency-Key` header on POST/PUT/PATCH/DELETE so retries are safe.
- **Keys:** camelCase on the wire.
- **Money:** all money fields are **integer halalas** (SAR × 100). Spreadsheet **exports** render money as SAR decimal strings.
- **Timestamps:** ISO-8601 with offset (Asia/Riyadh) or `null`.
- **Success envelopes:** single resource → the **bare JSON object** (`200` read/update, `201` create); list → `{ "data": [...] }` (sometimes `+ meta`); paginated → `{ "data": [...], "meta": { page, pageSize, total, totalPages } }`; no content → `204`.
- **Error envelope:** `{ "error": { "code", "message", "messageAr?", "details?" }, "requestId" }`. Common: `VALIDATION_ERROR` (422), `NOT_FOUND` (404), `WRONG_ROLE` (403).
- **Field aliases:** where a field shows `a (or b)`, the backend accepts **either** name — prefer the first (the canonical one) in new code.
- **Realtime (Pusher + Echo):** subscribe to **private** channels; bind events by bare name. Broadcasts are best-effort — never block UI on them.

---

## SECTION 1 — Admin console (role: **admin**)

### 1.1 Create user
`POST /api/v1/admin/users`
- Body: `{ name, email, phone, role, brands:[string], restaurants:[string], branches:[string], modules:[string], reportsTo, scope:"all"|"brand"|"restaurant"|"branch", status:"active"|"inactive", companyId? }`
- `201` → the created user row (with server `id`).

### 1.2 Create brand
`POST /api/v1/admin/brands`
- Body: `{ name, owner (display name), ownerEmail?, plan:"silver"|"gold"|"platinum", companyId }`
- ⚠️ **`companyId` is required** (a brand belongs to a company) — send it even though earlier drafts omitted it.
- `201` → created brand.

### 1.3 Create restaurant
`POST /api/v1/admin/brands/{brandId}/restaurants` — Body `{ name, city }` → `201` restaurant.

### 1.4 Create branch
`POST /api/v1/admin/restaurants/{restaurantId}/branches` — Body `{ name, manager, city }` → `201` branch.

### 1.5 Edit / change plan
- `PATCH /api/v1/admin/restaurants/{id}` — Body `{ name?, city?, status? }` → `200`.
- `PATCH /api/v1/admin/branches/{id}` — Body `{ name?, manager?, managerUserId?, city?, address?, phone?, status? }` → `200`.
- `POST /api/v1/admin/subscriptions/{id}/change-plan` — Body `{ plan: "silver"|"gold"|"platinum" }` (Arabic `فضي|ذهبي|بلاتيني` also accepted) → `200` updated subscription.

### 1.6 Bulk uploads (multipart)
- `POST /api/v1/admin/brands/{brandId}/uploads/{type}` — `type ∈ sales-items|raw-materials|suppliers`. Field **`file`**. → `{ uploadId, rowsImported, uploadedCount, errors:[{ row, message }], status:"done" }`.
- `POST /api/v1/admin/restaurants/{restaurantId}/uploads/employees` — field **`file`** → same shape.
- `GET /api/v1/admin/uploads/templates/{type}` — returns **`.xlsx`** by default (`?format=csv` for CSV). `type ∈ sales-items|raw-materials|suppliers|employees|fixed-assets`.
- `GET /api/v1/admin/brands/{brandId}/upload-status` — poll: `{ uploads:[{ type, status, progressPct, parsedRows, failedRows, ... }], shared:{...}, completionPct }`.
- **Realtime:** `brand.upload.progress` on `operations.company.{companyId}` (two ticks: processing → done). (Singular `/upload/{type}` paths also still work.)

### 1.7 Report distribution
- `POST /api/v1/admin/reports/{reportKey}/send` — Body `{ period:{ from, to }, restaurantIds?:[string], channels:["email","inApp"] }`. Empty `restaurantIds` → all active restaurants in the tenant. → `{ reportKey, sentAt, recipients:[{ restaurantId, sent:true, sentDate }] }`.
  - ⚠️ Only **in-app** is physically delivered today; `email` is recorded in the distribution but not yet sent.
- `POST /api/v1/admin/reports/{reportKey}/upload` — multipart **`file`** (upload the report before sending) → `{ uploadId, reportKey, status:"stored" }`.

### 1.8 Accountant assignment
`PATCH /api/v1/admin/accountants/{accId}/assignments`
- Body: `{ headId?:string|null, restaurants:[string] }` — `restaurants` is reconciled to **exactly** this array (assigns new, unassigns removed); `headId` (when sent) reassigns the head.
- `200` → `{ id, headId, restaurants:[string] }`.
- Granular alternatives still available: `POST/DELETE /api/v1/admin/distribution/assign-restaurant`, `POST /api/v1/admin/distribution/move-to-head`.

### 1.9 Accountant per-restaurant modules
`PUT /api/v1/admin/accountants/{accId}/restaurants/{restaurant}/modules`
- Body: `{ modules:[string] }` → `200 { accountantId, restaurant, modules:[string] }`.
- ⚠️ Modules are currently stored on the accountant's role (apply to **all** their restaurants); true per-restaurant scoping needs a schema change — treat the matrix as per-accountant for now.

### 1.10 Clone role permissions
`POST /api/v1/admin/permissions/clone` — Body `{ fromRole, toRole }` → `200` full permission matrix.

---

## SECTION 2 — Accountant (role: **accountant**, paths under `/company/me`)

### 2.1 Create fixed asset
`POST /api/v1/company/me/assets` — Body `{ name, category, branchId, invNum, priceHalalas (or cost), usefulLifeMonths, custodian, notes? }` → `201` asset.

### 2.2 Import assets (Excel)
`POST /api/v1/company/me/assets/import` — multipart **`file`** (xlsx/csv) → `202 { jobId, parsedRows, createdRows, count, imported:[asset], errors:[] }`.

### 2.3 Convert expense → asset draft
`POST /api/v1/company/me/expense-invoices/{invoiceId}/convert-to-asset-draft` — Body `{ invNum, assetName, category, usefulLifeMonths, targetBranches:[string], custodian, qty, notes? }` → `201 { draftId, status:"draft" }`.

### 2.4 Edit / confirm asset
`PATCH /api/v1/company/me/assets/{id}` — Body `{ status?, bookValueHalalas? (or bookValue), branchId?, custodian?, note?, name?, category? }` → `200` asset.

### 2.5 Save sales reconciliation
`PATCH /api/v1/company/me/operations/{id}/reconciliation` (alias of `/sales-details`)
- Body: `{ cashHalalas (or cashAmount), bankHalalas (or bankAmount), deliveryApps:[{ name, amountHalalas }], varianceReason?, varianceAllocations? }`
- `200` → `{ id, reconciliation, totalCollectionHalalas, varianceHalalas }` (totals computed server-side).

### 2.6 Allocate variance to employees
`POST /api/v1/company/me/operations/{id}/variance-allocations` (alias of `/sales-variance/assign`)
- Body: `{ allocations:[{ employeeId (or empNumber), amountHalalas }], notes? }`
- `200` → `{ operationId, varianceTotalHalalas, allocations:[...], remainingVarianceHalalas }` (should be `0`).

### 2.7 Edit a sales line / add a note
- `PATCH /api/v1/company/me/operations/{id}/sales-lines/{rowId}` — Body `{ amountBeforeTaxHalalas, vatHalalas, amountAfterTaxHalalas }` → updated row (`404` if `rowId` not found).
- `POST /api/v1/company/me/operations/{id}/notes` — Body `{ note }` → `201 { id, note, createdAt }`.

### 2.8 Save daily inventory catalog (+ notify branch)
`PUT /api/v1/company/me/inventory/catalog` — Body `{ branchId, items:[{ name, category, unit }] }` → `{ branchId, items:[...], notifiedBranch:true }` (creates catalog items from full definitions, links to the branch, notifies it).

### 2.9 Flag / confirm inventory
- `POST /api/v1/company/me/inventory/branches/{branchId}/flag` — Body `{ flagged:boolean }` → `{ branchId, isFlagged }`.
- `POST /api/v1/company/me/inventory/branches/{branchId}/confirm` (alias of `/mark-confirmed`) — Body `{ confirmed?:boolean }` (default `true`) → `{ branchId, isConfirmed }`.
- `POST /api/v1/company/me/inventory/branches/{branchId}/flagged-items` (alias of `/flag-items`) — Body `{ itemIndexes:[number] (or itemIndices), note? }`.

### 2.10 Export inventory (binary)
`GET /api/v1/company/me/inventory/export?branchId=&format=xlsx` → binary file (Excel).

### 2.11 Reminder rules + broadcast (role: **accountant, head**)
- `POST /api/v1/accountant/reminder-rules` (alias of `/reminders/rules`) — Body `{ module, triggerHour, repeatHours, active }` → `201 { id, module }`.
- `PATCH /api/v1/accountant/reminder-rules/{id}` — Body `{ triggerHour?, repeatHours?, active? }` → `{ id, active }`.
- `POST /api/v1/reminders/broadcast` — Body `{ target:"all"|"<branchId>" (or audience + branchIds), message (or messageAr), messageEn?, module?, channels?:["in-app"|"email"|"whatsapp"|"sms"] }` → `{ ok:true, broadcastId, sentCount, failedCount, perChannel }`.
  - ⚠️ Only **in-app** is delivered today; `module` is accepted but not persisted.

---

## SECTION 3 — Head accountant (role: **head**)

### 3.1 ERP batch preview (filtered)
`GET /api/v1/company/me/erp/preview`
- Query: `moduleKey?`, `period[type]=today|week|month|custom`, `period[from]?`, `period[to]?`, `restaurantId?`, `branchId?`, `status?` (`pending|approved|rejected|final-approved`; default = final-approved & not yet ERP-posted).
- `200` → `{ data:[op], meta:{ count, totalAmountHalalas, branches } }`.
- The final post stays at `POST /api/v1/company/me/operations/{id}/post-to-erp`.

---

## SECTION 4 — Branch manager (role: **branch**, paths under `/company/me/branch`)

### 4.1 Daily upload to accountant (multipart)
`POST /api/v1/company/me/branch/upload`
- Body (flat): `{ reportType:"sales"|"inventory"|"cash"|"waste"|"purchases"|"expenses", date, salesHalalas?, shift?, expensesHalalas?, expenseNote? }` + file fields **`attachments[]`** (sales image/PDF, invoice).
- `200` → `{ operations:[...], uploadId, reportType, status:"success", createdOperationId, uploadedAt, attachments:[...] }`. Attachments are now stored and linked to the created operation.

### 4.2 Save branch settings
`PATCH /api/v1/company/me/branch/settings` (PUT also works)
- Body: `{ branchName, manager, phone, address, openTime, closeTime, shiftDuration, taxNumber, bankAccount (IBAN), cashLimitHalalas, wasteThreshold, autoReminders:boolean, requireImages:boolean }` → saved settings (all fields validated & persisted).

### 4.3 Add branch employee
`POST /api/v1/company/me/branch/employees` — Body `{ name, role, salaryHalalas, shift }` → `201` employee (`empNumber` auto-generated, branch derived from the manager's tenant).

### 4.4 New purchase request
`POST /api/v1/company/me/branch/purchase-requests` — Body `{ itemName (or item), qty, unit, priority:"normal"|"urgent" (or urgency), notes? }` → `{ id, publicId, status }` (notifies procurement).

### 4.5 Open / close shift
- `POST /api/v1/company/me/branch/shifts/open` — Body `{ cashierId (or cashierEmpNumber), registerOpeningHalalas (or openingCashHalalas) }` → shift. `409` if a shift is already open.
- `POST /api/v1/company/me/branch/shifts/{id}/close` — Body `{ cashInDrawerHalalas (or cashInDrawer), salesSystemHalalas (or salesSystem), notes? }` → `{ id, variance, varianceHalalas, createdAt, ... }`.

### 4.6 Save shift config
`PUT /api/v1/company/me/branch/shifts/config` — Body `{ brandId, numShifts, durationHours, firstStart, shifts:[{ start, end }], restaurantOverrides:{} }` → saved config.

---

## SECTION 5 — Procurement (role: **procurement**, paths under `/company/me/procurement`)

### 5.1 Create purchase order
`POST /api/v1/company/me/procurement/orders` — Body `{ supplierId, brandId, items:[{ itemId, qty }], deadline? (or deliveryDate), description? }` → `201 { id, publicId, status, totalHalalas }`.

### 5.2 Edit order
`PATCH /api/v1/company/me/procurement/orders/{id}` — Body `{ supplierId?, items?, status?, deadline? }` → updated order (`status` transitions validated against `pending|approved|rejected|final-approved`).

### 5.3 Approve / reject
- `POST /api/v1/company/me/procurement/orders/{id}/approve` → approved order.
- `POST /api/v1/company/me/procurement/orders/{id}/reject` — Body `{ reason, note? }`.
- `POST /api/v1/company/me/procurement/orders/{id}/partial-reject` — Body `{ reason, itemIds:[string] (or rejectedItemIds), note? }`.
- `POST /api/v1/company/me/procurement/orders/approve` (**bulk**) — Body `{ orderIds?:[string], branch?, supplier? }` → `{ approved:[ids], count }` (only `pending` orders are transitioned).

### 5.4 Send grouped order to supplier
`POST /api/v1/company/me/procurement/grouped/{groupId}/send` (also `/orders/grouped/{groupId}/send`) → sent.

### 5.5 Add supplier (role: **procurement, company-admin**)
`POST /api/v1/company/me/procurement/suppliers` (also `/company/me/suppliers`) — Body `{ name, category, contactName, phone (or contactPhone), email? (or contactEmail) }` → `201` supplier.

### 5.6 Add catalog item
`POST /api/v1/company/me/procurement/items` — Body `{ name, unit, category, defaultPriceHalalas (or lastPriceHalalas), supplierId?, code? }` → `201` item.

---

## SECTION 6 — Supplier portal (role: **supplier**, paths under `/asab/supplier`)

### 6.1 Accept / reject supply order
- `POST /api/v1/asab/supplier/orders/{id}/accept` — Body `{ deliveryDate? }` (now **optional**) → `{ id, publicId, total, status:"accepted" }`.
- `POST /api/v1/asab/supplier/orders/{id}/reject` — Body `{ reason }` → `{ id, status:"rejected" }`.

### 6.2 Add catalog item
`POST /api/v1/asab/supplier/items` — Body `{ name, unit, minQty, maxQty, priceHalalas (or price), available:boolean, leadTimeDays, code? }` → `201` item.

### 6.3 Edit item / availability
`PATCH /api/v1/asab/supplier/items/{id}` — Body `{ priceHalalas?, available?:boolean, minQty?, maxQty?, name?, unit?, leadTimeDays? }` → updated item.
- Quick toggle also available: `POST /api/v1/asab/supplier/items/{id}/toggle-active`.

---

## SECTION 7 — Company admin (role: **company-admin**)

### 7.1 Save company profile
`PATCH /api/v1/company/me/settings` (PUT also works) — Body `{ name, city, crNumber, email }` (also accepts `legalName, displayName, primaryCity, taxId, phone, website`) → saved settings.

---

## SECTION 8 — System settings (role: **admin**)

### 8.1 Read settings
`GET /api/v1/admin/settings` →
```json
{
  "notifications": { "approvalNotifications": true, "subscriptionAlerts": true, "dailyPerformanceReports": false },
  "backup":        { "dailyAutoBackup": true, "weeklyBackup": false, "dataEncryption": true },
  "api":           { "erpConnection": false, "paymentGatewayConnection": false, "mobileAppInterface": true },
  "security":      { "twoFactorAuthRequired": false, "sessionDurationMinutes": 480, "passwordPolicyEnabled": true }
}
```

### 8.2 Update settings
`PATCH /api/v1/admin/settings` — Body `Partial<AdminSettings>` (deep-merged per bucket, e.g. `{ "backup": { "weeklyBackup": true } }`) → the full object.
- `sessionDurationMinutes` is a number input (not a toggle). Booleans are validated per key.

---

## Notes for the FE
1. **Modals first.** Many of these were firing stub/empty bodies (`{name:"new"}`, `{items:[]}`) because there was no input modal. Build the real form/modal that collects the documented body, then call the endpoint.
2. **Multipart endpoints** (1.6, 1.7b, 2.2, 4.1) must be sent as `multipart/form-data`; show progress via the realtime events where listed and poll the status endpoint (1.6) otherwise.
3. **Aliases are for migration convenience** — pick the canonical (first-listed) field name in new code.
4. **Every call needs the role's bearer token**; a `403 WRONG_ROLE` means the logged-in user's role doesn't match the endpoint's required role.
