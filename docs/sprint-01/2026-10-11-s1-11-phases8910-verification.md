# S1-11 AUD-05 and Phases 8–10 — Final Corrective Verification

Date: 2026-10-11. Target remote branch: `task/s1-11-corrections-and-liability`.

## Source, recovery and authority

- Required previous remote SHA: `b854e37044d1b81a08faafbc0e9e6ac700fd006a`.
- AUD-05 corrective commit: `72301848df291c78cc3ddaad09aef294757e24a6`, directly descended from that baseline. Phases 1–7 published corrections are preserved by ancestry.
- Existing Antigravity Phases 8–10 work was preserved in 34 SHA256-verified intake copies and restored above AUD-05 in an isolated task worktree before repair. No reset, force push, overwrite or Foundation/main/upstream merge.
- Mahmoud's latest user-relayed instruction authorizes completing through Phase 10 and ordinary fork publication. It supersedes the earlier no-Phase-8 sequencing. It does not establish formal final acceptance or settlement financial authority.
- The primary checkout's original staged/dirty work was preserved. Verification and publication use the isolated `s111-audit-1-7` checkout, not the unrelated original autoloader/app. Its dependencies, runtime wrappers, database credentials, logs and recovery copies are not part of the commit.

## Correction scope

AUD-05A/B/C: discover only live proven historical shortages on first reconciliation; preserve already-linked immutable history after later soft-delete; do not map manager custody mirrors or cashier surplus to shortage liability; fail closed on ambiguous replacements. No historical ledger rows or existing legacy links are changed.

F01–F13: required daily key/revision and atomic bridge rollback; count/receipt/company evidence required; branch/day/current carryover membership; replacement chain and historical receipt provenance; scoped settlement reads; integer physical cash/reservation mutex; default-disabled settlement writer; ordinary revision-aware liability commands and authenticated Employee response; canonical service operation replay; actual workflow fixtures; real bounded MySQL concurrency/populated upgrade; monetary maximum and exact SAR persistence.

Final independent source review found four additional integration issues. Each received a failing regression before the fix: nonzero corrected allocation number/evidence UUID binding, spendable cash exceeding observed count, unfunded cashier reservations after settlement, and public bulk owner/workday lock inversion. An additional consumed same-day foreign workday membership edge was reproduced with a real first daily submit and fixed. Permanent methods and scope are in the [finding register](2026-10-11-s1-11-phases8910-findings.md).

Zero-shortage corrections require manager acknowledgment; they are not auto-approved. Nonzero corrected allocations remain usable for responsible-party response and daily submit after current review. Old posted liability remains until replacement approval, then only the delta is posted.

Machine-readable run summaries, retained JUnit digests and the 51 PHP source fingerprints are in [verification evidence](2026-10-11-s1-11-verification-evidence.json).

## Fresh executable evidence

Runtime: PHP 8.4.26, PHPUnit 12.4.0, SQLite memory and real local MySQL 8.4 on 127.0.0.1:3310. Test runners explicitly load this checkout's autoloader. MySQL credentials are read privately; all schema names are owned disposable test allowlists.

| Gate | Result |
|---|---|
| Final SQLite S1-11 affected union (51 existing functional test files) | PASS: 428 tests / 2440 assertions / 0 failures-errors-skips |
| MySQL affected functional set (14 files, including AUD-05 and shortage posting) | PASS: 110 tests / 515 assertions / no failures-errors-skips |
| MySQL last daily scope, same-recipient and receipt changes | PASS: 72 total / 66 executed / 372 assertions / 0 failures-errors; 6 explicitly SQLite-only fault-injection skips |
| MySQL Phases 8–10 bounded races, including cashier reservation/settlement in both orders | PASS: 10 tests / 66 assertions |
| MySQL affected Phases 5–7 approval, redistribution/posting, retry, AUD-05 and public bulk/accountant races | PASS: 6 tests / 74 assertions |
| MySQL Phases 2–4 receipt/replacement/return/current-boundary races | PASS: 10 tests / 85 assertions |
| SQLite latest scope/daily regressions | PASS: 18 tests / 66 assertions |
| Existing populated-schema normal upgrade | PASS: prepare at pre-Phase9 schema with historical data; normal migration; verify original receipt/ledger preservation and additive settlement |
| PHP syntax | PASS: all 51 changed/new PHP files |
| Pint --test | PASS: all 51 changed/new PHP files |
| git diff --check | PASS: working and staged patch checked before commit |

