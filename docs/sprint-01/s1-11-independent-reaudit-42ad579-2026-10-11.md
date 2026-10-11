# Assab ERP — Independent S1-11 Re-Audit (Correction Pass)

**Date:** 2026-10-11 (Cairo)  
**Reviewer mandate:** Read-only, independent source-and-evidence re-audit; no code changes, branch updates, merge, or acceptance on behalf of Mahmoud.  
**Business authority:** Assab Business Rules v3.2 (2026-10-11, Mohamed S11).  
**Outcome:** **NEEDS CORRECTION** — F-1..F-4 are addressed in committed source/docs, subject to independently executed regression confirmation; the previously documented OG-06 is a demonstrable legacy correction dead-end and requires resolution or an explicit, evidence-based owner-approved deferral. **No production release approval.**

## 1. Frozen target and provenance

| Field | Verified state |
|---|---|
| Repository | `mohameelsherbini/Assab` (fork) |
| Task branch | `task/s1-11-corrections-and-liability` |
| Parent audited SHA | `f62a4ba96c5fb1ada5dc648f24e3edc28b163739` |
| Re-audit SHA | `42ad579e90ce0ebb1cefbdb530ac0e693b6bd3cd` |
| Remote branch comparison | SHA `42ad579e` vs task branch = `identical` when checked |
| Delta | One commit, 15 files: 8 existing test files, 1 new test helper and 6 documentation files (API contract, task register, four named reports) |
| Application code changed in this delta | **None** |
| Fork Foundation | `00348fa46ab82a5f4951fee6780f78d04cd6880c` (unchanged when checked) |
| Fork main | `18ec7e35f3c6d37e5b199a1b2812b5086b43e1b3` (unchanged when checked) |
| GitHub CI for reviewed SHA | No combined-status contexts or PR-triggered workflow runs surfaced by the accessible GitHub tools; this does not establish all CI is absent |
| Scope | Recheck prior F-1..F-4 and assess explicit newly recorded OG-06; not the later full S1-01..S1-11 business-rules audit |

**Commit:** https://github.com/mohameelsherbini/Assab/commit/42ad579e90ce0ebb1cefbdb530ac0e693b6bd3cd  
**Compare:** https://github.com/mohameelsherbini/Assab/compare/f62a4ba96c5fb1ada5dc648f24e3edc28b163739...42ad579e90ce0ebb1cefbdb530ac0e693b6bd3cd

### Evidence levels

- **GITHUB_SOURCE_VERIFIED:** GitHub commit file patches and exact-SHA source files retrieved/read during this review.
- **DEVELOPER_REPORTED:** PHP 8.3.6 / PHPUnit 12.4.0 / ParaTest / SQLite `:memory:`; full suite claimed exit 0, **1,890 tests, 10,666 assertions, 0 failures, 0 errors, 33 skipped**; focused test results also reported.
- **INDEPENDENT_RUNTIME_NOT_RUN:** No full checked-out Laravel repository, vendor dependencies, isolated test database, or independent CI test output available in this review environment. GitHub code retrieval permitted source checks, not PHP/Laravel suite execution. Thus the reported green outcome **is not independently verified**.
- **MYSQL_NOT_RUN:** Current correction pass reports 32 MySQL-only transactional/concurrency tests not run. The independent audit also did not run MySQL. This remains a release/production gate; do not treat prior-SHA tests as current-SHA evidence.
- The developer report labels its own executions “REVIEWER_EXECUTED-equivalent”; for acceptance they are **DEVELOPER_EXECUTED / AUTHOR_REPORTED**, not independent execution.

## 2. Findings F-1 to F-4 — re-audit matrix

