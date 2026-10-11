# S1-11 Phases 5–7 Codex corrections and verification

Date: 2026-10-11.
Task: S1-11 Phase 5–7 implementation handoff and three remaining verification gaps.
Repository: `mohameelsherbini/Assab`.
Branch: `task/s1-11-corrections-and-liability`.
Starting HEAD: `188bc53e0a05cb4b5f7eccb89c5fa6d3eaff7ff2`.
Tested state: uncommitted Phase 5–7 implementation plus Codex corrections; all 55 PHP source hashes checked unchanged before publication.
PHP source manifest SHA256: `ADC4623AC271CF97D00A38289353C04890E659476EC131D491F293EC5F418767`.

## Corrections

1. Replaced the artificial retry test with fault injection after real application writes. First correction attempt writes evidence, snapshots and outbox, registers afterCommit callbacks, then fails. First approval attempt writes its real ledger/posting and fails separately. Tests prove failed IDs disappear, one committed callback, exact correction evidence/outbox/inbox counts, and one committed liability entry.
2. Added bounded retries to the actual correction and review-approval transactions. Added a public HTTP regression: required idempotency's outer transaction owns retry, restores the inner DeadlockException rendered by Laravel's routing pipeline, and commits the correction with its response snapshot. An identical request replays exactly without rerunning the writer. The conditional retry policy affects the accountant correction command only.
3. The concurrent daily boundary invokes the actual submitDailyReport controller. Assertions cover successful submission, disabled reopen, daily_closed_at on the included handover, one sales bridge operation, unchanged cashier report, and REPORT_REOPEN_REQUIRED for the waiting correction.
4. The posted-redistribution test verifies pending signed financial balances remain A=1000/B=0 halalas; after approval the original A debit1000, A credit400, and B debit400 yield actual ledger nets600/400. Posting journal values are checked additionally.
5. Kept migration000010 in CP1's baseline; only000006/000007/000008 are pending. Upgrade preparation now refuses populated schemas and does not silently override the supplied database environment.
6. Included all55 new/modified PHP files in syntax and Pint checks. Generated JUnit/raw logs are stored outside Git.

## Red → green evidence

- Corrected direct-service forced-deadlock test initially failed with the injected deadlock after real correction writes (1 test, 1 error). This proved the missing retry behavior.
- The new public HTTP test initially failed with HTTP500 (1 test, 1 assertion, 1 failure). Service retries alone could not retry the middleware-owned outer transaction; Laravel rendered the propagated inner exception inside the routing pipeline.
- After fixing retry ownership and exception restoration, both tests passed. They are included in the final MySQL set below; repeated diagnostic runs are not added to final totals.
- The injected exception tests rollback/retry handling on MySQL; it is not evidence that a natural two-connection deadlock cycle was induced. Separate-connection contention/race cases run in the same MySQL suite.

## Fresh final results

| Gate | Tests | Assertions | Failures / errors / skipped | Exit | Result |
| --- | ---: | ---: | --- | ---: | --- |
| SQLite affected regression set,17 files |127|707|0 / 0 / 0|0|PASS|
| MySQL Phase5–7 suite |9|135|0 / 0 / 0|0|PASS|
| MySQL Phase2–4 shared-lock regression suite |10|85|0 / 0 / 0|0|PASS|
| Total final tests, without counting repeated diagnostics |146|927|0 / 0 / 0|0|PASS|
| Existing populated CP1-schema upgrade |—|—|No rebuild or manual repair|0|PASS|
| PHP syntax |55 files|—|0 errors|0|PASS|
| Pint final check |55 files|—|0 style issues|0|PASS|
| git diff --check |—|—|0 whitespace errors|0|PASS|

SQLite runtime: PHP8.4.26 / PHPUnit12.4.0; SQLite `:memory:`; duration08:44.957.
MySQL:8.4.11; localhost3310; REPEATABLE-READ.
Approved disposable targets: `s111_phase567_test_db`, `s111_phase234_audit_test_db`.
New upgrade target: `s111_phase567_upgrade_codex_20261011`.
MySQL suite durations:01:05.723 and01:11.954 respectively.
No production target, preserved baseline rebuild, Foundation change, main change, or Phase8–10 implementation was performed.

