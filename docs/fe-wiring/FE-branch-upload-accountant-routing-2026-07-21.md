# FE note — branch-manager daily upload → accountant routing (verified + gaps closed)

> Backend change 2026-07-21 · `Modules/Admin/app/Http/Controllers/Company/BranchCompanyController.php` (`upload`), `Modules/Admin/app/Http/Controllers/Branch/BranchDashboardController.php` (`uploadStatus`), new `Modules/Admin/app/Services/BranchDailyReportsService.php`, `Modules/Admin/app/Services/OperationService.php` (`approve`) · covered by `tests/Feature/BranchManagerT12Test.php`. Full contract: `FE-T12-branch-manager.md` §1–2.

You asked us to verify the branch-manager upload flow end to end (scope from token, create pending Operation, route to the accountant, `upload/status` shape, and the reverse direction after review). Here's what we found and what we changed.

## What already worked
- Branch scope was already token-derived (never a request param) on every endpoint in this group.
- `POST /company/me/branch/upload` already created a `pending` Operation, wrote the ApprovalStep audit row, and notified the company's `accountant` role.
- The accountant's operations list (`GET /accountant/operations`) already includes these rows with no extra wiring — it has no channel/origin filter and no default status filter, so a `channel:"dashboard"`, `origin:"mobile"`, `status:"pending"` upload on a branch inside the accountant's scope appears automatically.
- Accountant **reject** already notified the submitting branch manager.

## What was missing or wrong (now fixed)
1. **`GET /company/me/branch/upload/status` didn't exist.** Only the platform surface (`/branch/upload/status`) had it. Added the canonical route — same handler, same shape.
2. **The checklist was the wrong 6 reports, duplicated in two places.** Both copies listed `sales/inventory/cash/waste/purchases/expenses` with no `description`/`lastUpload`/`todayDeadline` and a `lastStatus` that only ever read `success`/`missing` (never `late`). Replaced both with one shared `BranchDailyReportsService` producing the exact 4-report checklist (`sales|expenses|inventory|shift-close`) with full fields.
3. **`kpis.requiredReportsCount` was hardcoded to `6`.** Now computed as `required && !uploadedToday` over the same checklist the status endpoint returns — the two screens can no longer disagree.
4. **`reportType=sales` with no `salesHalalas` silently stored a 0-amount operation.** Now `422 INVALID_INPUT`.
5. **`shift-close` wasn't an accepted `reportType`** on the company surface (only the 6 legacy module keys were). Added — it maps internally to the `shifts` module.
6. **Company upload's `status` field always said `"success"`**, even though the Operation was created `pending`. It now mirrors the Operation's real pipeline status (`pending`), so the client doesn't show a false "done" state before the accountant has looked at it.
7. **Accountant approve sent no notification to the branch manager** (only reject did). Added: approve now also `push`es the submitter (`type: "operation.approved"`).
8. **A same-day rejection didn't reopen the checklist.** `uploadedToday` now only counts a submission still alive (`pending`/`approved`/`final-approved`); a rejection flips it back to `false` and `lastStatus` to `late`, so the branch sees it needs re-filing instead of a stale green check.

## Net effect for the wizard / upload screen
- Poll `GET /company/me/branch/upload/status` for the 4-card checklist; it's now real and complete (`description`, `lastUpload`, `todayDeadline` included).
- `overview.kpis.requiredReportsCount` and `overview.requiredReports` are the same data as `upload/status` — no divergence to reconcile.
- After a submit, show the created report as **pending** (not "uploaded ✅") until either a push notification (`operation.approved` / `operation.rejected`) arrives or the next `upload/status` poll confirms it.
- A rejection notification's body carries the accountant's Arabic reason — safe to show verbatim in a toast/banner.

No route paths, request field names, or response envelope shapes changed from what `FE-T12-branch-manager.md` already documented — this is a correctness/completeness pass on that same contract, not a breaking change.