| Finding | Source/doc recheck | Result |
|---|---|---|
| F-1 — stale test failures introduced by `f62a4ba` | Existing test patches replace old manager-review expectation; supply reject → return → sender-confirm → edit → re-present flows; update legacy correction status; retain assertions of receipts, histories and current attempt. No tests deleted in delta. | **SOURCE/DOC CORRECTED.** Author-reported green full suite; **independent runtime NOT_RUN**. |
| F-2 — two already-red reassignment tests | Both fixtures now insert cashier `Total Sales` custody rows (30 SAR, 100 SAR) matching their artificial report amounts. Production `ShiftEndService` creates/updates those rows from recorded cash collected (`ShiftEndService.php:96-129`), while `ShiftCashAvailabilityService::assertCashierReservation()` disallows unfunded transfers (`ShiftCashAvailabilityService.php:51-101`). Root cause is credible. | **SOURCE/DOC CORRECTED.** No app-code change indicated; independent runtime NOT_RUN. |
| F-3 — undocumented public API contract changes | `docs/sprint-01/api-contract.md` documents `manager_review_required=false`, approved accountant allocation, `PHYSICAL_RETURN_REQUIRED`, current attempt ID, `PENDING_TRANSFER_EVIDENCE_INCOMPLETE`, `TRANSFER_RECEIPT_STATE_MISMATCH`, `PHYSICAL_REJECTION_DETAILS_REQUIRED`, legacy status-in-place change, and client integration notes. | **CLOSED for required documentation.** Flutter/Dashboard integration still NOT_RUN (OG-08). |
| F-4 — missing final handoff / traceability / evidence / gaps | All four requested markdown documents are published at exact SHA; v3.2 NEW-01..08 coverage/gaps classified, OG-01..OG-09 listed, developer does not claim acceptance. | **CLOSED for publication/coverage.** Deferral classification is **not** Mahmoud's acceptance or proof of production safety. |

### Test-design observations (not independently demonstrated runtime defects)

1. `tests/Concerns/ConfirmsPhysicalReturn.php` uses real production `ShiftTransferAttemptService` to create/return attempts, not direct evidence-row inserts; this is preferable to fabricating ledger state. Some regression tests combine service-layer presentation/return with HTTP rejection/acceptance, so those particular tests are not a fully HTTP-driven E2E guarantee. Other tests supply public-path coverage. **Suggested P2 hardening:** explicitly assert nonzero rejected retained cash, confirmed return evidence, and current attempt identity in the helper/callers.
2. `ShiftLegacyFinalRejectionCorrectionTest` no longer performs the intermediate count-absent rejection; the negative `REPORT_COUNT_REQUIRED` remains in `ShiftTransferReceiptTest::test_handover_receipt_requires_cash_count_for_current_revision`. This is a coverage relocation rather than silent deletion, but the downstream reviewer should run both classes.
3. The `test_accountant_split_of_a_counted_shortage_is_refused` method name was retained despite its new expected successful accountant-final allocation; consider renaming for audit clarity. Not a functional blocker.

## 3. Additional verified finding: legacy D11 correction dead-end

**Finding ID:** `RA-42-01` (previously disclosed by developer as `OG-06`)  
**Severity:** **P1 — conditional acceptance blocker until adjudicated**. Safe failure prevents unverified cash movement, but can strand historically rejected requests.  
**Task:** S1-11 — handover correction / legacy compatibility  
**SHA:** `42ad579e90ce0ebb1cefbdb530ac0e693b6bd3cd` (unchanged from parent in this patch)  
**Business rules:** v3.2 `CASH-11`, `CASH-12`, `COR-01`, `COR-02` (correct the same rejected request after physical return, without permanent attempt-count lockout).  
**Evidence:**

- `Modules/Shift/app/Services/HandoverService.php:675-690`: a rejected handover with **no** `current_transfer_attempt_id` but a `ShiftTransferRejectionEvidence` row throws `PHYSICAL_RETURN_REQUIRED` on `recordHandoverEdit`.
- `Modules/Shift/app/Services/ShiftTransferAttemptService.php:49-55`: when **no** current attempt exists but historical rejection evidence exists, `present` throws `LEGACY_ATTEMPT_IDENTITY_UNAVAILABLE`.
- `Modules/Shift/app/Services/ShiftTransferAttemptService.php:84-101`: initiating a physical return is keyed to an **existing attempt ID** and its attempt-bound rejection evidence.
- Published `docs/sprint-01/s1-11-open-gaps.md`, `OG-06`, independently corroborates the source-level dead-end.

**Reproduction (source-derived / not runtime executed):** Create or identify an eligible historical rejected cash handover with D11 rejection evidence, `current_transfer_attempt_id = NULL`, no receipt, and a valid actor. Call the existing `/handover/edit` route → `PHYSICAL_RETURN_REQUIRED`. Try to create a first tracked presentation for the request via `ShiftTransferAttemptService::present` → `LEGACY_ATTEMPT_IDENTITY_UNAVAILABLE`. Return path cannot be called without an attempt.  
**Expected:** Legitimate legacy request is either safely correctable after verifiable physical return, or explicitly migrated/closed through an approved audited recovery path preserving provenance and no duplicate financial effects.  
**Actual:** The two normal routes are independently rejected, leaving the documented state without an approved correction path.  
**Required correction/decision:** Developer must (a) run a **read-only, scoped data census** for historically rejected D11 rows without attempts in the actual target schema; (b) show whether the state is reachable and affects actual existing records; (c) if present, propose and test a narrowly scoped historical recovery/backfill or authorized audited manual remediation **without inventing cash-return evidence**; (d) cover no-double-posting, correct actor, tenant/branch scope, idempotency and immutable history; (e) if absent and provably unreachable, submit evidence for Mahmoud's explicit written defer/acceptance decision. Do not open the generic settlement writer or synthesize physical receipts.

