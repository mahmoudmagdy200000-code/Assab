# BRC v3.4 correction task — findings closure (2026-10-17)

Role: independent architect / financial-systems reviewer acting as integration executor.
Session: https://claude.ai/code/session_013aLwkfhK97DL869QsTf11q
**Business acceptance (G7): NOT ACCEPTED** — it remains with the owner and is not claimable from this task.

Evidence labels: `REVIEWER_EXECUTED` (run in this task, SQLite in-memory), `SOURCE_INSPECTION_ONLY`,
`NOT_RUN`, `UNVERIFIED`. **No migration, seeder, dependency, deployment, Coolify, staging or production change
was made; no MySQL/MariaDB test was run (separate authorization required).**

## 1. What was integrated

| Package | Commit | Merge into Candidate | Scope |
|---|---|---|---|
| F-03 CASH-08 | `eec9d01d` | `28ab9c9f` | undecided inbound cash is not a surplus |
| F-01 EXP-01…08 | `235d1c72` | `c526a1f8` | expense custody charged at submission, reversed once at rejection |
| F-04 FUND-01/02 | `69658710` | `c9fe5217` | sales-cash → expense-custody transfer gate |
| F-05 FUND-04/05/06 | `9f781a40` | `96684584` | funding-request resubmission + read scoping |
| F-06 EXP-09 / OPS-10 / MEM / CASH-15 | `04e574b8`, `3511162d`, `d5da3057`, `e945a880` | `6285895e` | see §3 |

Method for every package: RED on the unmodified Candidate → minimal fix → GREEN → focused regressions → merged FULL SQLite.

## 2. Closure table

Status vocabulary: FIXED · VERIFIED (executed on SQLite) · PARTIAL · BLOCKED · NOT IMPLEMENTED.

| ID | Pri | Status | RED → GREEN (SQLite) | AC effect | Remaining |
|---|---|---|---|---|---|
| F-03 CASH-08 | P1 | **FIXED + VERIFIED (server side)** | 17 tests: 15 failed → 17 passed | AC-13 PARTIAL | the optional `pending_incoming_cash` declaration must be sent by the mobile client; without it the server cannot tell the drawer cash is the incoming cash and keeps the previous behaviour (no guessing). Client adoption UNVERIFIED (Flutter not in scope). |
| F-01 EXP-01…08 | P1 | **FIXED + VERIFIED** | 24 tests: 16 failed → 31 passed | AC-27/28/29 COVERED; AC-30, AC-31, AC-39 PARTIAL | AC-30: no endpoint edits a rejected expense (corrected amount applied on the record). AC-31: simultaneous decisions `NOT_RUN` (MySQL). Scope assumption: only `payment_method='custody'` is charged; pre-approvals charged like other custody-paid expenses. |
| F-04 FUND-01/02 | P2 | **FIXED + VERIFIED** | 15 tests: 12 failed → 15 passed | AC-53/54/55/56 COVERED | two simultaneous transfers `NOT_RUN` (MySQL). Assumption: "source day fully approved" = every member report Operation of the workday is final-approved. Deliberate change of regression `ng09_branch_manager_transfer_regression` (a bare seeded balance is no longer transferable). |
| F-05 FUND-04/05/06 | P3→P2 | **PARTIAL** | 10 tests: 4 failed → 11 passed | AC-59 COVERED; AC-57, AC-58 PARTIAL | `custody_requests` = funding request is an ASSUMPTION; **F-09** (below) open; no per-permission model. |
| F-02 CASH-14 | P2 | **NOT IMPLEMENTED — BLOCKED** | — | AC-20/48/49/50 | needs a persisted reversal link to the original receipt (schema) + the decision whether the flag-gated additive settlement is retired or re-specified. |
| F-06 EXP-09 | P2 | **PARTIAL** (negative half FIXED) | 12 tests: 10 failed → 12 passed | AC-38, AC-39 PARTIAL; AC-51/52 NOT IMPLEMENTED | grant/withdraw needs a persisted per-cashier delegation flag (schema). |
| F-06 OPS-10 | P2 | **VERIFIED (characterization)** | no RED state (already conforms); mutation check: 2/6 cases fail when an implicit grant is injected | AC-60 COVERED | — |
| F-06 CASH-15 | P2 | **PARTIAL (pinned)** | no RED state (already conforms) | AC-33 PARTIAL | the manager-ledger label for a receipt credit is the enum-constrained legacy `Total Sales` → business ruling. |
| F-06 CASH-16 | P2 | **NOT IMPLEMENTED — BLOCKED** | — | AC-40/41 | the repository has no "repayment of a debt that is not shift cash" operation (no request primitive, no receipt↔debt link; legacy manual handover retired). |
| F-06 MEM-01…06 | P2 | **PARTIAL** — one defect FIXED | 6 tests: 1 error → 6 passed | AC-68 COVERED; AC-62/63/64/65/67/69 PARTIAL; AC-66 NOT IMPLEMENTED | MEM-04 follow-up assignment workflow and MEM-05 senior-manager notification are NOT IMPLEMENTED. |
| F-07 | P3 | unchanged | — | AC-06/08/15/24/25/43/44/45/46/47 | missing assertions only; AC-46 sub-halala policy is a business decision (AS-IS rejects >2 decimals; the new transfer endpoint answers 422). |
| F-08 | INFO | unchanged | — | — | — |

