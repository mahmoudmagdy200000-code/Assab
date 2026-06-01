# ASAB (عصب) — Frontend Build & Integration Brief

**Send this file + the two spec files to the frontend developer.**

You are building the **complete frontend** for ASAB — a multi‑company restaurant
financial‑management SaaS. Arabic‑first (RTL), English secondary. The backend is
**built, live, and stable** (Laravel 12 · Sanctum auth · Pusher realtime). Build
two products against the contract below.

---

## 0. Source of truth (read first)

Two spec files define **every screen, field, query param and JSON payload shape**.
Build the UI and request/response bodies **exactly** to them:

| File | Product | Primary role(s) |
|------|---------|-----------------|
| `BACKEND_API_SPEC.md` | **Platform Admin** dashboard | `admin` (أمين النظام) |
| `COMPANY_DASHBOARD_API_SPEC.md` | **Company** dashboard (B2B portal) | company-admin, head, accountant, branch, procurement, supplier |

This brief is the **integration contract** those specs assume (auth, envelope,
realtime transport, file handling). **Rule:** for screen/payload *shapes* the spec
files win; for *transport details* (base URL, auth, envelope, realtime, headers)
**this brief wins**. The endpoint index in §11 is generated from the live router —
it is authoritative for *what exists*.

---

## 1. Base URL & versioning

- Everything is under **`/api/v1`**.
  - Production: `https://<api-host>/api/v1/...`
  - Local dev: `http://127.0.0.1:8000/api/v1/...`
- Always send: `Accept: application/json`
- Always send: `Accept-Language: ar` (or `en`) — selects localized error messages and labels.

---

## 2. Authentication (Laravel Sanctum, bearer tokens)

No cookies/sessions. Pure bearer tokens.

| Endpoint | Body | Returns |
|---|---|---|
| `POST /auth/login` | `{ email, password }` | `{ user, accessToken, refreshToken, companyId, defaultPage, ... }` (top level, **not** wrapped) |
| `POST /auth/refresh` | `{ refreshToken }` | new `{ accessToken, refreshToken }` (rotating) |
| `GET /auth/me` | — | current user **+ permission map** — use it to gate UI |
| `POST /auth/logout` | — | 204 |
| `POST /auth/change-password` | `{ currentPassword, newPassword }` | 204 |
| `GET /auth/sessions` · `DELETE /auth/sessions/{id}` | — | active sessions / revoke |
| `POST /auth/forgot-password` · `POST /auth/reset-password` | per spec §4 | OTP/token reset |
| `POST /company/invitations/accept` (public) | `{ token, name, password, phone? }` | issues tokens (auto‑login a newly invited teammate) |

**Client rules**
1. Store `accessToken`; send `Authorization: Bearer <accessToken>` on every authenticated request.
2. On `401`, attempt `POST /auth/refresh` **once**; if that fails, route to login.
3. After login, route the user to their `defaultPage` (the backend returns the correct landing page per role).

---

## 3. Response envelope (exact — do not assume Laravel defaults)

- **Single resource** (`GET` one, `POST`, `PATCH`): the **bare object**, no wrapper.
  ```json
  { "id": "0b1c...", "publicId": "OPS-1234", "amount": 125000, "status": "pending" }
  ```
- **List**:
  ```json
  { "data": [ ... ], "meta": { "page": 1, "pageSize": 20, "total": 137, "totalPages": 7 } }
  ```
- **Error** (any non‑2xx):
  ```json
  {
    "error": { "code": "QUOTA_EXCEEDED", "message": "Plan limit reached for branches",
               "messageAr": "تم بلوغ الحد الأقصى للخطة الحالية", "details": { "resource": "branches", "used": 5, "max": 5 } },
    "requestId": "req_3K9F2A7B1C4D"
  }
  ```
  Display `error.messageAr` (fallback `error.message`). **Branch logic on `error.code`**, never on HTTP status text. `details` is optional and code‑specific.
- `204 No Content` for deletes/empty success.

---

## 4. Conventions (apply everywhere)