The historical Antigravity union run records399 tests/2097 assertions. It predates these corrections; it was not rerun or presented as fresh evidence. Only the affected set was run for this patch.

## Executed commands and local artifacts

Commands ran from `D:\claude\AssabERP\Assab`, using PHP at `D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe`.

A local runner reads the protected test CNF, validates localhost3310, sets explicit testing environment variables without printing credentials, and invokes PHPUnit. Exact runner commands:

~~~powershell
& $php -d memory_limit=3G D:\claude\AssabERP\.tools\s111-codex-phase567\run.php mysql567 tests/Feature/ShiftPhases567MySqlConcurrencyTest.php --do-not-cache-result --log-junit D:\claude\AssabERP\.tools\s111-codex-phase567\mysql567.xml
& $php -d memory_limit=3G D:\claude\AssabERP\.tools\s111-codex-phase567\run.php mysql234 tests/Feature/ShiftPhases234MySqlConcurrencyTest.php --do-not-cache-result --log-junit D:\claude\AssabERP\.tools\s111-codex-phase567\mysql234.xml
& $php -d memory_limit=3G D:\claude\AssabERP\.tools\s111-codex-phase567\upgrade.php
~~~

The SQLite command used the same runner with `sqlite`, `--do-not-cache-result`, `--log-junit ...\sqlite-focused.xml` and these17 test files:

- ShiftAccountantCorrectionAuthorizationTest
- ShiftAccountantCorrectionApprovalTest
- ShiftAccountantReportCorrectionTest
- ShiftReportBalanceCorrectionTest
- ShiftAccountantCorrectionHttpTest
- ShiftReportCorrectionNotificationsTest
- ShiftManagerCorrectionBulkTest
- ShiftManagerReportCorrectionTest
- ShiftShortagePostingApprovalIntegrationTest
- ShiftShortagePostingTest
- ShiftShortagePostingIdempotencyTest
- ShiftPhases567IntegrationTest
- ShiftCheckpoint1CorrectionTest
- ShiftReturnFinalizationGuardTest
- ShiftCashCountHttpTest
- IdempotencyCommitRecoveryTest
- ShiftCorrectionCommandIdempotencyTest

All are under `tests/Feature` with `.php` suffix. Standard PHPUnit commands are reproducible with the same explicit testing DB settings; the local runner is convenience tooling outside Git, not an application dependency.

Syntax and Pint used the deduplicated union of `git diff HEAD --name-only -- '*.php'` and `git ls-files --others --exclude-standard -- '*.php'`:55 files. Each ran through `php -l`; then `vendor/bin/pint --test` received the full list. Pint first fixed formatting in the edited concurrency test only; the subsequent check passed.

Local evidence directory: `D:\claude\AssabERP\.tools\s111-codex-phase567` contains JUnit, upgrade.log, source manifest, runner scripts and snapshots of Antigravity's original reports.

Upgrade log confirms:

~~~text
PRE_PHASES_567_BASELINE=PASS
2026_10_10_000006_create_shift_report_manager_reviews DONE
2026_10_10_000007_create_shift_report_correction_notifications DONE
2026_10_10_000008_create_shift_liability_posting_infrastructure DONE
EXISTING_SCHEMA_UPGRADE=PASS; exactly 3 pending S1-11 migrations applied; all existing IDs, money, evidence and allocations preserved without rebuild.
~~~

## Review, risks and publication

A separate reviewer inspected the new retry ownership, fault locations, signed-ledger assertions, actual submission call and safe upgrade preparation. No blocking issue was found. The reviewer did not execute database tests or conduct a full independent audit of all Phase5–7 paths.

Pending liability policy remains user-directed: preserve the prior charge until approval, then post the net delta. D-PR remains blocked. Production deployment, broad compatibility/release gates and formal acceptance by Mahmoud are not established by this scoped correction pass.

The user explicitly authorized commit and push. Publication includes the preserved pending Phase5–7 implementation and these corrections, to `origin/task/s1-11-corrections-and-liability` in the personal fork only. Raw/generated artifacts and credentials are excluded. No upstream PR or merge is authorized or created. Final local/remote SHA is reported after publication in the chat.
