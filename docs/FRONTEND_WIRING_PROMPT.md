# ASAB — Frontend Wiring Prompt (Final Batch)

> Paste this whole document to the frontend team. It is the authoritative,
> code-verified contract for the 24-item final batch (Sections 1–4). Every shape,
> validation rule, status code, role, and realtime event below was extracted
> directly from the shipped controllers/services/routes — wire against it exactly.

---

## 0. Global conventions (apply to every endpoint)

**Base URL:** all paths are under `/api/v1` (e.g. `POST {API_ORIGIN}/api/v1/auth/login`).

**Auth header:** `Authorization: Bearer <accessToken>` (Laravel Sanctum).
- `POST /api/v1/auth/login` → `{ accessToken, refreshToken, expiresIn: 900, user: {...} }` (access token lives 900s).
- Refresh: `POST /api/v1/auth/refresh` with `{ refreshToken }` → new `{ accessToken, refreshToken, expiresIn }` (refresh token is rotated — replace both).
- `user` object shape: `{ id, name, email, avatar, companyId, defaultPage, roles: [{ key, scope, brandIds:[], restaurantIds:[], branchIds:[], moduleKeys:[] }] }`. Note: login/SSO responses do **not** include `permissions` — only `GET /api/v1/auth/me` does.

**Idempotency:** mutating admin (`/admin/*`) and company-admin (`/company/*`) routes run idempotency middleware — send a unique `Idempotency-Key` header on POST/PUT/PATCH/DELETE to make retries safe.

**Success envelopes:**
- **Single resource** → the **bare JSON object** at the top level (no `{data}` wrapper). `200` for reads/updates, `201` for creates.
- **List (non-paginated)** → `{ "data": [...] }` (sometimes with `meta`).
- **Paginated** → `{ "data": [...], "meta": { page, pageSize, total, totalPages } }`.
- **No content** → `204` with a literal `null` body.

**Error envelope (all errors):**
```json
{ "error": { "code": "MACHINE_CODE", "message": "English", "messageAr": "عربي", "details": { "field": ["..."] } }, "requestId": "req_XXXXXXXXXXXXX" }
```
`messageAr` omitted when null; `details` omitted when empty. Common codes: `VALIDATION_ERROR` (422), `NOT_FOUND` (404), `INVALID_CREDENTIALS` (401), `USER_INACTIVE` (403), `RATE_LIMITED` (429), plus feature-specific codes listed per endpoint.

**Pagination params:** `?page` (default 1) and `?pageSize` (default 20, capped at 100).

**Money:** all JSON money fields are **integer halalas** (SAR × 100); divide by 100 to display SAR. Exception: spreadsheet **exports** render money as SAR decimal strings.

**Timestamps:** ISO-8601 with offset (Asia/Riyadh, e.g. `2026-06-09T12:00:00+03:00`) or `null`.

**Keys:** camelCase on the wire. **One exception:** webhook objects use snake_case `secret_prefix` (not `secretPrefix`) — handle it.

**Realtime (Pusher + Laravel Echo):** subscribe to **private** channels — Echo prepends `private-` to the channel name on the wire. ASAB channels use the `asab` auth guard (your Echo authorizer must send the Sanctum bearer). Channels & authorization:
| Channel (subscribe as `private-…`) | Who may subscribe |
|---|---|
| `notifications.user.{userId}` | the user themselves |
| `operations.company.{companyId}` | admin, or users in that company |
| `operations.brand.{brandId}` | admin, or users whose company owns the brand |
| `reminders.branch.{branchId}` | admin, or users whose company owns the branch |
| `chat.session.{sessionId}` | the session opener or its assigned agent (same company) |

Bind events by their **bare name** (e.g. `.message.new` in Echo). Broadcasts are best-effort — never block UI on them.

---

## SECTION 1 — UI-gap endpoints