- **All JSON keys are camelCase** (request and response).
- **Money = integer halalas.** 1 SAR = 100 halalas. Divide by 100 for display; send integers back. Money fields usually end in `Halalas`.
- **IDs are UUID strings.** `publicId` is the human reference: `OPS-####` (operations), `INV-YYYY-###` (invoices), `PO-…` (purchase orders), `FA-####` (fixed assets), `DRAFT-…` (asset drafts).
- **Timestamps**: ISO‑8601 UTC strings (`2026-06-01T08:30:00+00:00`). App timezone is Asia/Riyadh — convert for display.
- **Pagination**: `?page=1&pageSize=20` (pageSize capped at 100 server‑side). Read totals from `meta`.
- **Search/filter**: query params per the spec endpoint (e.g. `?search=`, `?status=`, `?brandId=`, `?branchId=`, `?dateFrom=&dateTo=`).
- **Idempotency** (write safety): for `POST`/`PATCH` that create money/state, you *may* send `Idempotency-Key: <uuid>`; replaying the same key returns the first result. Recommended for "Pay", "Approve", "Send to ERP".
- **Booleans/enums/Arabic enums**: send exactly the values the spec lists (some status enums are Arabic strings, e.g. `"نشط"`/`"موقوف"`).

---

## 5. Roles, gating & routing

Seven roles. The backend enforces role + multi‑tenant isolation on every route — the
frontend mirrors it for UX only (never trust the client for security).

| Role | Arabic | Product | Base path |
|---|---|---|---|
| `admin` | أمين النظام | Platform Admin | `/api/v1/admin/*` (cross‑company) |
| `company-admin` | مدير الشركة | Company | `/api/v1/company/me/*` |
| `head` | رئيس الحسابات | Company | `/api/v1/company/me/head/*` + shared ops |
| `accountant` | المحاسب | Company | `/api/v1/company/me/*` (accountant surfaces) |
| `branch` | مدير الفرع | Company | `/api/v1/company/me/branch/*` |
| `procurement` | مدير المشتريات | Company | `/api/v1/company/me/procurement/*` |
| `supplier` | المورد | Company | `/api/v1/asab/supplier/*` |

- A user belongs to **one company** (`companyId` in the login response); `admin` is cross‑company.
- Multi‑tenancy is automatic server‑side: a company user only ever sees its own company's data. **Never send `companyId`/`branchId` to "scope" a request** — it's derived from the token. Send IDs only to *target* a specific child you own (validated server‑side; cross‑tenant IDs return `404`).
- Use the permission map from `GET /auth/me` to show/hide actions.

---

## 6. The pipeline model (core domain concept)

Most financial records are **Operations** flowing through a 4‑state approval pipeline:

```
pending  →  approved  →  final-approved  →  (erpPosted flag)
        ↘  rejected
```

- `moduleKey` ∈ `sales | expenses | purchases | inventory | shifts | employees | cash | waste`.
- Branch submits → Accountant reviews/approves → Head final‑approves → Head posts an **ERP batch**.
- Endpoints: `GET /operations`, `GET /operations/{id}` (+ `/audit-trail`), `POST /operations/{id}/approve|reject|final-approve|conditional-approve|correction`, `POST /operations/bulk-approve`. Reject **requires a reason** (422 if missing). Wrong‑state transitions return `409` (`OP_NOT_PENDING` / `OP_ALREADY_FINAL`).

---

## 7. File uploads & exports

### Uploads (attachments)
- `POST /uploads/presigned-url` → presign flow, or `POST /uploads/direct` (multipart `file`).
- `POST /uploads/{attachmentId}/confirm`, `GET /attachments/{id}`, `GET /attachments/{id}/download`, `POST /attachments/{id}/verify`, `DELETE /attachments/{id}`.
- Bulk imports (admin): `POST /admin/brands/{brandId}/upload/{type}`, `.../restaurants/{id}/upload/employees`, `.../branches/{id}/upload/fixed-assets`; templates at `GET /admin/upload/templates/{type}`; status at `GET /admin/brands/{brandId}/upload-status`.
- Company asset import: `POST /company/me/assets/import` (multipart `file`, xlsx/csv) → `202 { jobId, parsedRows, createdRows }` (parsed + created synchronously).

