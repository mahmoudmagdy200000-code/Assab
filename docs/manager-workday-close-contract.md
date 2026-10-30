# Manager daily close — DC-01 through DC-04

Local repair scope authorized by Mahmoud on 2026-10-30. Business authority: Business Rules v3.5, 2026-10-21.

## Source and boundaries

Repair base: `mahmoudmagdy200000-code/Assab` candidate `038f100b3af792b158e1f958f217d4dcd78a7857`.
The reported deployed source `289fe09d15cc91bbe4d9877a51482b80abb4c7bf` is nine commits behind it. The candidate retains intervening tenant, report-review, and recipient security changes. No accepted baseline or integration branch is rewritten.

The historical HTTP 422 cause remains **UNCONFIRMED**: the historical POST body and selected workday ID were not retained. These corrections address independently reviewed source defects.

No migration, seed, fixture write to staging, publication, deployment, or production action is included.

## Canonical HTTP contract

| Operation | Selector |
|---|---|
| GET `/api/v1/branch-manager/workday/daily-close` | `manager_workday_id=<UUID>` pins an existing day |
| Initial selection through the same GET | `workday=today` or `workday=yesterday`, resolved against the server application timezone |
| Legacy GET compatibility | `shift_id=<UUID>` remains a read alias; conflicting selectors return 422 |
| POST `/api/v1/branch-manager/workday/end` | Body must contain `manager_workday_id` |
| PUT `/api/v1/branch-manager/workday/daily-close` | Body must contain `manager_workday_id` |

A selected/unknown/foreign ID never falls back to the server's current day. ID resolution includes exact manager and assigned branch ownership. The assigned branch supplies company authority; client company/tenant parameters do not grant access. Authorization and company identity are checked again on current rows inside the existing financial transaction and lock boundary.

GET returns `manager_workday_id`, `business_date`, `server_time`, `timezone`, and `workday_dates` alongside the existing response. GET selects existing records only; it does not create a workday. The app retains the returned identity for subsequent refresh and write requests, including across midnight.

**Compatibility:** ID-less writes are intentionally refused. The repaired Flutter client and backend require a coordinated release; retaining an implicit current-date fallback would retain the defect. `handover_timing` is a legacy presentation label, not workday ownership. `shift_date`/`handover_date` identify the source business date; `actual_end_time` and `handover_time` retain the actual server event time. No confirmed cash receipt timestamp is changed.

## Membership and financial invariants

- Summary and bulk updates use `ShiftDailyCloseScopeService`. Eligible carryovers remain eligible; a same-branch historical shift is not accepted without canonical membership.
- Existing owner/day/source lock ordering is retained through `ShiftReportReviewService::lockDay`. Consumption is read with a current locking read, bounded to this branch, so a MySQL REPEATABLE READ snapshot cannot conceal a competing committed consumption.
- Both explicit `cashier_shift_id` and the supported legacy unique `cashier_id` fallback resolve within the canonical set. If both IDs are provided, they must identify the same source. Duplicate targets are refused.
- Bulk updates, including no-ops, recheck manager/day authority and head finality. Real corrections retain revision, review, recount, reason, financial ownership, notification, and durable idempotency guards. The entire batch is atomic.
- An unchanged scalar app total is a no-op even with several apps. An unchanged explicit breakdown is also a no-op, independent of ordering. No cashier correction revision, liability charge, or correction notification is created for it.
- Real multi-app changes require an explicit validated `aggregators` array. Duplicate or foreign/disabled app targets, inconsistent totals, and missing allocation authority are refused. Same-total reallocation is a real correction. An unallocatable scalar change is never silently discarded.
- Per-app summary rows now include `cashier_shift_id`, notes, and a `present` flag, preserving separate rows for two shifts belonging to the same cashier and distinguishing a missing app row from an existing zero row.
- Gross sales remain VAT-inclusive and authoritative; app commissions are not deducted. Variance remains server-derived. The existing head review, return-cycle and pending-cash daily-submit business gates are retained.

## Verification

Executable coverage is in `ManagerWorkdayCloseContractTest`, `ManagerWorkdayMySqlSnapshotTest`, and the existing shift financial/security suites. The MySQL snapshot test requires `PHASE2_MYSQL_TESTS=1`, MySQL 8.4 on loopback and an owned disposable `s111_phase567_test_*` schema. The existing process-concurrency gate separately requires `S111_PHASE567_MYSQL_TESTS=1` on that disposable schema.

Never run RefreshDatabase or concurrency tests against staging. SQLite results do not establish MySQL row-lock safety. Consult the handoff's actual commands, logs and JUnit results for executed counts; this document does not claim acceptance or deployment.