The final union includes the actual accountant authorization/HTTP/approval, correction history/evidence/notification, report reopen/supersession, operational responsibility, CP1, ordinary liability, legacy/AUD-05 posting, S1-09/S1-10 count/receipt/physical-return/idempotency, reservation/replacement, daily, settlement and complete workflow test classes. Exact file list is published below. No opt-in MySQL test is misreported as a SQLite pass.

### Actual MySQL upgrade

Schema: `s111_phase567_phase8910_upgrade_codex`. `tests/Support/ShiftPhases8910UpgradeProbe.php` prepares a populated pre-settlement schema, applies migrations normally to that existing schema, then verifies the additive schema and funded dual-counterpart settlement with original receipt/ledger rows unchanged. All three separate executions exited 0. Exception exits are nonzero; the prior Antigravity failed verification banner was not accepted as proof. No database rebuild during the upgrade, manual schema repair or new historical migration rewrite.

### What initially failed and was repaired

- An initial affected SQLite union exceeded PHP's default 512 MB; it was not a passing gate. Final runs use sufficient explicit memory.
- An earlier 423-case union exposed a newly required revision/company fixture, a pending_review report boundary incorrectly blocking an unsubmitted start-handover, and a deliberately isolated service-test schema missing later additive columns. These were corrected without weakening finalized/approved boundaries.
- MySQL exposed an invalid cashier request status in inherited technical fixtures (`confirmed` versus enum `approved`); genuine manager transfer status remains unchanged. An old manager workday fixture also used invalid active rather than in_progress; corrected only that fixture, not the historical enum migration. New race fixtures reuse the actual branch schedule and include the required handover status projection.
- Funding regressions intentionally failed before their repair. Existing correction identity fixtures now carry genuine recorded/count funding and assert no additional ledger effect, rather than expecting an unfunded increase to succeed.

## Documentation synchronization

Before publication, updated the external `project-docs/Assab-ERP-Cash-Cycle-Business-Rules-v2.0-EN.md` and its committed canonical [copy](Assab-ERP-Cash-Cycle-Business-Rules-v2.0-EN.md), including current S1-11 status and dated §§19.1–19.6 decisions. Version 2.0, stable BR/AC identifiers and historical S1-10 acceptance are retained. Also updated [API contract](api-contract.md), [task register](task-register.md), [coverage matrix](2026-10-11-s1-11-complete-coverage-matrix.md), [finding register](2026-10-11-s1-11-phases8910-findings.md) and the general [verification index](verification.md). Phantom tests/routes and automatic zero-review/authority closure claims are removed from the current report.

## Deliberately open gates

- Settlement writer: **BLOCKED_PENDING_BUSINESS_AUTHORITY**. Public and direct service default 403 SETTLEMENT_AUTHORITY_UNRESOLVED. Positive tests use explicit disposable-process policy simulation; no production activation.
- D-PR: unresolved; finalized reopen command remains blocked and both resource capabilities false. Historical reopen columns remain.
- Mahmoud final independent acceptance, AssabAPP/Dashboard release, deployment, and Foundation/main integration: not granted or claimed.
- Whole repository 1,550-test suite: **NOT_RUN**, following the affected-test policy.

Publication uses normal descendant commits to the same task remote branch. Final remote equality and clean isolated delivery worktree are checked after push; the commit containing this report is the delivery SHA.

STATUS: S1_11_CORRECTIONS_THROUGH_PHASE_10_READY_FOR_INDEPENDENT_REVIEW.

