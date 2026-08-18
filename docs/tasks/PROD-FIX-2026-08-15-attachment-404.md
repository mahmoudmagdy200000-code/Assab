# 2026-08-15 — invoice attachments return 404 on the live server

Reported from the live test: the dashboard reads
`GET /api/v1/operations/{id}` → `expenses.invoices[].attachments[].publicUrl`
and the URL 404s.

```
https://ivory-snail-183262.hostingersite.com//storage/expenses/attachments/expense_….jpg
                                            ↑↑
```

## Two separate defects, both now fixed in code

### A. The doubled slash (`//storage`)

`config/filesystems.php` built the public disk's URL as `env('APP_URL').'/storage'`.
An `APP_URL` with a trailing slash produced `https://host//storage/…`, which
Hostinger 404s outright (the page returned is Hostinger's own 404, not Laravel's
— the request never reached PHP).

The base is now `rtrim(…, '/')`, so a trailing slash in the env can no longer
produce it, and `App\Support\PublicUrl` collapses any residual duplicate slash
in the path (never in the `https://` scheme).

### B. The missing document-root segment

This project's `public` disk root is **`public_path('storage')`** — uploads are
written straight into `public/storage`. There is **no symlink** to
`storage/app/public`.

> **Never run `php artisan storage:link --force` on this project.** It replaces
> the real `public/storage` directory with a symlink and destroys every uploaded
> receipt. Plain `storage:link` is a no-op.

On this host the document root is the project root, so the same file is served
at `/public/storage/…` and 404s at `/storage/…` (verified 2026-07-31, and again
now). The fix is one env var:

```dotenv
# The browsable prefix for uploaded files. Set this — NOT APP_URL, which also
# drives password-reset and signed-route links.
ASSET_URL=https://ivory-snail-183262.hostingersite.com/public
```

then `php artisan config:clear`.

`ASSET_URL` was chosen deliberately: `asset('storage/…')` (the mobile modules'
images — branch logos, avatars, timeline photos) and
`Storage::disk('public')->url()` (dashboard attachments) both derive from it
now, so one variable fixes both worlds. Setting only one of them fixes half the
app. `FILESYSTEM_PUBLIC_URL` remains available as an override if uploads later
move to a CDN/S3 host of their own.

> `.env.example` could not be edited from this session (tooling permission).
> Add these two lines by hand:
> `ASSET_URL=` and `FILESYSTEM_PUBLIC_URL=`.

## No data backfill is needed

The absolute URL used to be **baked into the database** — `asab_attachments.public_url`
and the operation's `payload.invoices[].attachments[].publicUrl` — at upload
time. That is why the historical rows could not be repaired by fixing the config.

Both are now **derived from the storage key on read**:

- `Attachment::publicUrl` is an accessor over `storage_key`; the stored column is
  never replayed verbatim (a row with no key keeps its external URL, normalised).
- `ExpenseInvoiceService::document()` re-derives each payload attachment's URL
  from its `storageKey`.

So changing `ASSET_URL` repairs every historical attachment at once.

## Verifying on the server

```bash
php artisan config:clear
php artisan asab:storage-doctor --sample=3
```

It prints the resolved prefix, checks the disk root exists and is a real
directory (not a symlink), and for the most recent attachments of **both**
worlds (`asab_attachments` + `expense_attachments`) prints the expected file
path, whether the file is physically present, and the URL the API now serves.

- **file MISSING** → the upload never landed (or was lost in a deploy); the
  config is not the problem.
- **file present + URL 404s** → the web server does not serve that prefix; set
  `ASSET_URL` to the prefix that is reachable.
- **200** → done; the dashboard modal renders it with no FE change.

If the SPA is deployed at the same document root, confirm its rewrite rule
serves `/storage/*` (or `/public/storage/*`) as static files **before** falling
through to `index.html`.

## Files

- `app/Support/PublicUrl.php` (new) — the one place a stored file becomes a URL.
- `config/filesystems.php` — rtrim'd base, derived from `ASSET_URL`, plus the
  `storage:link --force` warning.
- `Modules/Admin/app/Models/Attachment.php` — `public_url` derived on read.
- `Modules/Admin/app/Services/ExpenseInvoiceService.php` — payload URLs re-derived.
- `Modules/Admin/app/Services/ExpenseBridgeService.php`,
  `OperationAttachmentService`, `Shared/UploadController`, `Shared/ReportController`,
  `GenerateDataExportJob`, `BrandOwnerAssetOverviewService`,
  `Expense/…/ExpenseAttachmentController` — emit through `PublicUrl`.
- `Modules/Admin/app/Console/Commands/StorageDoctorCommand.php` (new).
- `tests/Feature/AttachmentPublicUrlTest.php` (new).