### Exports — two patterns, know which is which
- **Synchronous binary (`200`)** — the response **is** the file. Set `responseType: 'blob'` and trigger a download. These endpoints (xlsx by default, `?format=csv` where noted, PDF where noted):
  - `GET /company/me/operations/{id}/export` (xlsx)
  - `GET /company/me/waste/export`
  - `GET /company/me/shifts/export`
  - `GET /company/me/employees/payroll/export?month=YYYY-MM`
  - `GET /company/me/cash-custody/export`
  - `GET /company/me/billing/invoices/{id}/pdf` → `application/pdf`
  - `GET /company/me/reports/{key}/download?format=pdf|xlsx` (and `.../procurement/reports/{key}/download`)
  - ERP batch files: `GET /erp/batches/{batchId}/download.json|.csv|.xlsx`
- **Async job (`202 { jobId }`)** — large export, generated in the background:
  - `GET /company/me/billing/invoices/export?format=xlsx|csv` → `202 { jobId, status:"queued" }`.
  - When ready, the user receives a realtime `export.ready` event (see §8) with a download link, **or** poll/download via `GET /company/me/exports/{jobId}/download` (returns `404` until ready, then the binary).

---

## 8. Real‑time (WebSocket via Pusher + Laravel Echo)

Realtime uses **Pusher channels**, consumed with **Laravel Echo + pusher-js**. Use
**private** channels (auth‑gated). The backend already implements channel
authorization and broadcasts all events below.

### 8.1 Client setup
```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
window.Pusher = Pusher;

const echo = new Echo({
  broadcaster: 'pusher',
  key: import.meta.env.VITE_PUSHER_APP_KEY,        // from backend team (production)
  cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER, // e.g. "mt1"
  forceTLS: true,
  authEndpoint: `${API_BASE}/broadcasting/auth`,    // NOTE: at the app root, not under /api/v1
  auth: { headers: { Authorization: `Bearer ${accessToken}`, Accept: 'application/json' } },
});
```
> `VITE_PUSHER_APP_KEY` and `VITE_PUSHER_APP_CLUSTER` come from the backend/production
> config — ask the backend owner (do **not** hardcode). The auth endpoint is
> `POST /broadcasting/auth` and requires the bearer token.

### 8.2 Channels to subscribe (per logged‑in user)

Subscribe with `echo.private('<channel>')`. pusher‑js automatically prepends the
`private-` transport prefix — use the names exactly as written:

| Channel | Subscribe when | What arrives |
|---|---|---|
| `notifications.user.{userId}` | always (every role) | personal notifications + user‑targeted events |
| `operations.company.{companyId}` | always (company users) | company‑wide operation/subscription/branch/module events |
| `operations.brand.{brandId}` | for each brand in the user's scope (head/accountant) | brand‑level operation, asset‑draft, shift events |
| `reminders.branch.{branchId}` | branch managers (their branch) | branch reminders, inventory flags, asset confirmations, shifts |

### 8.3 Events (custom names → use a leading dot in Echo: `.listen('.event.name', cb)`)

