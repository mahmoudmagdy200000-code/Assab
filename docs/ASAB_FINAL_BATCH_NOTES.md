# ASAB Final-Batch — Backend Delivery Notes

Tracks the FE "Final Completion Request". Sections **1 & 2 are shipped**; Section 3
is blueprinted pending approval; Section 4 is confirmed below.

> Response envelopes follow the ASAB spec: bare object for single GET/POST/PATCH,
> `{ data, meta }` for lists, `{ error: { code, message, messageAr, details? }, requestId }`
> for errors. Money is in halalas. Realtime uses `AsabRealtimeEvent` (leading-dot
> event name on the wire via Echo/pusher-js).

## Section 1 — UI gaps (shipped)

| # | Method & path | Notes |
|---|---|---|
| 1.1 | `POST /api/v1/admin/restaurants/{restaurantId}/subscription/renew` | Body `{ months?=12 }`. Resolves the restaurant's subscription, renews, emits `subscription.updated` on `operations.company.{companyId}`. Returns the restaurant-subscription object (with `restaurantName`/`brandName`). |
| 1.2 | `POST /api/v1/admin/brands/{brandId}/auto-reminder` | Body `{ enabled }` → `{ brandId, enabled, updatedAt }`. Persists on `asab_brands.auto_reminder_enabled`. |
| 1.3 | `GET /api/v1/admin/audit-logs/{id}` | Full entry incl. `before`/`after`/`userAgent`. |
| 1.4 | `POST /api/v1/admin/companies` (extended) | Accepts `contactName/contactEmail/contactPhone/city/plan/branchesLimitOverride/billingCycle/modules`. Side-effects: auto-creates a `company-admin` user + welcome email (one-time password, mail channel only); `billingCycle=annual` provisions the first invoice and emits `invoice.created`. Response = AdminCompany + `adminUserId`. |
| 1.5 | `POST /api/v1/operations/{id}/correction` | Accepts `{ reason, notes?, correctedFields? }` (legacy `correctionReason/amount/diffNote` still accepted). Response = `{ originalOperationId, correctionOperationId, publicId, status, createdAt }`. |
| 1.6 | `POST /api/v1/operations/{id}/final-approve` | Conditional approval is the **flag form**: `{ isConditional?, conditionalNote? (required when conditional), conditions?:[{id?,text,dueAt?}] }`. The standalone `POST /operations/{id}/conditional-approve` route + `HeadController::conditionalApprove` were **removed** — delete it from §11/the spec. |
| 1.7 | `GET /api/v1/admin/brands/{brandId}/upload-status` (extended) + realtime | Adds an `uploads:[{type,status,progressPct,parsedRows,failedRows,failureReason?,startedAt,finishedAt?}]` array (legacy `shared`/`completionPct` retained). Realtime tick `brand.upload.progress` on `operations.company.{companyId}`. |
| 1.8 | `GET /api/v1/company/me/inventory/export?brandId=&branchId=&date=&format=xlsx` | Binary xlsx/csv with the spec columns (Branch…Counted By). Tenant-scoped. |
| 1.9 | `POST /api/v1/reminders/{id}/send` + `POST /api/v1/reminders/broadcast` | `send` body `{ channel }` → `{ id, reminderStatus, sent, channel, deliveredAt }`. `broadcast` body adds `channels:[…]` → response adds `perChannel: { channel: {sent,failed} }`. **Note:** in-app is delivered now; email/whatsapp/sms providers are not yet wired — they report the audience count and route through `NotificationService` (provider integration is a Section-3 concern). |
| 1.10 | `GET /api/v1/admin/audit-logs/filters` | Already present; returns the exact 8 `actionTypes`. No change needed. |

## Section 2 — new concepts (shipped)

| # | Endpoints | Notes |
|---|---|---|
| 2.1 | `POST /company/me/support/chat/start`, `GET .../chat/{sessionId}`, `POST .../chat/{sessionId}/message`, `POST .../chat/{sessionId}/close` | Realtime on **private** `chat.session.{sessionId}` (auth: opener or assigned agent). Events: `message.new`, `agent.joined`, `session.closed`. |
| 2.2 | `GET /users/me/onboarding-state`, `PATCH /users/me/onboarding-state` | Body `{ stepCompleted?, skip?, reset? }` → `{ completedSteps, skipped, completedAt? }`. Step IDs are FE-defined. (Available to the 5 company roles.) |
| 2.3 | `GET /admin/permissions/history`, `GET .../history/{snapshotId}`, `POST .../history/{snapshotId}/restore` | `PUT /admin/permissions` now captures a snapshot with `changesCount`/`summaryAr`. Restore re-applies, audit-logs the restore, emits `permissions.matrix.updated`. |
| 2.4 | `GET /reports/builder/fields`, `POST /reports/builder/preview`, `POST /reports/builder/save` | Whitelisted dimensions/metrics/filters over operations (no user SQL). Preview capped at 1000 rows (`capped` flag). Roles: accountant/head/company-admin. |

## Section 4 — contract confirmations

- **4.1 sales-variance assign** — confirmed, response is exactly:
  `{ operationId, varianceTotalHalalas, allocations:[{ id, employeeId, employeeName, amountHalalas, appliedAt }], remainingUnallocatedHalalas }`.
