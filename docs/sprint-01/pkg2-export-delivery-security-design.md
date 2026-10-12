# IR-PKG2-01 — Report export delivery security (DESIGN ONLY, not implemented)

Status: **CONFIRMED (P1) · design-only.** The fix is a storage + download-architecture change (and touches already-generated files), which is outside the authorized "minimal local" scope and must be approved before implementation. No production storage was touched.

## The gap (verified against source)

- `ReportFileExportService::writeCsv()/writePdf()` write with `Storage::disk('public')->put(...)` → `public_path('storage')`, visibility `public` (`config/filesystems.php`).
- `BrandOwnerReportExport::getDownloadUrlAttribute()` returns `asset('storage/'.$file_path)` — a **static public URL**. There is **no download route** and therefore **no per-download authorization middleware**.
- `BrandOwnerReportsService::getReportsAndAnalytics()` returns the history for `brand_owner_id = $owner->getKey()` (the creator) with that `download_url`, with **no** current-brand-scope or revocation check. A-1 did not modify this method.
- Filename: `<kind>_report_<Ymd_His>_<Str::random(6)>.<ext>` — timestamp + 6 random chars; unguessable-ish but permanent and unauthenticated.
- `brand_owner_report_exports` has no brand/company column — only `brand_owner_id`, `report_kind`, `format`, `title`, `file_path`, `params`.

**Consequence:** a file exported before A-1 (global, cross-tenant content) stays downloadable by its URL; a file exported while a brand was assigned stays downloadable after revocation. Delivery bypasses application authorization entirely.

**Exploitability (honest, corrected per FU-04):** the URL is *initially disclosed* only to the authenticated creator via the reports endpoint, but it is an **unauthenticated bearer link** — once issued it can be forwarded, captured in logs/proxies/referrers, or otherwise disclosed, and it keeps working with no access control. The 6 random filename characters reduce blind guessing but are **not** authorization. So this is **retention/confidentiality** (a former-authorized user keeps a working link; old files hold cross-tenant figures); there is no evidence of anonymous arbitrary-tenant enumeration. Severity **P1** (defensible without enumeration), not P0. Negative controls documenting the gap: `tests/Feature/ExpenseExportSecurityTest.php::test_negctrl_*` (file on public disk; `download_url` is an unauthenticated `/storage/` URL with no signature; history still returns the URL after the owner is fully unlinked).

## Why no in-scope code fix was shipped

A half-measure at the API layer (e.g. hiding history rows from an unlinked actor) does **not** close the exposure — the file remains at a permanent public URL that a saved link still reaches. Shipping it would risk being mistaken for a real fix. The only real fix changes where files are stored and how they are delivered, which the authorization explicitly gates ("storage migration, production cleanup, or a broad download-architecture change → provide an explicit design and rollout plan before making that portion").

## Proposed design (for approval)

1. **Private storage for new exports.** Write report files to the `local` (private) disk (`storage/app/private`), visibility `private` — never `public`.
2. **Authenticated, scope-checked download route.** `GET /api/[v1/]brand-owner/reports/exports/{export}/download` behind `auth:sanctum` + `branch.manager.or.brand.owner`, that:
   - loads the `BrandOwnerReportExport`, 404 uniform if not the caller's own;
   - re-authorises against the caller's **current** brand scope at download time (so revocation takes effect) — which requires persisting the export's brand/branch scope on the row (new nullable `brand_ids`/`branch_ids` column, backfilled null = legacy);
   - streams the file from the private disk; the model's `download_url` becomes this route, not an `asset()` URL.
3. **Legacy public files (rollout, needs approval).** Inventory `public_path('storage/brand-owner/reports')` and the `brand_owner_report_exports` rows read-only; quarantine/move legacy files off the public path (or invalidate their rows) under a dedicated, reversible operation — **no deletion or rewrite without separate permission**.
4. **Tests to add with the fix:** legacy global export not reachable; revoked brand → 403/404; unlinked owner → none; another tenant's export id → 404; index + download for both expense and custody; signed/stream path never public.

## Dependencies / impact
- New route + controller method + request authorization (PKG-2 reports area — in scope once approved).
- New nullable scope column on `brand_owner_report_exports` (additive migration) + backfill policy for legacy rows.
- Storage migration of existing public files (production operation) — **separate approval**.
- Clients (Flutter/Dashboard) must call the new download route instead of using a raw public URL — consumer change.