### 1.1 Renew a restaurant's subscription
`POST /api/v1/admin/restaurants/{restaurantId}/subscription/renew` — role **admin**.
- Body: `{ "months"?: number }` — optional int 1–60, default **12**.
- `200` → restaurant-subscription object: `{ id, companyId, brandId, restaurantId, plan, status:"active", expiresAt, daysLeft, monthlyPrice (halalas), autoRenew, reminderEnabled, restaurantName, brandName }`.
- Realtime: `subscription.updated` on `operations.company.{companyId}` → `{ id, companyId, brandId, restaurantId, plan, status, expiresAt }`.
- Errors: `404 NOT_FOUND` if the restaurant has no subscription; `422 VALIDATION_ERROR` for bad `months`.

### 1.2 Brand auto-reminder toggle
`POST /api/v1/admin/brands/{brandId}/auto-reminder` — role **admin**.
- Body: `{ "enabled": boolean }` — **required**.
- `200` → `{ brandId, enabled, updatedAt }`. No realtime.

### 1.3 Audit-log entry detail
`GET /api/v1/admin/audit-logs/{id}` — role **admin**.
- `200` → `{ id, action, actorName, actorRole, entityType, entityId, description, before, after, ip, userAgent, occurredAt }`. `before`/`after` are raw stored state objects for diffing. `404` if missing.

### 1.10 Audit-log filter metadata
`GET /api/v1/admin/audit-logs/filters` — role **admin**.
- `200` → `{ "actionTypes": [ { value, labelAr, labelEn } ] }` — fixed list of 8: `users, approvals, subscriptions, rejection, export, inventory, permissions, purchases` (the `value`s feed `audit-logs/export?actionType=`).

### 1.4 Create company (extended)
`POST /api/v1/admin/companies` — role **admin**.
- Body: `name` (req, ≤200), `plan` (req, `Basic|Professional|Enterprise`), `contactName?` (≤200), `contactEmail?` (email ≤191), `contactPhone?` (≤32), `city?` (≤80), `logo?` (≤255), `billingCycle?` (`monthly|annual`, default `monthly`), `branchesLimitOverride?` (int 1–1000), `modules?` (string[]), `adminEmail?` (email).
- `201` → AdminCompany + `adminUserId`: `{ id, name, logo, contactName, contactEmail, contactPhone, city, plan, status:"trial", maxBranches, maxUsers, monthlyRevenue, startDate, nextBilling, modules, adminEmail, createdAt, adminUserId }` (`adminUserId` is null if no email supplied).
- Side effects: auto-creates a **company-admin** user (email = `contactEmail ?? adminEmail`) and emails a one-time password (password never returned). If `billingCycle=annual` and a matching plan exists → provisions the first invoice and emits `invoice.created` on `notifications.user.{adminUserId}` → `{ id, publicId, companyId, totalHalalas, amountDueHalalas, status }`.

### 1.5 Create a correction operation
`POST /api/v1/operations/{id}/correction` — roles **accountant, head**. `{id}` may be the operation UUID **or** its `publicId` (e.g. `OPS-0001`).
- Body (both shapes accepted): new `{ reason?, notes?, correctedFields? }` or legacy `{ correctionReason?, amount?, diffNote? }`. Effective reason = `reason ?? correctionReason` — **if both missing → 422** (`details.reason`). `notes` is appended to the reason. `correctedFields.amount` / `correctedFields.diffNote` override the copied values.
- `201` → linkage only: `{ originalOperationId, correctionOperationId, publicId, status:"pending", createdAt }`. No realtime.

### 1.6 Final approval (conditional = flag form)
`POST /api/v1/operations/{id}/final-approve` — role **head only**. (The old `POST /operations/{id}/conditional-approve` was **removed** — delete it from your client.)
- Body: `{ isConditional?: boolean (default false), conditionalNote?: string (required when isConditional=true, ≤1000), conditions?: [{ id?, text (req, ≤500), dueAt? (date) }] }`.
- `200` → the full operation object: `{ id, publicId, branchId, moduleKey, sourceModule, sourceId, amount (halalas), match, diffNote, origin, attachmentCount, status:"final-approved", rejectReason, isConditional, isCorrection, erpPosted, operationDate, submittedAt, approvedAt, finalApprovedAt, createdAt }`.
- Realtime: `operation.status_changed` on **both** `operations.company.{companyId}` and `operations.brand.{brandId}` → `{ operationId(publicId), from:"approved", to:"final-approved", by:{ id, name } }`.
- Errors: `409 OP_NOT_APPROVED` (with `details.currentStatus/requiredStatus`) if the op isn't in `approved` state.