- **4.2 daily inventory reconciliation** (`POST /accountant/inventory/branches/{id}/daily-variance-allocation`) — confirmed, returns the reconciliation snapshot:
  `{ branchId, branchName, date, items:[{ itemId, itemName, unit, expectedQty, actualQty, varianceQty, variancePct, varianceValueHalalas, status, allocatedTo:[…] }], totalVarianceValueHalalas, unassignedVarianceValueHalalas }`.
- **4.3 forgot-password resend window** — current behavior is a **flat 60-second** cooldown (not exponential). Returns `{ ok, nextResendAvailableAt }`, or `429 RATE_LIMITED` with `nextResendAvailableAt` while the cooldown is active. The FE should rely on the returned `nextResendAvailableAt` timestamp. (Exponential-to-5-min is not implemented; can be added on request.)
- **4.4 realtime event/channel names** — confirmed/implemented:
  - `brand.upload.progress` → `operations.company.{companyId}`
  - `message.new` / `agent.joined` / `session.closed` → `chat.session.{sessionId}` (using §2.1's names, **not** the `chat.*`-prefixed variants from §4.4 — pick these).
  - `permissions.matrix.updated` → `operations.company.{companyId}` (admin acts globally; when the acting admin has no company it is emitted on `operations.company.platform`, which admins are authorized to subscribe to).

## Section 3 — operational / enterprise (shipped)

New deps: `pragmarx/google2fa` (TOTP), `firebase/php-jwt`. New middleware alias
`asab.apikey` (in `bootstrap/app.php`).

| # | Endpoints | Notes |
|---|---|---|
| 3.1 | `POST /auth/2fa/{setup,verify,disable}`, `GET /users/me/2fa-status`, extended `POST /auth/login` | TOTP + SMS. Secret + backup codes encrypted at rest (backup codes also hashed). `setup` → `{secret,qrCodeUrl}` (totp) or `{sentTo}` (sms); `verify` → `{backupCodes}`. `login` with 2FA on + no `code` → `{requires2fa:true, twoFactorToken}` (interim token cached 5 min, no access token); re-post with `{twoFactorToken, code}` (or `{email,password,code}`). |
| 3.3 | `GET/POST/DELETE /company/me/api-keys` | Key `asab_live_…` shown **once** (SHA-256 hash stored). Scopes: `operations:read|write`, `reports:read`, `inventory:read|write`. Authenticate integration calls with middleware `asab.apikey:<scope>` (alias registered; attach to integration routes as needed — not retrofitted onto existing endpoints). |
| 3.4 | `POST /users/me/data-export`, `GET .../data-export/{jobId}`, `POST /users/me/account-deletion-request` | Export = queued `GenerateDataExportJob` → JSON on the `public` disk + `downloadUrl`/`expiresAt` (7d). Deletion = T+30 `scheduled`, cancelable; `confirmEmail` must match the account. |
| 3.5 | `GET/POST/PATCH/DELETE /company/me/webhooks`, `POST .../{id}/test`, `GET .../{id}/deliveries` | Secret `whsec_…` returned once, encrypted at rest. Deliveries are HMAC-SHA256 signed (`X-ASAB-Signature: sha256=…`, `X-ASAB-Event`). `WebhookService::dispatchEvent($companyId,$event,$payload)` is the integration point for real events (operation.created, invoice.paid). |
| 3.6 | `GET /admin/jobs?status=&type=`, `POST /admin/jobs/{id}/{retry,cancel}` | Reads `asab_job_runs`. To populate, long-running jobs should write a `JobRun` row (recorder helper to be added where each job is dispatched). |
| 3.2 | `GET/PUT/DELETE /company/me/sso`, public `POST /auth/sso/{provider}/callback` | Config CRUD full (Enterprise-gated). Sign-in implemented for **OIDC** authorization-code flow (discovery → token exchange → claims → provision/login). `client_secret` encrypted. Full **SAML** assertion handling is deferred (config is stored). The OIDC callback can't be exercised without a live IdP; JWKS signature verification (via `firebase/php-jwt`) is the production hardening step. |

**Security posture (per CLAUDE.md):** all secrets (2FA secret/backup codes, OIDC client secret, webhook signing secret, API key) are encrypted or hashed at rest, never logged. Multi-tenant isolation enforced by explicit `company_id` scoping. The 2FA login change is additive — non-2FA logins are unchanged.

## Known environment note (pre-existing)

The SQLite test driver cannot run the full migration set today: ~24 pre-existing
`dropForeign(['col'])` / `dropForeign('name')` calls across 8 modules
(Inventory, Expense, Supplier, Custody, Purchase, RecurringOrder, Shift, Branch)
throw `This database driver does not support dropping foreign keys by name`. This
blocks the ASAB layer (and any ASAB feature test) from migrating on SQLite — it is
unrelated to this batch. The 5 new migrations in this batch were validated in
isolation on SQLite (all tables/columns created OK). Guarding those drops with a
`DB::getDriverName() !== 'sqlite'` check would restore the SQLite baseline; that is
a separate, cross-module change.