| Event name | Channel | Payload (camelCase) |
|---|---|---|
| `notification.new` | `notifications.user.{id}` | `{ id, type, title, body, link, refType, refId, readAt, createdAt }` |
| `operation.created` | `operations.company.{id}` | `{ operationId, id, moduleKey, branchId, amount, match, origin, status, operationDate }` |
| `operation.status_changed` | `operations.company.{id}` **and** `operations.brand.{id}` | `{ operationId, from, to, by:{ id, name } }` |
| `subscription.updated` | `operations.company.{id}` | `{ id, companyId, planId, status, billingCycle, currentPeriodEnd }` |
| `subscription.suspended` | `operations.company.{id}` | same shape as above |
| `subscription.expiring` | `notifications.user.{adminId}` | `{ subscriptionId, daysRemaining }` |
| `module.changed` | `operations.company.{id}` | `{ moduleKey, isActive }` |
| `branch.created` / `branch.updated` | `operations.company.{id}` | `{ branchId, name }` |
| `invoice.created` / `invoice.paid` / `invoice.payment_failed` | `notifications.user.{adminId}` | `{ id, publicId, companyId, totalHalalas, amountDueHalalas, status }` |
| `quota.warning` / `quota.exceeded` | `notifications.user.{adminId}` | `{ resource, used, max }` |
| `user.invited` | `notifications.user.{adminId}` | `{ id, email, roleKey, status }` |
| `user.joined` / `user.role_changed` / `user.suspended` | `notifications.user.{adminId}` | `{ id, userId, name, email, roleKey, status }` |
| `support.ticket_replied` | `notifications.user.{openerId}` | `{ id, ticketId, body, authorType, createdAt }` |
| `erp.batch.completed` | `notifications.user.{headId}` | `{ batchId, status, companyId }` |
| `asset_draft.created` | `operations.brand.{id}` | `{ id, draftId, name, status }` |
| `asset_draft.confirmed` | `reminders.branch.{id}` | `{ id, draftId, status }` |
| `asset.confirmation_needed` | `reminders.branch.{id}` | `{ assetId, branchId }` |
| `inventory.flag_sent` | `reminders.branch.{id}` | `{ branchId, items: [...] }` |
| `shift.opened` / `shift.closed` | `reminders.branch.{id}` + `operations.brand.{id}` | `{ id, branchId, status, supervisor, startedAt, endedAt }` |
| `reminder.responded` | `reminders.branch.{id}` | `{ reminderId, response }` |
| `approval.pending` | `operations.company.{id}` | `{ accountantId, count }` |

Example:
```js
echo.private(`notifications.user.${userId}`)
    .listen('.notification.new', (e) => toastAndIncrementBell(e))
    .listen('.invoice.paid',     (e) => refreshBilling(e));
```
> Realtime is **best‑effort UI sugar** — always also refetch on user navigation. Never depend on an event for correctness.

---

## 9. Webhooks (server‑side only — ignore on FE)

`POST /webhooks/{provider}` (`stripe|tap|hyperpay|moyasar`) is the payment gateway →
backend channel. The frontend never calls it. Payment results reach the UI via the
`invoice.paid` / `invoice.payment_failed` realtime events and a billing refetch.

---

## 10. Error codes to handle in UI

Common `error.code` values (show `messageAr`, act on code):

`VALIDATION_ERROR` (422, `details` = field→messages) · `NOT_FOUND` (404) ·
`QUOTA_EXCEEDED` (409) · `QUOTA_WOULD_EXCEED` (422, downgrade) · `OP_NOT_PENDING` /
`OP_ALREADY_FINAL` (409) · `ALREADY_ON_PLAN` · `INVALID_TRANSITION` ·
`SUBSCRIPTION_EXPIRED` · `LAST_ADMIN_CANNOT_DEMOTE` / `LAST_ACTIVE_ADMIN` (409) ·
`USER_ALREADY_MEMBER` (409) · `INVALID_INVITATION` / `INVITATION_EXPIRED` (422) ·
`SHIFT_ALREADY_OPEN` (409) · `BRANCH_HAS_OPEN_OPERATIONS` (409) ·
`INVALID_SIGNATURE` / `INVALID_WEBHOOK` (webhooks) · `INVALID_TOKEN` /
`PROVIDER_UNAVAILABLE` (payments).

Map any unknown `4xx` to a generic toast using `messageAr`.

---

## 11. Endpoint index (authoritative — generated from the live router)

> Full request/response payloads for each are in the two spec files. This index is the
> complete list of what the backend exposes. Prefixes shown are the real resolved paths.

### Auth & public
```
POST   /auth/login · /auth/refresh · /auth/forgot-password · /auth/reset-password
GET    /auth/me        POST /auth/logout · /auth/change-password
GET    /auth/sessions  DELETE /auth/sessions/{id}
POST   /company/invitations/accept        (public, token)
POST   /webhooks/{provider}               (server-side; stripe|tap|hyperpay|moyasar)
```