### 1.7 Brand upload progress (poll + realtime)
**Poll:** `GET /api/v1/admin/brands/{brandId}/upload-status` — role **admin**.
- `200` → `{ uploads: [ { type, status:"queued|done|failed", progressPct, parsedRows, failedRows, failureReason, startedAt, finishedAt } ], shared: { sales, materials, suppliers }, completionPct }`. `uploads` mixes brand-level and restaurant-level rows under the brand.

**Realtime:** when an admin uploads (`POST /api/v1/admin/brands/{brandId}/upload/{type}`, multipart `file`), the server emits two ticks on `operations.company.{companyId}`, event `brand.upload.progress`:
- tick 1 (start): `{ brandId, type, status:"processing", progressPct:0, parsedRows:0, failedRows:0 }`
- tick 2 (done): `{ brandId, type, status:"done", progressPct:100, parsedRows:<count>, failedRows:<#errors> }`
The upload HTTP response itself is `{ uploadedCount, errors:[{ row, message }] }`.

### 1.8 Inventory export (binary)
`GET /api/v1/company/me/inventory/export?brandId=&branchId=&date=&format=` — role **accountant**.
- Query: `format` (`csv` else xlsx), `brandId?`, `branchId?` (if set, `brandId` ignored), `date?` (`YYYY-MM-DD`).
- `200` → **binary file** (not JSON). `Content-Disposition: attachment; filename="inventory-variance-YYYYMMDD-His.<ext>"`. Columns: `Branch | Item | Category | Unit | Expected Qty | Actual Qty | Variance Qty | Variance Pct | Variance Value (SAR) | Last Counted At | Counted By` (money column is SAR decimal string).

### 1.9 Reminder channel selector
**Single:** `POST /api/v1/reminders/{id}/send` — roles **accountant, head**.
- Body: `{ channel?: "in-app"|"email"|"whatsapp"|"sms" }` (default `in-app`).
- `200` → `{ id, reminderStatus:"sent", sent:true, channel, deliveredAt }`.

**Broadcast:** `POST /api/v1/reminders/broadcast` — roles **accountant, head**.
- Body: `{ messageAr (req, ≤1000), messageEn?, audience (req: all-branch-managers|all-accountants|all-suppliers|specific-branches), branchIds?: string[] (for specific-branches), channels?: ("in-app"|"email"|"whatsapp"|"sms")[] (default ["in-app"]) }`.
- `200` → `{ broadcastId, sentCount, failedCount:0, perChannel: { "<channel>": { sent, failed:0 } } }`. companyId is taken from the token, never the body. **Note:** only in-app is actually delivered today; other channels report the recipient count.

---

## SECTION 2 — new domains

### 2.1 Live support chat — any company role
- `POST /api/v1/company/me/support/chat/start` (no body) → `201 { sessionId, agentName:null, queuePosition, estimatedWaitSeconds }`.
- `GET /api/v1/company/me/support/chat/{sessionId}` → `200 { sessionId, status, messages: [ { id, authorType:"user|agent", text, sentAt } ] }`.
- `POST /api/v1/company/me/support/chat/{sessionId}/message` — body `{ text (req, 1–4000) }` → `201 { id, sentAt }`.
- `POST /api/v1/company/me/support/chat/{sessionId}/close` (no body) → `204`.
- Ownership: you can only access your own session (`404` otherwise).
- **Realtime** on `chat.session.{sessionId}`: `message.new` `{ id, authorType, text, sentAt }`; `agent.joined` `{ agentName }`; `session.closed` `{ closedBy, reason }`.

### 2.2 Onboarding tour state — any company role
- `GET /api/v1/users/me/onboarding-state` → `200 { completedSteps: string[], skipped: bool, completedAt }`.
- `PATCH /api/v1/users/me/onboarding-state` — body (all optional) `{ stepCompleted?: string, skip?: bool, reset?: bool }` → same shape. Apply order: reset → append stepCompleted → set skip (skip=true stamps completedAt). Step IDs are FE-defined (e.g. `welcome`, `dashboard-tour`).