## 3. Defects discovered while implementing (not in the original F-list)

| ID | Pri | Status | Evidence | Detail |
|---|---|---|---|---|
| N-01 | P2 | **FIXED** (`d5da3057`) | `REVIEWER_EXECUTED` RED | A pending handover of an **archived** (soft-deleted) cashier could never be confirmed: `ShiftCashAvailabilityService::lockCashierOwners` threw `ModelNotFoundException`, and after that `ShiftTransferReceiptService::recordReceipt` read the sender through a relation that hides archived rows → misleading 409 `RECEIVING_MANAGER_REQUIRED`. Cash physically handed over was stranded. Fix: `withTrashed()` on the owner lock and on the sender read (2 files, +8/−3). |
| N-02 | P3 | **FIXED** (`04e574b8`) | `REVIEWER_EXECUTED` probe | A cashier reaching the expense writers failed incidentally with 400 / **500 (FK violation on `expenses.branch_manager_id`)** / 422, because every writer stamps `auth()->id()` as `branch_manager_id`. Now an explicit 403 `EXPENSE_DELEGATION_REQUIRED` before any validation or write. |
| F-09 | P2 | **OPEN** (not changed) | `SOURCE_INSPECTION_ONLY` + probe in F-05 note | Any brand owner / brand manager — even one with no brand link — can approve/reject/read ANY custody request, and the owner list returns every request. A fail-closed scope would lock out owners whose identity-map link is missing in production data → needs a data census and Mahmoud's decision. |
| N-04 | P3 | **OPEN** (not changed, out of the AC list) | `REVIEWER_EXECUTED` probe | A cashier can **create an expense category** (`POST /api/branch-manager/expenses/categories` → 200, row written); category update/delete sit behind the same middleware with no role check. Minimum correction (not applied): the same one-line route middleware used for EXP-09 on the category write routes, or an owner/manager role check. |
| N-05 | INFO | **OPEN** | `SOURCE_INSPECTION_ONLY` | `CashierObserver::deleting` refuses archiving a cashier with an active shift, while the approved M05 text says open shifts do not block termination. Deactivation (which stops access) is allowed, so access can always be stopped; archive-with-open-shift is a business decision. |

## 4. Verification summary

| Item | Result |
|---|---|
| Final merged candidate `6285895e` — FULL SQLite (ParaTest ×4, nothing else running) | **2520 tests, 14357 assertions, 74 skipped, 0 failures, 0 errors** (17 min 46 s) — `REVIEWER_EXECUTED` |
| Earlier merged state `c526a1f8` — FULL SQLite | 2468 tests / 14001 assertions / 74 skipped / 0 failures — and GitHub FAST + FULL success on that exact SHA (run 39001610108) |
| F-04 package-only FULL | 2481 passed + **2 timing-only NFR failures** (`LoadTest::test_response_time_consistency`, `ResponseTimeTest::test_multiple_endpoints_percentile_response_time`) caused by self-inflicted CPU contention (other suites ran concurrently); both pass in isolation (7/7 and 7/7). Superseded by the clean merged FULL above. |
| Skips | 74 = 73 `DB_CONCURRENCY_OPT_IN` (need a real MySQL-family server) + 1 `SQLITE_DRIVER_LIMITATION` — all **NOT_RUN**, never counted as passes |
| Source guard | no migration, seeder, dependency, deployment, secret or staging-workflow path touched |

## 5. Remaining decisions / blockers (nothing below was guessed)

1. **Schema authorization** (single question): may additive schema changes be authorized — and the source-guard policy
   (`deny database/migrations`) relaxed for them — for (a) a per-cashier expense-delegation flag (EXP-09: AC-38/51/52),
   (b) a reversal link to the original receipt (CASH-14: AC-20/48/49/50), (c) a receipt↔debt link for CASH-16 (AC-40/41)?
   Without it these stay NOT IMPLEMENTED.
2. Mohamed: definition of "source day fully approved" (FUND-01); whether `custody_requests` is the funding request (FUND-04/05/06);
   whether `cash` / `supplier` expenses must also be charged to the branch custody (EXP-01); whether a pre-approval request should reserve custody;
   how a CASH-16 repayment is initiated; the manager-ledger label for a receipt credit (CASH-15); archive-with-open-shift (M05).
3. Mahmoud: F-09 tenant scoping of owner decisions (needs a data census); N-04 category write authorization; whether the flag-gated additive settlement is retired (F-02).
4. AC-46 rounding policy for sub-halala input (AS-IS rejects).
5. MySQL 8.4 / concurrency gate: `NOT_RUN`; proposal in `MYSQL84_CONCURRENCY_GATE_PROPOSAL.md` (§7 lists the new concurrency-sensitive code added by this task).
