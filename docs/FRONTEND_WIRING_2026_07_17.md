# Frontend wiring — 2026-07-17 (brand create · user credentials · Excel upload)

Backend changes landed on `asab-admin-backend`. Three dashboard areas are affected.
Every fact below was verified against the shipped code, not a plan.

---

## 0. Error envelope (unchanged, but people keep getting it wrong)

Every failure from an ASAB endpoint looks like this — there is **no top-level `errors` key**,
and validation errors are nested under `error.details`:

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Validation failed",
    "messageAr": "فشل التحقق من البيانات",
    "details": { "companyId": ["The selected companyId is invalid."] }
  },
  "requestId": "req_A1B2C3D4E5F6G"
}
```

Read `error.code` to branch, and render `error.messageAr` to the user (Arabic UI).
`error.details` is present only when non-empty.

---

## 1. Add Brand — «إضافة علامة تجارية جديدة»

`POST /api/v1/admin/brands`

### What changed

**`companyId` is now OPTIONAL.** Omit it and the backend creates a company named after the
brand and links it automatically.

> Remove the `*` from «الشركة» and let the user submit without picking one.

**`companyId`, when sent, must be a real, non-deleted company.** Previously any string was
accepted and silently created an orphaned brand invisible to everyone. It now returns **422**
with `error.details.companyId`. Do not send `""`, `null` is fine — just omit the key.

### Request

```jsonc
{
  "name": "علامة جديدة",        // required, max 120
  "companyId": "uuid",           // OPTIONAL — omit to auto-create
  "owner": "اسم المالك",         // optional, display name
  "ownerEmail": "owner@x.com",   // optional — provisions a login + emails the password
  "plan": "gold",                // optional — package CODE (see below)
  "abbr": "AJ",                  // optional, max 8
  "color": "#7C3AED",            // optional, max 16
  "modules": []                  // optional
}
```

### Response `201`

```jsonc
{
  "id": "...", "companyId": "...", "name": "...", "owner": "...", "ownerEmail": "...",
  "ownerUserId": "uuid|null",
  "emailSent": true               // <-- NOW MEANINGFUL. See §2.
}
```

### The «الباقة» dropdown is currently wrong

Your screenshot shows it rendering **«تيست»**, which is a *brand* name — the select is bound to
the wrong source. Populate it from:

`GET /api/v1/admin/packages` → `{ "data": [ { "id", "code", "name", "isActive", ... } ] }`

Submit **`code`** (e.g. `silver` / `gold` / `platinum`), not `id` and not `name`.
Filter `isActive: true` client-side. Arabic aliases («فضي»/«ذهبي»/«بلاتيني») are accepted and
resolved server-side, but prefer the code. An unknown code → **422**.

### The «الشركة» dropdown

There is no `lookups/companies` endpoint and none is needed — use the existing:

`GET /api/v1/admin/companies?pageSize=100&search=` → `{ "data": [ { "id", "name", ... } ], "meta": {...} }`

Read `.data`. If your select is empty today, this binding is the thing to check.

### Also fixed

`PATCH /api/v1/admin/brands/{id}` now accepts the same Arabic plan aliases as create and
validates the code (previously it accepted any string).

---

## 2. Users & credentials — this is the big behavioural change

### Every user you add is now emailed their password **by default**

`POST /api/v1/admin/users` — `sendLoginEmail` previously defaulted to **false**, so the default
"add user" flow emailed nobody and the account was unusable. It now defaults to **true**, matching
`BACKEND_API_SPEC.md`. Send `sendLoginEmail: false` explicitly to suppress.

**Response `201` now includes `emailSent: boolean`.** Surface it. `false` means the account exists
but the user never got their password — show a warning and offer "resend" (`POST
/admin/users/{id}/reset-password`). It is no longer a lie: it previously returned `true` even when
mail was only written to a log file.

> Applies equally to `emailSent` on Add Brand (§1), `POST /admin/brands/{id}/owner/reset-password`,
> and `POST /admin/companies/{id}/admin/reset-password`.

### `POST /admin/users/import` — new response field

```jsonc
{ "imported": 12, "skipped": 1, "emailFailed": 0, "errors": [] }
```

Imported users are now emailed their password too. `emailFailed > 0` → tell the admin which
accounts need a resend. Accepts optional `sendLoginEmail` (defaults true).

### ⚠️ BREAKING — `role: "supplier"` now requires `supplierId`

```jsonc
{
  "name": "...", "email": "vendor@x.com", "role": "supplier",
  "supplierId": "uuid"   // REQUIRED — must exist in asab_suppliers
}
```

**You must add a supplier picker to the Add User form** when role = supplier. Without it → **422**.
Source the list from the existing suppliers endpoint.

Two rules that will bite:
- The user's `email` **must equal** that supplier's `contact_email`, else `422
  SUPPLIER_EMAIL_MISMATCH`. The mobile app authenticates suppliers by that address, so a mismatch
  would issue a password that only opens the dashboard. Best UX: auto-fill (and lock) the email
  from the selected supplier.
- Supplier must belong to the admin's company → `422 SUPPLIER_NOT_IN_COMPANY`.
- Other codes: `SUPPLIER_LOGIN_AMBIGUOUS`, `SUPPLIER_LEGACY_MISSING`.

### ⚠️ BREAKING — `role: "branch"` branch id is now validated

`branches` must be exactly one **existing** branch UUID, belonging to the admin's company.
Previously a bad id succeeded silently. Now `422` (`error.details["branches.0"]`, or
`BRANCH_NOT_IN_COMPANY`).

### What "same password on mobile + dashboard" now means

Adding a `supplier`, `branch` (branch manager), or brand-owner user creates a **real mobile app
login** with the same password. One email, both worlds. No FE work needed — just don't promise
otherwise in copy.

Consequence worth surfacing in the UI: **deleting or deactivating a dashboard user now also
revokes their mobile app access** and kills their app sessions. Reactivating does **not**
automatically restore mobile access. Word your confirm dialogs accordingly
(«سيتم إلغاء دخول المستخدم من التطبيق أيضاً»).

### NOT delivered (don't build against it)

Supplier dashboard **screens** are still behind a disabled feature flag — every `/asab/supplier/*`
URI still 404s. This release gives suppliers a *login*, not a portal.

---

## 3. Data Upload — «رفع البيانات»

### Templates

`GET /api/v1/admin/upload/templates/{type}` → `.xlsx` binary (default) or `?format=csv`.
`type` ∈ `sales-items` | `raw-materials` | `suppliers` | `fixed-assets`.
Aliases `GET /admin/uploads/templates/{type}` also work.

### Upload

```
POST /api/v1/admin/brands/{brandId}/upload/sales-items
POST /api/v1/admin/brands/{brandId}/upload/raw-materials
POST /api/v1/admin/brands/{brandId}/upload/suppliers
POST /api/v1/admin/brands/{brandId}/upload/fixed-assets     // NEW — brand-level assets
POST /api/v1/admin/branches/{branchId}/upload/fixed-assets  // existing, branch-level
```
`multipart/form-data`, field name **`file`**. Accepted: `.xlsx`, `.csv`, `.txt`. **Max 2 MB.**
(`/uploads/` plural aliases exist for all of the above.)

Success `200`:
```jsonc
{ "uploadId": "...", "rowsImported": 12, "uploadedCount": 12, "errors": [], "status": "done" }
// assets: { "assetCount": 12, "errors": [] }
```
`errors` is per-row: `[ { "row": 4, "message": "..." } ]` — a partial import is normal, render them.

### ⚠️ Uploading the blank template now returns 422 — handle it

Previously a header-only file returned `200 {"rowsImported": 0, "status": "done"}` — a **fake
success**, which is why your counter sat at 0/3 while claiming completion. Now:

| `error.code` | HTTP | When | Render |
|---|---|---|---|
| `EMPTY_FILE` | 422 | File has headers but no data rows | «الملف لا يحتوي على صفوف بيانات» |
| `INVALID_HEADER` | 422 | Column headers don't match the template | Show `error.details.expected` vs `.received` |
| `INVALID_INPUT` | 400/422 | Unknown upload type; or asset condition counts > total quantity | `error.messageAr` |

`INVALID_HEADER` details are FE-renderable — use them:
```jsonc
{ "error": { "code": "INVALID_HEADER",
  "details": { "expected": [["رمز الصنف","اسم الصنف","التصنيف","وحدة البيع","السعر"]],
               "received": ["code","name","..."] } } }
```
`expected` is an **array of layouts** (fixed-assets accepts two — see below).

### Required columns — fix your UI hint

The UI currently shows «الفئة» as the grouping column for items. The template says **«التصنيف»**.
Both are accepted for items, but the hint should match the template you hand out:

| type | header row (exact order) |
|---|---|
| `sales-items` | `رمز الصنف, اسم الصنف, التصنيف, وحدة البيع, السعر` |
| `raw-materials` | `رمز المادة, اسم المادة, التصنيف, وحدة القياس, التكلفة` |
| `suppliers` | `رقم المورد, اسم المورد, الفئة, جهة الاتصال, شروط الدفع` — («الفئة» here is correct) |

The first column (`رمز الصنف` / `رمز المادة` / `رقم المورد`) is now actually **saved** — it was
being parsed and thrown away before.

### Fixed assets — both templates accepted

**Arabic (8 cols):** `اسم الأصل, الفئة, اسم الفرع, رقم الفاتورة, التكلفة (ر.س), العمر الافتراضي (شهر), أمين العهدة, ملاحظات`

**English (11 cols)** — the client's own `Assab_Fixed_Assets_Upload_Template.xlsx`:
`Serial Number, Zone, Asset Category (Type), Asset Name, Total Quantity, Excellent, Maintenance, Problem, Purchase Date, Purchase Value, Notes`

The layout is detected from the header row. Notes:
- `28,000.00` now parses correctly (it used to import as **28 halalas**).
- `Excellent + Maintenance + Problem` may not exceed `Total Quantity` → per-row error.
- English category labels map onto canonical keys (`Kitchen Equipment` → `kitchen`, `POS & IT
  Equipment` → `tech`, …). Arabic labels map to the same keys. Unknown labels are stored verbatim.
- Brand-level asset uploads land with **`branch_id = null`** ("pending assignment"). There is no
  screen to assign them to a branch yet — flag if you need one.

### Status / progress

`GET /api/v1/admin/brands/{brandId}/upload-status`

```jsonc
{
  "uploads": [ { "type": "sales-items", "status": "done|failed|queued|processing",
                 "progressPct": 100, "parsedRows": 12, "failedRows": 0,
                 "failureReason": null, "startedAt": "...", "finishedAt": "..." } ],
  "shared":  { "sales": true, "materials": false, "suppliers": false },
  "completionPct": 33
}
```
`shared.*` / `completionPct` now require a **successful** upload (`uploaded_count > 0` and status
`done`). A failed upload no longer counts toward «مكتمل» — the 0/3 counter will now be truthful.

Realtime tick (unchanged, Pusher): `processing` → `done` per brand/type.

---

## Summary of FE work

1. **Add Brand**: make «الشركة» optional (drop the `*`); bind it to `GET /admin/companies` → `.data`;
   fix «الباقة» to bind to `GET /admin/packages` and submit `code`.
2. **Add User**: add a **supplier picker** for `role=supplier` (required `supplierId`, email must match
   the supplier's `contact_email`); validate the branch picker for `role=branch`.
3. **Surface `emailSent`** everywhere a password is issued; offer resend when `false`.
4. **Upload**: render `EMPTY_FILE` / `INVALID_HEADER` (use `details.expected` vs `details.received`);
   change the items column hint «الفئة» → «التصنيف»; enforce the 2 MB cap client-side.
5. **Confirm dialogs**: deleting/deactivating a user also revokes their mobile app access.

---

# Round 2 — answers to the FE review (2026-07-17)

Point numbers match your list. **3 of your 10 items already worked** — check your deployed build
before writing code against §2 and §8.

| # | Ask | Verdict |
|---|-----|---------|
| 1 | Admin supplier list | ✅ **Shipped now** — `GET /admin/suppliers?companyId=` |
| 2 | Branch `id` in brands tree | ✅ **Already returned** — no change needed |
| 3 | Accountant restaurants | ⚠️ **Answered + now 422s** instead of silently dropping |
| 4 | `reportsTo` value | ⚠️ **Breaking fix** — userId, and GET now returns `{id,name}` |
| 5 | `createdAt` / `lastLoginAt` | ✅ Confirmed, already returned |
| 6 | `role=branch` required fields | ⚠️ **Your assumption is wrong** — `companyId` is also required |
| 7 | Parent-existence validation | ❌ **Not delivered** — partial today, see table |
| 8 | `PATCH /subscriptions/{id}/modules` | ✅ **Already published** — not a proposal |
| 9 | Email / `FRONTEND_URL` | ❌ Ops — and worse than you described |
| 10 | CORS | ❌ Ops — no `config/cors.php` exists at all |

## 1. ✅ `GET /api/v1/admin/suppliers` — new

Built because you were right: `/company/me/suppliers` names only the five *company* roles in its
role guard, so a platform admin reading it gets `403 WRONG_ROLE`. This is the admin-side twin.

```
GET /api/v1/admin/suppliers?companyId={uuid}&search=&status=active&page=1&pageSize=50
```

All params optional. `companyId` **omitted = every company** (admins are cross-tenant by design);
send it to scope the picker. `search` matches name *or* contact email. `pageSize` caps at 100.

```jsonc
{
  "data": [
    {
      "id": "uuid",
      "name": "Alpha Foods",
      "contactEmail": "alpha@vendor.test",  // lock POST /admin/users email to THIS
      "companyId": "uuid",
      "status": "active",
      "hasLogin": false                     // already provisioned a login
    }
  ],
  "meta": { "page": 1, "pageSize": 50, "total": 1, "totalPages": 1 }
}
```

Two fields worth binding beyond what you asked for:

- **`contactEmail` can be `null`.** A supplier with no contact email can never get a login —
  `SUPPLIER_EMAIL_MISMATCH` treats null as a mismatch. Disable those rows in the picker rather
  than letting the admin discover it at submit.
- **`hasLogin: true`** means a login already exists for that supplier. Creating a second one is
  what `SUPPLIER_LOGIN_AMBIGUOUS` rejects later. Grey them out.

## 2. ✅ Branch `id` is already in `GET /admin/brands`

No change was needed — `restaurants[].branches[]` has emitted `{id, name, manager}` all along, and a
test asserts that exact structure. If your build sees no `id`, you are on a stale deploy — re-pull
before filing this again. Wire `POST /admin/branches/{branchId}/upload/fixed-assets` to
`restaurants[].branches[].id` as-is.

## 3. ⚠️ Accountant restaurants — neither of your two options, but close to the second

The honest answer to «يا إما... يا إما»: **an accountant is brand-level *on the user endpoints*, but
restaurant-scoped accountants are real and are configured elsewhere.**

- `POST /admin/users` + `PATCH /admin/users/{id}` — **brand only.** Sending a non-empty
  `restaurants` now returns **422** (`error.details.restaurants`) instead of returning `201` with
  `"restaurants": []` and pretending it saved. Sending `scope` also 422s — it was always forced to
  `"brand"` regardless of what you sent.
- **Restaurant assignment lives on the distribution endpoints**, which already exist and already
  set `scope: "restaurant"`:

```
GET    /api/v1/admin/distribution                              // heads, accountants, free restaurants
PATCH  /api/v1/admin/accountants/{accId}/assignments           // { headId?, brands?, restaurants? }
POST   /api/v1/admin/distribution/assign-restaurant            // { accountantId, restaurantId }
DELETE /api/v1/admin/distribution/assign-restaurant            // { accountantId, restaurantId }
```

**So: remove the restaurant picker from the Add/Edit User form** (that part of your option B is
right), and drive restaurants from the التوزيع screen. Empty `restaurants: []` still passes, so you
do not need to strip the key.

> ⚠️ **Known sharp edge, not fixed in this pass.** `PATCH /admin/users/{id}` with `brands` **clears
> any restaurants the distribution screen assigned** (it zeroes `restaurant_ids` to stop the tenant
> resolver OR-ing stale ids into scope). Editing an accountant's brands from the users screen
> silently undoes distribution work. Avoid sending `brands` from the users screen for accountants
> until we reconcile the two screens.

## 4. ⚠️ `reportsTo` — userId, never the name (breaking on both sides)

**You were sending the name. That was being written straight into a `uuid` column with no foreign
key**, producing an unresolvable reference (and a 500 rather than a 422 on Postgres). Fixed:

- **Request** — `reportsTo` must be the head's **`userId`** and must exist. A name → `422`
  (`error.details.reportsTo`). Optional; omit or send `null` to leave unset.
- **Response — BREAKING.** `GET|POST|PATCH /admin/users` now return an object, not a bare uuid,
  so you get the name without a second lookup:

```jsonc
// before
"reportsTo": "8f3a...-uuid"
// now
"reportsTo": { "id": "8f3a...-uuid", "name": "محمد علي" }   // or null
```

Populate the picker from `GET /admin/users?roleFilter=head`.

## 5. ✅ `createdAt` and `lastLoginAt` both return

Already in every user row, ISO-8601, and **nullable** — `lastLoginAt` is `null` until the user's
first successful login, so your «—» fallback is correct and will legitimately show for new accounts.

## 6. ⚠️ `role=branch` — your assumption is incomplete

Confirmed: `branches` = **exactly one** existing branch UUID. `modules` and `reportsTo` are **not
required** and do not 422 when omitted or empty.

**But `companyId` is required in practice, and this is the part you're missing.** The rule says it's
nullable, yet creating a branch manager provisions a real mobile `branch_managers` login, and that
provisioner fails closed:

```jsonc
{ "name": "...", "email": "...", "role": "branch",
  "companyId": "uuid",        // REQUIRED in practice — omit it and you get 422
  "branches": ["branch-uuid"] // must belong to THAT company
}
```

Omit `companyId`, or send a branch owned by a different company → **`422 BRANCH_NOT_IN_COMPANY`**
(not `VALIDATION_ERROR`, so match on `error.code`). This is not new behaviour — it just was never
written down.

## 7. ❌ Parent-existence validation — partial, not delivered this pass

Deliberately deferred; here is exactly where you stand today so you can code defensively:

| Field | Endpoint | Validated? |
|---|---|---|
| `branches.*` | `POST /admin/users` (`role=branch`) | ✅ `422` + `BRANCH_NOT_IN_COMPANY` |
| `supplierId` | `POST /admin/users` (`role=supplier`) | ✅ `422` |
| `reportsTo` | `POST|PATCH /admin/users` | ✅ `422` (new, §4) |
| `companyId` | `POST /admin/brands` | ✅ `422` (soft-delete aware) |
| `plan` | `POST /admin/brands` | ✅ `422` against the package catalog |
| `brands.*` | `POST /admin/users` (`role=accountant`) | ❌ **accepted silently** — typo'd id orphans |
| `restaurants.*` | `POST /admin/users` | n/a — now `prohibited` (§3) |
| `modules.*` | `POST /admin/brands`, `PATCH /subscriptions/{id}/modules` | ❌ any string accepted |
| `brandId` | `POST /admin/subscriptions` | ⚠️ `404`, not `422` |

The `❌` rows have **no FK behind them**, so a bad id is written and orphaned rather than rejected.
Treat your own picker as the guard until this lands.

## 8. ✅ `PATCH /admin/subscriptions/{id}/modules` is live

Not a proposal — it is registered and serving. Wire «تعديل الموديولات» to it.

```
PATCH /api/v1/admin/subscriptions/{id}/modules     body: { "modules": ["sales", "expenses"] }
```
Returns the full updated subscription object. Caveat per §7: module strings are **not** validated
against a catalog — send keys from `GET /admin/lookups/modules`, because a typo will be stored.

## 9. ❌ Email — ours, and the diagnosis was worse than yours

Correct that `MAIL_MAILER=log` must become real SMTP. Two things you did not have:

- Credential mail is **refused outright** under `log` (by design — it stops passwords landing in a
  log file). So today users get **no** password email at all; `emailSent: false` is truthful.
- **Setting `FRONTEND_URL` in `.env` alone will not fix the links.** Every notification builds its
  URL from `config('app.frontend_url')`, and that key **does not exist in `config/app.php`** — it
  resolves to `null`, so links are a host-less `"/login"`, not `http://localhost:3000/login`. The
  config key has to be added too. Tracked on our side; no FE work.

## 10. ❌ CORS — not configured at all

Sharper than «يشمل دومين الفرونت»: **`config/cors.php` does not exist**, so Laravel's defaults apply
— `paths: ['api/*', 'sanctum/csrf-cookie']`, `allowed_origins: ['*']`, `supports_credentials: false`.

Practical effect for you today: cross-origin calls to `/api/*` **work** (wildcard origin), `Bearer`
tokens are unaffected, but `broadcasting/*` is **not** covered — Pusher auth will fail cross-origin.
Publishing the file is on us.

> Note on your `supports_credentials: true` request: it is only needed for **cookie** auth, and the
> browser rejects `credentials: true` together with `allowed_origins: ['*']`. Current auth is Bearer
> tokens, which need neither. Tell us if you actually intend to move to cookies.

---

# Round 3 — the six defects reported 2026-07-20

Reported from the dashboard: employees template dead, fixed assets «not working», adding a branch
manager answers «تعذر الاتصال بالخادم», and three role-model corrections. Items 1–3 and 6 are fixed
below. Items 4 and 5 (supplier / procurement manager decoupled from companies) are a separate pass.

| # | Report | Verdict |
|---|--------|---------|
| 1 | Employees template dead | ✅ **Restored** — template + per-restaurant upload, both new |
| 2 | Fixed assets not working | ✅ **Fixed** — the status endpoint never existed |
| 3 | Branch manager «تعذر الاتصال بالخادم» | ✅ **Fixed** — was a genuine uncaught 500 |
| 4 | Supplier independent of companies | ⏳ Confirmed, next pass |
| 5 | Procurement manager same, web-only | ⏳ Confirmed, next pass |
| 6 | Admin must not pick brands | ✅ **Your bug** — the backend never required them |

## 1. ✅ Employees — template and upload are live again

The `employees` type was dropped at the client meeting «to avoid confusion with user management», so
`GET /admin/upload/templates/employees` had been 404ing all along. Restored, because a roster in
`asab_employees` and a dashboard login in `asab_users` were never the same thing.

```
GET  /api/v1/admin/upload/templates/employees            // .xlsx; ?format=csv for CSV
POST /api/v1/admin/restaurants/{restaurantId}/upload/employees
POST /api/v1/admin/restaurants/{restaurantId}/uploads/employees   // plural alias
GET  /api/v1/admin/restaurants/{restaurantId}/upload-status
```

**Per restaurant, not per brand** — the screen says كل مطعم له قائمة موظفين مستقلة, so wire the
button to `restaurants[].id` from `GET /admin/brands`.

Columns: `اسم الموظف | الوظيفة | اسم الفرع | رقم الجوال | رقم الهوية | الراتب الشهري (ر.س) | نوع الوردية | تاريخ التعيين`.
Common English/Arabic aliases are accepted per column, so a hand-made sheet still imports; only
**اسم الموظف** and **الوظيفة** are mandatory.

Two behaviours to surface in the UI:

- **`اسم الفرع` is resolved, and an unmatched name is a row error** — not a silent null the way the
  fixed-assets importer treats its own branch column. Matching is case- and whitespace-insensitive
  and is limited to branches of **that** restaurant. Leave the cell blank for an unassigned employee.
- **Salary is written in riyals and stored in halalas** (×100), matching
  `POST /company/me/branch/employees`. Do not pre-multiply.

Response is the same shape as the other uploads:

```jsonc
{ "employeeCount": 12, "errors": [ { "row": 4, "message": "لا يوجد فرع باسم «فرع الرياض 1» ضمن هذا المطعم" } ] }
```

A cashier-role row also provisions the mobile cashier login, exactly as adding one by hand does.

## 2. ✅ Fixed assets — the upload always worked; reading the state did not

Nothing was wrong with the template or the import. Two real defects sat around them:

- **`GET /admin/branches/{branchId}/upload-status` did not exist.** `POST .../upload/fixed-assets`
  has always stamped an `owner_type='branch'` row, but the only status endpoint hard-filtered to
  `owner_type='brand'`. The data was written and unreadable, so the per-branch «حالة الرفع» column
  could never leave «لم يُرفع» no matter how many times you uploaded. **Now published:**

```jsonc
GET /api/v1/admin/branches/{branchId}/upload-status
{
  "branchId": "uuid",
  "uploads": [ { "type": "fixed-assets", "status": "done", "progressPct": 100,
                 "parsedRows": 12, "failedRows": 0, "failureReason": null,
                 "startedAt": "...", "finishedAt": "..." } ],
  "fixedAssets": true,
  "completionPct": 100
}
```

- **The branch upload had no plural alias.** Brands accept both `upload/` and `uploads/`; branches
  accepted only the singular, so a plural call 404'd. `POST /admin/branches/{branchId}/uploads/fixed-assets`
  now works too. If your build was calling the plural path, that alone explains «مش شغالة».

⚠️ **Breaking on `GET /admin/brands/{brandId}/upload-status`.** `completionPct` counted three steps
and ignored fixed assets, so a brand that had uploaded its assets got no credit and `uploads[]`
disagreed with the percentage. It now counts **four**, and `shared` gained a key:

```jsonc
"shared": { "sales": true, "materials": true, "suppliers": true, "fixedAssets": false },
"completionPct": 75   // was 100 for the same data
```

> Still open, not fixed this pass: the fixed-assets template ships an `اسم الفرع` column that its
> importer ignores, so those rows land with `branch_id: null`. Upload assets per branch to place them.

## 3. ✅ Branch manager — a real 500, and why it read as a network error

Three separate faults on one path.

**(a) The 500 itself.** `SupplierUserProvisioner` had guarded this since day one; the branch-manager
one never did. `asab_identity_map` carries `unique(entity_type, legacy_id)` — one dashboard login per
mobile row — and the branch-manager link was written with no check, so a legacy manager already
claimed by another user hit the index as an uncaught `QueryException`. Now a clean **422
`BRANCH_MANAGER_LOGIN_AMBIGUOUS`**.

**(b) Why the browser said «تعذر الاتصال بالخادم» instead of showing a server error.** An unhandled
exception fell through to Laravel's default 500, which is an **HTML** page — your error parser gets
invalid JSON and reports a transport failure. Every 500 under `/api/v1/*` is now the standard error
envelope:

```jsonc
{ "error": { "code": "SERVER_ERROR", "message": "...", "messageAr": "حدث خطأ غير متوقع في الخادم", "details": {} },
  "requestId": "req_..." }
```

`details` carries the exception class **only when `APP_DEBUG=true`** — never in production.

**(c) A cross-tenant hazard, now closed.** The provisioner matches an existing mobile manager **by
email**, then overwrote its `branch_id` and password. Nothing checked the company, so a colliding
email silently handed another tenant's manager to a new branch and reset their password. Now
**422 `BRANCH_MANAGER_IN_OTHER_COMPANY`**.

Also fixed: `DELETE /admin/users/{id}` now releases the identity link. It did not, so deleting a
branch manager and re-creating them would have hit (a) forever.

**New error codes to handle on this endpoint:**

| Code | Status | Meaning |
|---|---|---|
| `BRANCH_MANAGER_LOGIN_AMBIGUOUS` | 422 | Another user already owns that mobile login |
| `BRANCH_MANAGER_IN_OTHER_COMPANY` | 422 | The email belongs to a manager in another company |
| `SERVER_ERROR` | 500 | Now JSON — parse `error.code`, do not treat as a network failure |

`BRANCH_NOT_IN_COMPANY` is **unchanged** (we deliberately did not switch it to `VALIDATION_ERROR`,
per round 2 §6), but it now carries `error.details` naming the field — `companyId` when the user has
no company, `branches` when the branch belongs elsewhere. Highlight that input.

> **Check this first on your server.** `asab_identity_map` ships in a recent migration. If the
> deployment has not run `php artisan migrate`, every branch-manager create 500s on a missing table
> and no code change here helps.

## 6. ✅ Admin — the brand picker is a frontend requirement, not ours

`POST /admin/users` has **never** required brands, restaurants or branches for `role=admin`; only
`branch` (one branch) and `accountant` (≥1 brand) have scope rules. `{ name, email, role: "admin" }`
has always been accepted. **Remove the required flag and stop disabling «التالي».**

Hardened on our side to match: an admin's `brand_ids`/`restaurant_ids`/`branch_ids` are now forced
empty with `scope: "all"`. They were passed straight through, and because the tenant resolver ORs any
non-empty id array regardless of the scope string, a stray array from the wizard would have
**narrowed** an account that is meant to be unrestricted.

## Ops, fixed in this pass

- **`config/cors.php` published** — now covers `broadcasting/*`, so Pusher auth works cross-origin.
  Origins come from `CORS_ALLOWED_ORIGINS` (comma-separated), falling back to `FRONTEND_URL`, then `*`.
- **`config('app.frontend_url')` now exists.** It was read in eight notifications and declared
  nowhere, so every credential email shipped a host-less `"/login"` link. Setting `FRONTEND_URL`
  alone genuinely did nothing until now (round 2 §9).