### 2.3 Permission-matrix versioning — role **admin**
- `GET /api/v1/admin/permissions/history?page=&pageSize=` → paginated `{ data: [ { id, savedBy:{ id, name }, savedAt, changesCount, summaryAr } ], meta }`.
- `GET /api/v1/admin/permissions/history/{snapshotId}` → `200` the full matrix: `{ matrix: [ { module, perms: [perm×6] } ], roles: ["accountant","head","branch","procurement","supplier","admin"], legend: { view, submit, review, approve, final, none → {labelAr,labelEn} } }`. `perms` is positional, aligned to `roles`; each value ∈ `view|submit|review|approve|final|none`.
- `POST /api/v1/admin/permissions/history/{snapshotId}/restore` (no body) → `200` the fresh current matrix (same shape). Realtime: `permissions.matrix.updated` on `operations.company.{companyId}` (falls back to `operations.company.platform`) → `{ updatedAt }`.
- A snapshot is also auto-captured whenever the matrix is saved via the existing `PUT /api/v1/admin/permissions`.

### 2.4 Custom report builder — roles **accountant, head, company-admin**
> Paths are `/api/v1/reports/builder/*` (NOT under `/company/me`).
- `GET /api/v1/reports/builder/fields` → `200 { dimensions:[{ key, labelAr, labelEn, type }], metrics:[{ key, labelAr, labelEn, aggregation }], filters:[{ key, labelAr, type, options? }] }`. Dimensions: `moduleKey, branchId, status, origin, operationDate`. Metrics: `totalAmount(sum), count, avgAmount(avg)`. Filter options: moduleKey ∈ `sales,expenses,purchases,inventory,waste,assets,custody`; status ∈ `pending,approved,final-approved,rejected`.
- `POST /api/v1/reports/builder/preview` — body `{ dimensions?: string[], metrics?: string[], filters?: { key: scalar|array }, dateRange?: { from?, to? } }` → `200 { rows:[{ <dim>:value, <metric>:number }], totals:{ <metric>:number }, rowCount, capped }`. Capped at **1000** rows. Unknown dimension/metric → `422 VALIDATION_ERROR`. Money metrics are halalas (avgAmount 2-dp).
- `POST /api/v1/reports/builder/save` — body `{ name (req, ≤160), descriptionAr?, definition (req, object) }` → `201 { id, name, createdAt }`.

---

## SECTION 3 — operational / enterprise

### 3.1 Two-factor auth (account-level — any authenticated user; bearer token)
- `POST /api/v1/auth/2fa/setup` — body `{ method: "totp"|"sms" }` → `200`: totp → `{ secret, qrCodeUrl(otpauth://...) }`; sms → `{ sentTo: "<masked phone>" }`. `409 TWO_FACTOR_ALREADY_ENABLED` if already on.
- `POST /api/v1/auth/2fa/verify` — body `{ code }` → `200 { backupCodes: string[8] }` (plaintext, shown once). `409 TWO_FACTOR_NOT_SETUP`; `422 TWO_FACTOR_INVALID_CODE`.
- `POST /api/v1/auth/2fa/disable` — body `{ code }` (TOTP or an unused backup code) → `204`. `409 TWO_FACTOR_NOT_ENABLED`; `422 TWO_FACTOR_INVALID_CODE`.
- `GET /api/v1/users/me/2fa-status` → `200 { enabled, method: "totp"|"sms"|null, backupCodesRemaining }`.

**Login step-up** (`POST /api/v1/auth/login`, public):
- Validation: `email` & `password` required *unless* `twoFactorToken` is present; `code?`, `twoFactorToken?`, `rememberMe?`.
- If 2FA **off** (or `code` sent and valid) → standard auth response `{ accessToken, refreshToken, expiresIn:900, user }`.
- If 2FA **on** and no `code` → `200 { requires2fa:true, twoFactorToken }` (no tokens; SMS sent if method=sms; token valid 5 min). Then call login again with `{ twoFactorToken, code }` → standard auth response.
- Errors: `401 INVALID_CREDENTIALS`, `403 USER_INACTIVE`, `422 TWO_FACTOR_INVALID_CODE`, `401 TWO_FACTOR_TOKEN_INVALID` (expired step-up token).