**Risk qualification:** Whether impacted legacy rows exist in production is **NOT VERIFIED**. Therefore the existence and safety of the dead-end is demonstrated by source, but its incidence and urgency are conditional; do not claim production data corruption or actual affected users without data evidence.

## 4. Evidence qualification — re-audit runtime gate

**Finding ID:** `RA-42-02`  
**Severity:** P1 verification gate / P2 documentation hygiene  
**Task:** S1-11 independent acceptance evidence  
**SHA:** `42ad579e90ce0ebb1cefbdb530ac0e693b6bd3cd`  
**Evidence:** `docs/sprint-01/s1-11-final-test-evidence.md` provides a full-suite command, counts and temporary `/tmp/full-corr.xml` path, but no JUnit artifact or runner output is supplied to the auditor. GitHub status and available PR-run lookup return empty for this SHA.  
**Expected:** Independently reproducible green regression evidence tied to exact immutable SHA; known skipped/NOT_RUN distinctions.  
**Actual:** Author's results are reported; no independent test execution or accessible CI logs confirmed them in this session.  
**Required action:** Re-run at the exact SHA in a separate, clean execution environment and attach full command, exit, test/skip totals and reproducible JUnit or job logs. Include focused tests for F-1/F-2/OG-06. MySQL transactional/concurrency on disposable isolated MySQL remains an explicit release gate. In the published evidence document, relabel “REVIEWER_EXECUTED-equivalent” as `DEVELOPER_EXECUTED` / `AUTHOR_REPORTED` for clarity.

**This does not claim the author's reported results are false.** It limits the evidence strength for independent acceptance.

## 5. v3.2 gaps retained (not auto-approved)

- OG-01/02: authorized cashier expenses / connection to branch expense custody (NEW-03/04) — absent/partial.
- OG-03: old liability repayment through manager handover (NEW-05) — absent.
- OG-04: Head-of-Accounts full accountant→manager→accountant→Head return choreography (NEW-06) — partial.
- OG-05: linked reversal of erroneously confirmed 48→480 receipt (NEW-07) — disabled/fail-closed, not a generally authorized settlement writer.
- OG-06: above conditional legacy dead-end; **a source-demonstrated defect, not merely a missing optional feature**.
- OG-07: current MySQL/concurrency and production schema gate — NOT_RUN.
- OG-08: Flutter/Dashboard consumer adaptation — NOT_RUN.
- OG-09: stale pinned project instructions — governance.

These are traceable current-state findings; accept/defer scope only via Mahmoud. This re-audit does **not** certify all 46 v3.2 business acceptance scenarios.

## 6. Decision and exact next actions

**Formal verdict for S1-11 at `42ad579e`: NEEDS CORRECTION (or explicit evidence-based owner adjudication) — NOT ACCEPTED.**

1. Fix/adjudicate `RA-42-01 / OG-06` with a data census + narrowly scoped recoverability plan or evidence proving no affected state, followed by explicit Mahmoud deferral if appropriate.
2. Obtain a separate independent clean checkout regression execution at `42ad579e` (or its next correction SHA). Verify targeted suites plus full SQLite result; attach raw logs. If code changes, rerun affected tests and concurrency.
3. Correct evidence labeling and retain JUnit logs where possible; do not equate the author's session with independent test execution.
4. Decide and document carry-over acceptance for OG-01..05/07..09; do not silently expand S1-11 into the later comprehensive audit or Flutter work.
5. Only after a qualifying independent PASS and Mahmoud's explicit acceptance may the accepted SHA fast-forward into fork `sprint/01-financial-foundation`; `main` and upstream remain unchanged until later integration gates. Then S1-12 can start in its independent branch while the full S1-01..S1-11 v3.2 audit uses that frozen Foundation SHA.

**No commits, pushes, merges or project-source modifications were performed during this re-audit.**