### Platform Admin — `/api/v1/admin/*` (role `admin`)
```
GET    /admin/overview
        companies:  GET /admin/companies · POST · GET/PATCH/DELETE /admin/companies/{id}
                    POST /admin/companies/{id}/suspend|activate|upgrade
                    GET/PATCH /admin/companies/{id}/modules · GET /admin/companies/{id}/usage
        brands:     GET/POST /admin/brands · PATCH/DELETE /admin/brands/{id}
                    POST /admin/brands/{brandId}/restaurants
        restaurants:PATCH/DELETE /admin/restaurants/{id}
                    GET /admin/restaurants/subscriptions
        branches:   GET /admin/branches · POST /admin/restaurants/{restaurantId}/branches
                    PATCH/DELETE /admin/branches/{id}
        users:      GET/POST /admin/users · POST /admin/users/import
                    PATCH/DELETE /admin/users/{id} · POST /admin/users/{id}/activate|deactivate
        distribution: GET /admin/distribution · POST /admin/distribution/assign-restaurant
                    DELETE /admin/distribution/assign-restaurant
                    POST /admin/distribution/assign-modules · /move-to-head
        subscriptions: GET /admin/subscriptions
                    POST /admin/subscriptions/{id}/renew|change-plan|toggle-auto-reminder|suspend|activate
        permissions: GET/PUT /admin/permissions · PATCH /admin/permissions/cell · POST /admin/permissions/clone
        audit:      GET /admin/audit-logs
        settings:   GET/PATCH /admin/settings
        reports:    GET /admin/reports/catalog · POST /admin/reports/generate
        uploads:    POST /admin/brands/{brandId}/upload/{type} · /restaurants/{id}/upload/employees
                    /branches/{id}/upload/fixed-assets · GET /admin/upload/templates/{type}
                    GET /admin/brands/{brandId}/upload-status
```

### Shared (authenticated; role‑gated per route — used by head/accountant/etc.)
```
operations: GET /operations · /operations/{id} · /operations/{id}/audit-trail
            POST /operations/bulk-approve · /operations/{id}/approve|reject|final-approve
                 |conditional-approve|correction
erp:        POST /erp/batches · GET /erp/batches/{batchId}/status
            GET  /erp/batches/{batchId}/download.json|.csv|.xlsx
misc:       GET /pipeline/overview · /modules/aggregation · /exceptions · /search
lookups:    GET /lookups/brands|restaurants|branches|suppliers|items|users|employees|modules|exceptions
notifications: GET /notifications · POST /notifications/{id}/read · /notifications/read-all
uploads/attachments: POST /uploads/presigned-url · /uploads/direct · /uploads/{attachmentId}/confirm
            GET /attachments/{id} · /attachments/{id}/download · POST /attachments/{id}/verify · DELETE /attachments/{id}
reports:    POST /reports/profit-loss|sales-summary|expense-summary|inventory-valuation|payroll
                 |waste-analysis|supplier-performance|menu-engineering|breakeven|cash-flow
```

### Platform Head — `/api/v1/head/*` (role `head`)
```
GET /head/dashboard · /head/accountants/performance · /head/reminders
PATCH /head/reminders/{id} · POST /head/reminders/mark-all-done
GET /operations/pending · /operations/final-approved · /operations/rejected
GET /erp/preflight · /erp/eligible-operations · /erp/batches
GET /reports/internal · /reports/owner
```

### Platform Accountant — `/api/v1/accountant/*` (role `accountant`,`head`)
```
GET   /accountant/dashboard · /accountant/operations
PATCH /accountant/operations/{id}/reconciliation
POST  /accountant/expense-invoices/{invoiceId}/convert-to-asset
assets: GET/POST /accountant/assets · POST /accountant/assets/{id}/confirm
        GET /accountant/asset-drafts · POST /accountant/asset-drafts/{draftId}/confirm · DELETE …
inventory: GET /accountant/inventory · /accountant/inventory/catalog · POST …/catalog
        POST /accountant/inventory/branches/{branchId}/flag|items/flag|send-confirmation
        GET/PUT /accountant/inventory/branches/{branchId}/daily-list
waste:  GET /accountant/waste · PATCH …/{entryId}/products/{productIdx}
        PUT …/products/{productIdx}/allocations · POST …/{entryId}/approve|reject · /waste/bulk-approve
shifts: GET /accountant/shifts/live|history · POST /accountant/shifts/{id}/close
employees: GET /accountant/employees · /accountant/employees/{id}/statement · POST …/{id}/movements
cash:   GET /accountant/cash-custody · POST …/{id}/settlement-request · …/{id}/transactions
reminders: GET /reminders · POST /reminders/{id}/send · /reminders/bulk-send · /reminders/{id}/respond
        GET/POST /reminders/rules · PATCH/DELETE /reminders/rules/{id} · POST /reminders/rules/{id}/toggle
```

