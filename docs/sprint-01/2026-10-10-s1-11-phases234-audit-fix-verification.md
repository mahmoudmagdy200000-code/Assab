# S1-11 Phases 2–4 — independent audit fixes and verification

Date: 2026-10-10. Repository: personal fork **mohameelsherbini/Assab**.
Branch: **task/s1-11-corrections-and-liability**.
Starting HEAD: **2d2317b39896267e0da2d984661f601e9de88e31**.

The user explicitly authorized fixing the comprehensive audit findings, testing the affected paths, and committing/pushing this branch to the personal fork. Pre-existing unpublished Antigravity implementation was retained. This report supersedes the original Antigravity verification report for the repaired working tree. The ending SHA is the commit containing this report, recorded in Git and the delivery message.

## Scope and fixes

| Audit finding | Implemented correction | Regression evidence |
|---|---|---|
| AUD-01: cancelled requests in current readers | Active scopes in source/manager relations, live lists, pending-correction checks, resource fallback and report revision propagation; synchronize next recipient. Cancelled predecessors remain historical. | Audit regression: current request, recipient status, next cashier, cancelled rejection, historical revision; cache tests |
| AUD-02: retained legacy cash bypass | Block replacement for positive legacy rejection evidence without an attempt, dangling attempts, retained attempt cash and unconfirmed returns. | Legacy handover/manager replacement cases; physical return and integration suites |
| AUD-03: internal actor authorization | Shared guard permits only the report owner cashier or assigned active branch manager of the same branch; explicit actor recorded in history. | Foreign actor and internal actor history tests |
| AUD-04: submitted/finalized boundaries | Shared ordered workday/source guard checks submitted flag/timestamp, daily closure, active locks, carry-over receiving days, closed/pending-review admin projection and final-approved operations. | Boundary/reopen/audit suites; separate-connection submit-boundary race |
| AUD-05: actor discriminator exceeds MySQL column | Store canonical morph discriminator rather than model FQCN. | Manager correction/reopen on real MySQL |
| AUD-06: wrong/ambiguous receiving shift | Bind explicit shift to recipient, branch and available status; implicit destinations require a unique available shift on the relevant source day. | Cross-branch/wrong cashier/ambiguous destination tests |
| AUD-07: before-state loss | Snapshot previous revision and immutable review history before cancellation; history includes request, workflow and report projection. New workflow counters may reset only with archived prior counters. | Snapshot and archived rejection-count tests |
| AUD-08: invalid replacement money | Shared bounded SAR validation and integer halala conversion before reservation/cancellation. | Negative amount, reservation and receipt suites |
| AUD-09: unrelated aggregators | Validate active aggregator enabled for this branch, unique IDs and monetary payload. | Refused unassigned aggregator and accepted enabled aggregator |
| AUD-10: correction bypasses fresh count | Reject correction while physical recount is required even when reopened revision has no count. Preserve original physical observation across valid corrections. | Fresh-count regression; recount/return integration |
| AUD-11: broad/unauthorized daily lock supersession | Assigned manager, explicit workday scope, ordered locks, submitted boundary guard; leave other workdays locked. | Daily lock and cross-workday/actor tests |
| AUD-12: repeated reopen | Required transactional idempotency at the command boundary; operation ID is audit identity, not a new replay table. No public accountant workflow added. | Required middleware replay/payload conflict and correction idempotency suites |

### Additional issues found and fixed during verification

- MySQL REPEATABLE READ allowed concurrent reservations from two workdays to reserve **230 SAR against 200 SAR**. A shared manager cash-owner row mutex precedes workday/ledger locks; reservation rows use current locking reads. Financial close paths participating in this ledger use the same ordering.
- Confirmation could win a race yet replacement read an old receipt snapshot. Replacement now rejects approved requests and uses a current receipt read.
- A waiting correction/reopen could see the new aggregate but an old revision/count snapshot, dropping physical count evidence. Current reads cover the previous revision, count carry-forward, report breakdowns, immutable snapshots, shares and review evidence. Confirmation count reads follow this policy too.
- Snapshot allocations queried nonexistent report_revision_id; the actual allocation identity is report_revision. Historical allocations and their shares are preserved using the correct column.
- Cancelled rejected predecessors could continue blocking the replacement; cancelled pending predecessors could have their historical revision rewritten on a new report. Both were reproduced and fixed.
- Internal correction/reopen audit rows now identify the supplied actor even without an authenticated HTTP user.
- Unsupported fields, values beyond monetary storage bounds, oversized operation IDs and reopening an unended report are rejected before writing.
- Financial caches invalidate **after commit**; rollback leaves cached data intact. Manager handover cache aliases are included.
- Integration scenario 2 now uses an actual receiving report and physical return, automatic recount requirement, and the real recount endpoint. Its original sender gross remains 500 SAR.

## Migration decision

Existing migration edits only shorten overlong MySQL foreign-key/index identifiers that prevented a **fresh** installation. The manager-recipient rollback discovers the actual FK name, preserving compatibility with earlier default names. No applied business columns, financial values or historical records were renamed or backfilled by these edits. This narrowly justified installation fix is an exception to the plan's preference for leaving applied migration files untouched.