## SQLite affected union files

- `tests/Feature/ShiftAccountantCorrectionApprovalTest.php`
- `tests/Feature/ShiftAccountantCorrectionAuthorizationTest.php`
- `tests/Feature/ShiftAccountantCorrectionHttpTest.php`
- `tests/Feature/ShiftAccountantReportCorrectionTest.php`
- `tests/Feature/ShiftAud05LegacyDiscoveryTest.php`
- `tests/Feature/ShiftAuditFindingsReproductionTest.php`
- `tests/Feature/ShiftCashCountHttpTest.php`
- `tests/Feature/ShiftCashCountServiceTest.php`
- `tests/Feature/ShiftCheckpoint1CorrectionTest.php`
- `tests/Feature/ShiftCommandIdempotencyHttpTest.php`
- `tests/Feature/ShiftCorrectionCommandIdempotencyTest.php`
- `tests/Feature/ShiftDailyCloseScopeTest.php`
- `tests/Feature/ShiftDailyCloseSubmitGateTest.php`
- `tests/Feature/ShiftDailyLockSupersessionTest.php`
- `tests/Feature/ShiftHandoverSettlementAuthorizationTest.php`
- `tests/Feature/ShiftHandoverSettlementBalancesTest.php`
- `tests/Feature/ShiftHandoverSettlementHttpTest.php`
- `tests/Feature/ShiftHandoverSettlementSchemaTest.php`
- `tests/Feature/ShiftHandoverSettlementServiceTest.php`
- `tests/Feature/ShiftLegacyFinalRejectionCorrectionTest.php`
- `tests/Feature/ShiftLiabilityApprovalHttpTest.php`
- `tests/Feature/ShiftLiabilityLegacyPostingTest.php`
- `tests/Feature/ShiftLiabilityServiceTest.php`
- `tests/Feature/ShiftManagerCorrectionBulkTest.php`
- `tests/Feature/ShiftManagerCorrectionIdempotencyTest.php`
- `tests/Feature/ShiftManagerReportCorrectionTest.php`
- `tests/Feature/ShiftOperationalResponsibilityTest.php`
- `tests/Feature/ShiftPhase1RemainingAuditTest.php`
- `tests/Feature/ShiftPhases234IntegrationTest.php`
- `tests/Feature/ShiftPhases567IntegrationTest.php`
- `tests/Feature/ShiftRejectionCompatibilityTest.php`
- `tests/Feature/ShiftRejectionCorrectionTest.php`
- `tests/Feature/ShiftReportBalanceCorrectionTest.php`
- `tests/Feature/ShiftReportCorrectionEvidenceTest.php`
- `tests/Feature/ShiftReportCorrectionNotificationsTest.php`
- `tests/Feature/ShiftReportCorrectionSchemaTest.php`
- `tests/Feature/ShiftReportCorrectionServiceTest.php`
- `tests/Feature/ShiftReportHistoryPreservationTest.php`
- `tests/Feature/ShiftReportInFlightReopenTest.php`
- `tests/Feature/ShiftRequestCancellationGuardTest.php`
- `tests/Feature/ShiftRequestLifecycleSchemaTest.php`
- `tests/Feature/ShiftRequestReplacementTest.php`
- `tests/Feature/ShiftRequestReservationTest.php`
- `tests/Feature/ShiftRequestSameRecipientCorrectionTest.php`
- `tests/Feature/ShiftReturnFinalizationGuardTest.php`
- `tests/Feature/ShiftS111CompleteWorkflowTest.php`
- `tests/Feature/ShiftShortagePostingApprovalIntegrationTest.php`
- `tests/Feature/ShiftShortagePostingIdempotencyTest.php`
- `tests/Feature/ShiftShortagePostingTest.php`
- `tests/Feature/ShiftTransferPhysicalReturnTest.php`
- `tests/Feature/ShiftTransferReceiptTest.php`