### Platform Branch — `/api/v1/branch/*` (role `branch`)
```
GET /branch/overview · /branch/upload/status · POST /branch/upload/{reportType}
GET /branch/employees · POST /branch/employees · GET /branch/inventory-items · /branch/suppliers
GET/PATCH /branch/settings · POST /branch/assets/{id}/confirm · /branch/inventory/{id}/reconfirm
```

### Platform Procurement — `/api/v1/procurement/*` (role `procurement`)
```
GET /procurement/overview · /procurement/orders · /procurement/orders/{id}
POST /procurement/orders/{id}/approve|reject|partial-reject · /procurement/orders/consolidate
POST /procurement/orders/{groupId}/send · GET /procurement/suppliers · /procurement/items
```

### Supplier — `/api/v1/asab/supplier/*` (role `supplier`)
```
GET /asab/supplier/overview · /asab/supplier/orders · /asab/supplier/reports
POST /asab/supplier/orders/{id}/accept|reject|mark-delivered
GET/POST /asab/supplier/items · PATCH/DELETE /asab/supplier/items/{id} · POST …/{id}/toggle-active
```

### Company Dashboard — `/api/v1/company/*`
**Onboarding / plans (public‑ish):** `GET /company/plans` · `POST /company/onboard`
**Invitations (company-admin):** `GET /company/invitations` · `POST /company/invitations` · `POST /company/invitations/{id}/revoke`

**company-admin — `/api/v1/company/me/*`**
```
dashboard:    GET /company/me/dashboard
subscription: GET /company/me/subscription
              POST /company/me/subscription/upgrade|downgrade|cancel|reactivate|contact-sales|billing-cycle
users:        GET /company/me/users · POST /company/me/users
              PATCH /company/me/users/{id} · POST …/{id}/toggle-status|resend-invite · DELETE …/{id}
org:          GET/POST /company/me/brands · PATCH/DELETE /company/me/brands/{id}
              POST /company/me/restaurants · PATCH/DELETE /company/me/restaurants/{id}
              POST /company/me/branches · PATCH/DELETE /company/me/branches/{id} · POST …/{id}/transfer-manager
modules:      GET /company/me/modules · PATCH /company/me/modules/{moduleKey}
billing:      GET /company/me/billing/summary · /billing/invoices · /billing/invoices/{id}
              GET /billing/invoices/{id}/pdf (PDF) · GET /billing/invoices/export (202 job)
              POST /billing/invoices/{id}/pay
              GET/POST /billing/payment-methods · POST …/{id}/set-default · DELETE …/{id}
              GET/PUT /company/me/billing/address
              GET /company/me/exports/{jobId}/download  (async export result)
settings:     GET/PUT /company/me/settings · POST /company/me/settings/logo · PATCH /company/me/preferences
```

**support (any company role) — `/api/v1/company/me/support/*`**
```
GET /support/channels · /support/tickets · /support/tickets/{id}
POST /support/tickets · …/{id}/reply · …/{id}/close · …/{id}/attachments
```

**head — `/api/v1/company/me/head/*` (+ shared ops)**
```
GET /company/me/head/dashboard · /head/accountants/performance · /head/reminders
PATCH /head/reminders/{id} · POST /head/reminders/mark-all-done
POST /company/me/operations/{id}/post-to-erp
```

