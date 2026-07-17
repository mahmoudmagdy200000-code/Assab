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
