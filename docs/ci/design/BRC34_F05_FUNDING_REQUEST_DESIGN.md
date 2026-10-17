# F-05 — Funding request lifecycle (FUND-04, FUND-05, FUND-06; AC-57, AC-58, AC-59)

ASSUMPTION (needs Mohamed): the repository's `custody_requests` flow *is* the v3.4 "funding request" (طلب التغذية).

## AS-IS (Candidate c526a1f8)
* FUND-06 (approval does not raise the balance; receipt does, once): **already true** — `approveRequest` posts nothing,
  `confirmReceipt` posts one `custody_transactions` cash-in under a row lock + `received_at` guard. Previously only partly asserted.
* FUND-04: `approve`/`reject` accept `$user instanceof BrandOwner`; `BrandManager extends BrandOwner`, so owner and brand manager both
  decide; branch manager / cashier / accountant / head get 403. Already true, now asserted.
* FUND-05: `rejectRequest` is terminal; no resubmission endpoint (`reuseRequestId` creates a NEW request, not the same one). "Approve Anyways"
  does not exist (approve requires Pending) — now asserted.
* Defect found while testing: `GET /api/custody/requests/{id}` had no authorization — a manager of another branch could read
  a request's amount/purpose/attachments (REVIEWER_EXECUTED probe, HTTP 200). Unknown id returned 500.

## IMPLEMENTED (no migration)
* `POST /api/custody/requests/{id}/resubmit` (branch manager who raised it): Rejected → Pending, same id, no money moves. A retry is refused
  by the status check. The answered rejection (who/when/why) is written into the new «Submit Case» timeline row `notes` before the live
  rejection fields are cleared; every «Reject Case» row now stores its reason in `notes` → all cycles stay traceable. Same stage/status as the
  first submission, so existing clients' timeline event types are unchanged. Owners are notified (`requestAwaitingOwner`) after commit.
* `show`: a branch manager reads only requests addressed to / raised by themselves; other non-brand actors get 404; owners/brand managers unchanged;
  unknown id → 404 (was 500).

## NOT IMPLEMENTED / open decisions
1. **Tenant scoping of owner/brand-manager decisions and lists (F-09, P2, UNVERIFIED data risk).** Any brand owner or brand manager — even one with
   no brand link — can approve/reject/read ANY request, and the owner list returns every request. A fail-closed scope
   (`MobileBranchScopeService`, as EXP-06 does for expenses) would lock out owners whose identity-map link is missing in production data.
   Needs Mahmoud's decision + a data census first.
2. FUND-04 "per permissions": the repository has no permission flag for deciding funding requests; role = class membership only.
3. `reuseRequestId` still creates a new request (kept; it is a convenience, not a FUND-05 resubmission).
4. SQLITE_FK_DIVERGENCE: `custody_requests.approved_by/rejected_by` keep a branch_managers FK on SQLite only (migration 2026_05_17_000002 skips
   sqlite), so the new test defers FK checks; production (MySQL) has no such FK. Not verified against a real MySQL schema (NOT_RUN).