The repaired migration set installed from scratch on a newly created local disposable MySQL schema without manual table repair. Additive Phase 3/4 migrations retain their immutable-history rollback guards.

## Verification

Environment: PHP 8.4.26 / PHPUnit 12.4.0. SQLite functional evidence and MySQL concurrency evidence are separate.

- Original independent baseline: **216 tests / 1,225 assertions**, zero failures/errors/skips.
- Original audit reproducers: **20 failed cases / 27 assertions**, exposing gaps despite the passing baseline.
- Final targeted SQLite run: **292 tests / 1,600 assertions**, zero failures/errors/skips (5 minutes 31.457 seconds). Contains the original 26 suites, independent audit/cache regression suites, and affected handover ledger, variance custody and reassignment suites. The full 1,550-test suite was not run.
- Final real MySQL run: **8 tests / 65 assertions**, zero failures/errors/skips (40.751 seconds).
- PHP syntax: **51 changed/new PHP files passed**. Laravel Pint: **51 files passed**. git diff --check passed.

MySQL 8.4.11 / InnoDB / **REPEATABLE READ**, separate PHP processes/connections and barriers. Only a new owned local server (127.0.0.1:3317) and schemas prefixed s111_phase234_audit_ were used. The opt-in gate rejects other DB targets and never migrates/truncates them itself.

The eight cases cover shared-owner cross-workday overreservation, replacement winning against old confirmation, confirmation winning against replacement with once-only financial writes, submitted boundary winning against correction/reopen, newer count preservation after a waiting correction/reopen, and real actor-column compatibility. The submission race exercises the existing financial-input locking boundary followed by the submitted marker; it is not a complete accountant approval simulation.

### Reproduce targeted checks

From the repository root in PowerShell:

~~~powershell
$php = 'D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe'
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = ':memory:'
$targets = @(
 'ShiftRequestLifecycleSchemaTest', 'ShiftRequestCancellationGuardTest',
 'ShiftRequestSameRecipientCorrectionTest', 'ShiftRequestReplacementTest',
 'ShiftRequestReservationTest', 'ShiftTransferReceiptTest',
 'ShiftTransferPhysicalReturnTest', 'ShiftReturnFinalizationGuardTest',
 'ShiftCommandIdempotencyHttpTest', 'ShiftLegacyFinalRejectionCorrectionTest',
 'ShiftReportCorrectionSchemaTest', 'ShiftReportCorrectionServiceTest',
 'ShiftReportCorrectionEvidenceTest', 'ShiftCorrectionCommandIdempotencyTest',
 'ShiftFinancialCalculatorTest', 'ShiftCashCountServiceTest',
 'ShiftReportHistoryPreservationTest', 'ShiftPhase1ReviewIntegrityTest',
 'ShiftLiabilityServiceTest', 'ShiftLiabilityRealSchemaTest',
 'ShiftReportReopenBoundaryTest', 'ShiftReportInFlightReopenTest',
 'ShiftDailyLockSupersessionTest', 'ShiftPhases234IntegrationTest',
 'ShiftOperationalResponsibilityTest', 'BranchWorkdayWindowTest',
 'ShiftPhases234AuditRegressionTest', 'ShiftReportMutationCacheTest',
 'HandoverLedgerDateTest', 'ShiftReassignHandoverCountTest',
 'ShiftHandoverVarianceCustodyTest'
) | ForEach-Object { "tests/Feature/$_.php" }
& $php -d memory_limit=3G vendor/phpunit/phpunit/phpunit @targets --do-not-cache-result
~~~

For MySQL, initialize/migrate an **owned disposable local schema** first, set DB environment explicitly to that schema, then run:

~~~powershell
& $php vendor/phpunit/phpunit/phpunit tests/Feature/ShiftPhases234MySqlConcurrencyTest.php --do-not-cache-result
~~~

Raw JUnit, migration logs, test history and source hashes are retained outside Git in the local audit artifacts/runtime; dependencies, logs and credentials are not committed.

## Review and delivery boundaries

A fresh-context read-only reviewer inspected the full unpublished implementation and audit fixes, identified two further clusters, then re-reviewed the repaired paths. Recommendation: **acceptable for personal-fork commit, conditional on final targeted tests passing**. The reviewer ran no PHPUnit; execution evidence is from the implementation session.

That condition is satisfied: the final two runs total **300 tests / 1,665 assertions**, with no failures, errors or skipped tests. All 51 tested PHP source hashes remained unchanged through the final gate. The owned disposable MySQL server was stopped and process exit verified after testing.

No confirmed remaining blocker was found in the reviewed Phase 2–4 scope. This is scoped evidence, not a guarantee that every possible defect is absent. MySQL results establish the executed races, not every project-wide locking behavior.

Gate D-PR remains **blocked by business decision**: submitted/finalized workday reopening continues returning REPORT_REOPEN_REQUIRED. Phases 5–9, public accountant workflows, payroll/settlement posting, AssabAPP release compatibility, pending-handover release checks and production rollout are outside this delivery. Formal acceptance by Mahmoud at an identified SHA remains pending.

Publication target: **origin/task/s1-11-corrections-and-liability only**. No upstream push, PR, merge or deployment.