**accountant — `/api/v1/company/me/*`**
```
GET /company/me/accountant/dashboard · /accountant/reminders · PATCH /accountant/reminders/{id}
operations: GET /company/me/operations · POST /operations/bulk-approve · /operations/{id}/approve
            PATCH /operations/{id}/sales-details · POST /operations/{id}/sales-variance/assign
            GET  /operations/{id}/export (xlsx)
expenses:   POST /company/me/expense-invoices/{invoiceId}/verify · DELETE …/verify
            GET …/{invoiceId}/attachments · POST …/convert-to-asset-draft
assets:     GET/POST /company/me/assets · POST /assets/import (202) · PATCH /assets/{id}
            POST /company/me/asset-drafts/{draftId}/confirm|discard
inventory:  GET /company/me/inventory/branches · /inventory/items
            POST /inventory/branches/{branchId}/flag|flag-items|send-notification|mark-confirmed
            GET/PUT /company/me/branches/{branchId}/inventory-list
waste:      GET /company/me/waste · /waste/export · POST /waste/bulk-approve
            PATCH /waste/{id}/products/{idx} · PUT …/allocations · POST …/{id}/approve|reject
shifts:     GET /company/me/shifts · /shifts/configs · /shifts/export
            POST /shifts/{id}/close · PUT /company/me/brands/{brandId}/shift-config
employees:  GET /company/me/employees · /employees/{id}/movements · /employees/payroll/export
cash:       GET /company/me/cash-custody · /cash-custody/export · /cash-custody/{id}/transactions
            POST /cash-custody/{id}/settle · …/{id}/transactions/{txnId}/approve|reject
lookups:    GET /company/me/branches/{branchId}/employees/lookup
```

**branch — `/api/v1/company/me/branch/*`**
```
GET /company/me/branch/overview · /branch/employees · /branch/items · /branch/purchase-requests
    /branch/suppliers · /branch/settings · /branch/shifts/active
POST /company/me/branch/items/count · /branch/upload · /branch/upload/sign-attachment
     /branch/suppliers/request-new · /branch/shifts/open · /branch/shifts/{id}/close
```

**procurement — `/api/v1/company/me/procurement/*`**
```
GET /company/me/procurement/overview · /procurement/orders · /procurement/orders/grouped · /orders/sent
PATCH /procurement/orders/{id} · POST /orders/{id}/approve|reject · /orders/grouped/{groupId}/send
GET /company/me/procurement/items · /items/{id}/price-history · PATCH /items/{id}
GET /company/me/suppliers · PATCH /suppliers/{id} · POST /suppliers/{id}/ratings|toggle-active
GET /company/me/procurement/reports · /procurement/reports/{key}/download
```

**cross‑cutting (any company role) — `/api/v1/company/me/*`**
```
GET    /company/me/lookups/{brands|restaurants|branches|users|cities|asset-categories
                            |inventory-categories|units|expense-categories|supplier-categories}
GET    /company/me/audit-logs        (company-admin full, head read-only)
GET    /company/me/search?q=&type=ops,branches,users,suppliers,items
GET    /company/me/notifications · PATCH …/{id}/read · POST …/mark-all-read · DELETE …/{id}
GET    /company/me/reports · /company/me/reports/{key}/download?format=pdf|xlsx
PATCH  /company/me/preferences        { language?: "ar"|"en", theme?: "light"|"dark" }
```

---

## 12. Environment / config the frontend needs

| Var | From | Notes |
|---|---|---|
| `VITE_API_BASE_URL` | you | e.g. `https://<api-host>` (Echo auth uses `${BASE}/broadcasting/auth`; API calls use `${BASE}/api/v1`) |
| `VITE_PUSHER_APP_KEY` | backend owner | production Pusher key |
| `VITE_PUSHER_APP_CLUSTER` | backend owner | e.g. `mt1` |

---

## 13. Deliverable expectations

1. **Both products**: Platform Admin (admin role) and the Company Dashboard (6 roles), routed by `defaultPage` from login.
2. RTL Arabic UI (English toggle persisted via `PATCH /company/me/preferences`).
3. Token lifecycle: store, attach bearer, refresh‑once‑on‑401, logout.
4. Global API client implementing the envelope in §3 (unwrap single vs `{data,meta}`; surface `error.messageAr`/`code`).
5. Money helpers (halalas↔SAR). camelCase throughout.
6. Realtime wired per §8 (notifications bell, live pipeline/billing/quota/shift updates).
7. File handling per §7 (blob downloads for sync exports; poll/notify for async; multipart for imports/uploads).
8. Role/permission gating from `GET /auth/me`.

Build screen‑by‑screen against `BACKEND_API_SPEC.md` (admin) and
`COMPANY_DASHBOARD_API_SPEC.md` (company). This file is the contract glue; those are
the screen/payload bible.