### 3.2 SSO (Enterprise) — role **company-admin** for config; callback public
- `GET /api/v1/company/me/sso` → `200` configured: `{ enabled, provider:"saml"|"oidc", metadataUrl, entityId, x509cert, oidcIssuer, oidcClientId, defaultRole }`; not configured: `{ enabled:false, provider:null }`. (Secrets `oidcClientSecret`/`metadata` are never echoed.)
- `PUT /api/v1/company/me/sso` — **Enterprise plan only** (else `403 PLAN_REQUIRED`). Body: `provider (req: saml|oidc)`, `enabled?` (default true), `oidcIssuer`/`oidcClientId` (required when provider=oidc), `oidcClientSecret?`, `metadataUrl?`, `metadata?`, `entityId?`, `x509cert?`, `defaultRole?` (`accountant|head|branch|procurement`, default accountant) → `200` (same shape as GET).
- `DELETE /api/v1/company/me/sso` → `204` (disables SSO, back to password).
- `POST /api/v1/auth/sso/{provider}/callback` (**public**; `{provider}` must be `oidc`). Body `{ companyId (req), code (req), redirectUri (req url) }` → `200` standard auth response. Errors: `422 SSO_PROVIDER_UNSUPPORTED` (non-oidc), `409 SSO_NOT_CONFIGURED`, `502 SSO_DISCOVERY_FAILED`/`SSO_EXCHANGE_FAILED`, `422 SSO_NO_EMAIL`. (Full SAML sign-in is not implemented yet — config is stored.)

### 3.3 API keys — role **company-admin**
- `GET /api/v1/company/me/api-keys` → `200 { data: [ { id, name, prefix:"asab_live_xxxxxx", scopes, lastUsedAt, createdAt, expiresAt } ] }` (only non-revoked).
- `POST /api/v1/company/me/api-keys` — body `{ name (req, ≤120), scopes (req, ≥1, each ∈ operations:read|operations:write|reports:read|inventory:read|inventory:write), expiresInDays? (1–3650, default never) }` → `201 { id, name, key:"asab_live_<40>", scopes, expiresAt }`. **`key` is shown once — store it now.**
- `DELETE /api/v1/company/me/api-keys/{id}` → `204` (soft-revoke). `404` if not in your company.
- Integrations call ASAB with `Authorization: Bearer asab_live_...`; the server enforces the scope per route (`401 API_KEY_REQUIRED`/`INVALID_API_KEY`, `403 INSUFFICIENT_SCOPE`).

### 3.4 GDPR / PDPL — any authenticated user (bearer only, no tenant/role)
- `POST /api/v1/users/me/data-export` (no body) → `201 { jobId, status:"queued" }`.
- `GET /api/v1/users/me/data-export/{jobId}` → `200 { jobId, status, downloadUrl (only when status==="ready"), expiresAt }`. `404` for someone else's job.
- `POST /api/v1/users/me/account-deletion-request` — body `{ reason?, confirmEmail (req, must equal your account email) }` → `201 { requestId, scheduledFor (T+30 days) }`. `422 EMAIL_MISMATCH` if the email doesn't match.

### 3.5 Webhooks — role **company-admin**
- `GET /api/v1/company/me/webhooks` → `200 { data: [ { id, url, events, secret_prefix, isActive, description, lastTriggeredAt, failureCount } ] }`. (Note snake_case `secret_prefix`.)
- `POST /api/v1/company/me/webhooks` — body `{ url (req url ≤1000), events (req, ≥1, each ∈ operation.created|operation.status_changed|invoice.created|invoice.paid|subscription.updated), description? }` → `201 { id, url, events, secret:"whsec_<40>", isActive }`. **`secret` shown once** — use it to verify the HMAC.
- `PATCH /api/v1/company/me/webhooks/{id}` — body `{ url?, events?, isActive? }` → `200` (full object). 
- `DELETE /api/v1/company/me/webhooks/{id}` → `204`.
- `POST /api/v1/company/me/webhooks/{id}/test` — body `{ event? (default operation.created) }` → `200 { delivered, statusCode, error }`.
- `GET /api/v1/company/me/webhooks/{id}/deliveries?page=&pageSize=` → paginated `{ data: [ { id, event, statusCode, error, response, attemptedAt } ], meta }`.
- **Delivery signature (your endpoint receives):** headers `X-ASAB-Signature: sha256=<hex hmac_sha256(rawBody, secret)>` and `X-ASAB-Event: <event>`. Body = `{ "event": <event>, "data": <payload> }`. Recompute HMAC over the exact raw bytes.

### 3.6 Background-job monitoring — role **admin**
- `GET /api/v1/admin/jobs?status=&type=&page=&pageSize=` → paginated `{ data: [ { id, type, status, companyId, branchId, triggeredBy:{ userId, name }, progressPct, attemptCount, lastError, queuedAt, startedAt, finishedAt } ], meta }`. `status` accepts comma-separated values.
- `POST /api/v1/admin/jobs/{id}/retry` → `200 { ok:true, requeuedAt }`.
- `POST /api/v1/admin/jobs/{id}/cancel` → `200 { ok:true, cancelledAt }`.

---

## SECTION 4 — confirmed existing contracts

### 4.1 Sales-variance assign — role **accountant**
`POST /api/v1/company/me/operations/{id}/sales-variance/assign` (`{id}` = uuid or publicId of a sales op).
- Body: `{ allocations: [{ employeeId?|empNumber?, amountHalalas (req int ≥1) }] (req ≥1), notes? }`.
- `200` → `{ operationId, varianceTotalHalalas, allocations: [{ id, employeeId, employeeName, amountHalalas, appliedAt }], remainingUnallocatedHalalas }`.
- If the op carries a known variance, the allocation sum must equal it exactly (else `422 VALIDATION_ERROR` with the expected total). Unknown employee → `422`.

### 4.2 Daily inventory variance allocation — roles **accountant, head**
`POST /api/v1/accountant/inventory/branches/{branchId}/daily-variance-allocation` (note: **not** under `/company/me`).
- Body: `{ date (req), items: [{ itemId (req), allocations: [{ employeeId (req), qty (req ≥0) }] (req ≥1) }] (req ≥1) }`.
- `200` → reconciliation snapshot: `{ branchId, branchName, date, items: [{ itemId, itemName, unit, expectedQty, actualQty, varianceQty, variancePct, varianceValueHalalas, status:"flagged|ok", allocatedTo:[{ employeeId, employeeName, qty, valueHalalas }] }], totalVarianceValueHalalas, unassignedVarianceValueHalalas }`.
- `404` if no inventory submission exists for that branch+date. Realtime: `inventory.variance_allocated` on `operations.brand.{brandId}` → `{ branchId, date, totalValueHalalas }`.

### 4.3 Forgot-password resend — public
`POST /api/v1/auth/forgot-password/resend` — body `{ email (req) }` → `200 { ok:true, nextResendAvailableAt }`. **Cooldown = flat 60 seconds** per email (not exponential). While active → `429 RATE_LIMITED` with `details.nextResendAvailableAt`. Always `ok:true` (no email enumeration). **Drive your countdown off the returned `nextResendAvailableAt`.**

### 4.4 Realtime event names (final)
- `brand.upload.progress` → `operations.company.{companyId}`
- `message.new` / `agent.joined` / `session.closed` → `chat.session.{sessionId}` (use these names — **not** `chat.*`)
- `permissions.matrix.updated` → `operations.company.{companyId}`
- `subscription.updated` → `operations.company.{companyId}`
- `operation.status_changed` → `operations.company.{companyId}` + `operations.brand.{brandId}`
- `invoice.created` → `notifications.user.{userId}`
- `inventory.variance_allocated` → `operations.brand.{brandId}`

---

## Removed / changed (update your client)
- **Deleted:** `POST /api/v1/operations/{id}/conditional-approve` → use `final-approve` with `isConditional:true`.
- **Report builder** lives at `/api/v1/reports/builder/*` (not `/company/me/...`).
- **Daily variance allocation** lives at `/api/v1/accountant/inventory/branches/{branchId}/daily-variance-allocation`.
