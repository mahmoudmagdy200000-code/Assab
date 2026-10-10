# S1-11 Phase 1 — comprehensive audit corrections

Date: 2026-10-10. Repository: `mohameelsherbini/Assab`. Branch: `task/s1-11-corrections-and-liability`.

Starting HEAD: `8029282273ad7f0eb3b341fcfaea36c89db62d0b`, clean worktree. The user explicitly authorized implementation, commit and push after requesting the comprehensive Phase 1 audit. This records development verification; it does not update Mahmoud's acceptance or authorize deployment, upstream PR or merge.

## Confirmed findings and corrections

### 1. Responsibility rejection failed and could leave partial writes

`cashierRejectResponsibility` used an undefined `$shiftModel` after updating the variance detail. The HTTP request returned 500 while persisting rejection. Manager rejection also wrote status/history outside a transaction before inserting required review evidence; an evidence insert failure left partial state.

Both rejection endpoints now lock the source shift and relevant detail rows and commit status, history and evidence within one transaction. Cashier assignment and manager branch checks remain in place. Approval endpoints retain their existing transaction and locks.

Regression evidence: real cashier HTTP rejection succeeds and records reviewer/reason; injected SQLite evidence-insert failure returns 500 and restores pending status, absent reviewer, unchanged history and zero evidence for both actors.

### 2. Review events were collapsed or attributed to a later report

The original `firstOrCreate` keyed only revision/shift/detail, losing approve → reject → approve events. Preservation after advancing the revision could duplicate an old approval under the corrected current revision.

Actual review operations now append evidence for only the detail IDs changed by that operation. Preservation passes deduplicate existing review state across revisions. End, handover recording, handover editing and plain rejection preserve old evidence before advancing or mutating the source projection. An unknown historical review timestamp stays unknown.

Regression evidence: three distinct events persist at the original revision even under a frozen identical timestamp; repeat preservation adds no event; a balanced correction has no copied prior approval in its current revision and removes old current variance details.

### 3. Handover snapshots omitted narrative and could capture overwritten metadata

Snapshots omitted handover notes, variance explanation/files and rejection metadata. A legacy request without an existing snapshot could have its rejection reason overwritten before the snapshot was captured.

Snapshots now include closing balance, report/handover notes, handover variance amount/reason/files, rejection reason/count and rejection timestamps. Existing immutable snapshots remain unchanged. Both plain rejection paths, record and edit capture the previous revision before mutation.

Existing older immutable snapshots may already lack these keys. Edit and resubmission also preserve the complete previous handover and report closing/notes projection in additive correction history before overwrite. This retains source evidence without rewriting the old snapshot. Two regressions reproduced the missing evidence before this additional fix: 2 tests / 9 assertions / 2 failures; an earlier probe had a test cast error, corrected before the verified red run.

Regression evidence: original notes survive corrected resubmission; legacy evidence preserves original file paths, reason and rejection metadata; a completed legacy report retains its original closing balance and status in its immutable snapshot after edit, recount and receipt. Referenced uploaded files remain present.

### 4. Completed legacy final rejection without a cash count had no direct recovery path

Editing a historical `rejected_final` request changed it to pending but left its source COMPLETED without count evidence. Confirmation required a count; ending required IN_PROGRESS.

An eligible rejected, unreceived, completed request with no current transfer attempt and no current revision count now reopens its source to IN_PROGRESS during correction. The owner must explicitly end/count again. Editing creates no physical count. Existing carry-forward recount checks and receipt immutability remain enforced.

Regression evidence: completed/no-count legacy report → edit → premature receipt blocked → explicit end with new count → only named recipient can confirm → exactly one receipt and unchanged custody on duplicate confirmation. Existing Total Sales custody is preserved without duplicate posting.

### 5. Older custody/receipt tests no longer represented the approved contract

Two tests expected destructive report/request/custody deletion removed by Phase 1. They now prove preservation and rejection-history insert rollback. Three receipt fixtures lacked required current count evidence; they now record real revision-bound counts. The ledger rollback test asserts the injected ledger failure, preventing a count-validation exception from falsely satisfying rollback coverage.

No count guard was weakened to make fixtures pass. No production ledger deletion was restored.

The full suite also identified four `HandoverLedgerDateTest` cases whose common fixture used an IN_PROGRESS source without a cash count. A targeted reproduction confirmed `REPORT_COUNT_REQUIRED`: 4 tests / 1 assertion / 4 errors. The fixture now uses a completed source and a real current revision-bound count. All original confirmation-date, daily-statement, balance and no-double-post assertions remain unchanged. Targeted retest: **4 tests / 15 assertions / zero failures, errors or skips**. This was a test-setup correction; the production patch did not change during the full run.

## Scope and verification

- Four production files: ShiftVarianceController, HandoverService, ShiftEndService and ShiftReportRevisionSnapshotService.
- Tests: updated ShiftHandoverVarianceCustodyTest and HandoverLedgerDateTest; new ShiftPhase1ReviewIntegrityTest and ShiftLegacyFinalRejectionCorrectionTest.
- No new migration, applied migration rewrite, receipt rewrite or Phase 2 cancellation implementation.
- Original six new regressions failed against the starting code: 6 tests / 20 assertions / 6 failures. After the initial corrections: 6 tests / 35 assertions passed.
- Expanded focused suite initially reproduced the remaining legacy rejection-metadata overwrite: 27 tests / 219 assertions / 1 failure. After fixing pre-mutation snapshot timing: 27 tests / 229 assertions passed. After the existing-incomplete-snapshot regressions and additive history fix, final focused evidence is **29 tests / 247 assertions / zero failures, errors or skips**.
- PHP syntax and Pint: original seven changed PHP files passed; the additional HandoverLedgerDateTest fixture correction also passed both checks. `git diff --check`: passed.
- Independent read-only review inspected the production patch, regression tests, additional ledger-date fixture and actual JUnit evidence. No material new findings in the bounded correction patch; ACCEPTABLE FOR COMMIT/PUSH to the personal fork with the full-suite and performance limitations explicitly recorded below.
- The initial full-suite process terminated at `BrandOwnerFinancialReportingTest::test_sales_channel_analysis_defaults_to_first_branch_when_branch_id_omitted` with PHP's 512 MB memory limit exhausted. It has no complete result and is not passing evidence. The prior documented full suite used 1.87 GB; retry uses a process-local 3 GB limit. No application memory configuration was changed.
- An early 3 GB retry was interrupted when the additional legacy incomplete-snapshot coverage exposed the remaining evidence gap; it is not final verification. The complete suite was restarted after the final fix and focused green run.
- Final full-suite retry completed with exit 1: **1559 tests / 9325 assertions / 4 errors / 2 failures / 1 skipped**, runtime **01:12:12.567**, peak memory **1.91 GB**. All four errors were the old `HandoverLedgerDateTest` fixture already corrected and separately retested successfully. The two failures were response-time assertions, detailed below. This full run is **FAIL**, not a fully green suite.
- The single skip is `BranchFixedAssetsUploadPersistenceTest::test_a_driver_error_is_not_leaked_to_the_client`: SQLite does not enforce the column length used to provoke the driver error. That driver-error behavior remains unverified on MySQL/MariaDB.
- SHA-256 comparison confirmed all four production files and the three original changed regression test files were unchanged during final full-suite execution. Only the separately verified ledger-date test fixture changed after test discovery. Composite evidence keeps the original full JUnit and corrected reruns separate; no merged or fabricated passing full-suite log was produced.

### Performance evidence and open gate

The full run returned HTTP 200 for the affected requests but failed two 500 ms assertions:

| Test in `Tests\\NFR\\Performance\\ResponseTimeTest` | Full-run measurement | First isolated rerun | Final current-code rerun |
| --- | --- | --- | --- |
| `test_cashier_operations_response_time` | 1210.289 ms: FAIL | 5387.786 ms: FAIL | PASS at the unchanged 500 ms limit |
| `test_multiple_endpoints_percentile_response_time` | 1285.035 ms: FAIL | 3575.493 ms: FAIL | PASS at the unchanged 500 ms limit |

The first isolated rerun was **2 tests / 7 assertions / 2 failures**, exit 1, 02:12.793, 80 MB. A bounded baseline comparison used copies of the four changed production classes from starting SHA `8029282273ad7f0eb3b341fcfaea36c89db62d0b`, selected by a process-local prepend autoloader, without replacing worktree files. The same two cases passed **2 tests / 7 assertions**, exit 0, 00:10.215, 80 MB. Reflection confirmed the resolved handover and snapshot classes came from those baseline copies; the other two changed classes were not loaded. Immediately afterward, the normal current-code rerun passed **2 tests / 7 assertions**, exit 0, **00:09.026**, 80 MB.

The measured GET paths use unchanged list, dashboard, purchase and profile handlers. The cashier list resolves HandoverService through its unchanged constructor, but invokes none of the changed rejection, correction, end or snapshot methods. No middleware, providers or global listeners changed. Independent review found no patch-related causal path. The repeated timing results vary; no environmental root cause or pre-existing performance defect is established. Stable full-suite performance remains an **open verification gate**. No threshold was raised, assertion removed or performance test skipped.

### Final verification matrix

| Executed run | Tests | Assertions | Errors | Failures | Skips | Result |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| Final focused correction/history suite | 29 | 247 | 0 | 0 | 0 | PASS |
| Complete configured Unit/Feature/NFR suite | 1559 | 9325 | 4 | 2 | 1 | FAIL; exact identities accounted for above |
| Corrected ledger-date fixture rerun | 4 | 15 | 0 | 0 | 0 | PASS |
| First isolated performance rerun | 2 | 7 | 0 | 2 | 0 | FAIL |
| Bounded starting-code performance comparison | 2 | 7 | 0 | 0 | 0 | PASS |
| Final current-code performance rerun | 2 | 7 | 0 | 0 | 0 | PASS; timing stability gate remains open |

No further full-suite repeat was performed for the isolated fixture repair or the unchanged performance methods. Independent review recommended bounded personal-fork publication with transparent composite evidence and the open gates retained.

Runtime: PHP 8.4.26, PHPUnit 12.4.0, `APP_ENV=testing`, SQLite `:memory:`. Logs are local ignored artifacts in `storage/logs/codex-s111-corrections-focused.xml`, `codex-s111-corrections-full.xml`, `codex-s111-ledger-date-green.xml` and `codex-s111-performance-{retest,baseline,final}.xml`; they are not committed. The bounded baseline autoloader and source copies are local diagnostic artifacts outside the backend repository and are not committed.

Commands executed (PowerShell from the backend repository):

```powershell
$env:APP_ENV='testing'
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=':memory:'
& 'D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe' vendor/phpunit/phpunit/phpunit tests/Feature/ShiftPhase1ReviewIntegrityTest.php tests/Feature/ShiftLegacyFinalRejectionCorrectionTest.php tests/Feature/ShiftHandoverVarianceCustodyTest.php tests/Feature/ShiftPhase1RemainingAuditTest.php tests/Feature/ShiftReportHistoryPreservationTest.php --log-junit storage/logs/codex-s111-corrections-focused.xml
& 'D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe' -d memory_limit=3G vendor/phpunit/phpunit/phpunit --do-not-cache-result --log-junit storage/logs/codex-s111-corrections-full.xml
& 'D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe' vendor/phpunit/phpunit/phpunit tests/Feature/HandoverLedgerDateTest.php --log-junit storage/logs/codex-s111-ledger-date-green.xml
& 'D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe' -d memory_limit=3G vendor/phpunit/phpunit/phpunit tests/NFR/Performance/ResponseTimeTest.php --filter 'test_cashier_operations_response_time|test_multiple_endpoints_percentile_response_time' --do-not-cache-result --log-junit storage/logs/codex-s111-performance-retest.xml
& 'D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe' -d memory_limit=3G -d auto_prepend_file=D:/claude/AssabERP/.tools/s111-perf-baseline/prepend.php vendor/phpunit/phpunit/phpunit tests/NFR/Performance/ResponseTimeTest.php --filter 'test_cashier_operations_response_time|test_multiple_endpoints_percentile_response_time' --do-not-cache-result --log-junit storage/logs/codex-s111-performance-baseline.xml
& 'D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe' -d memory_limit=3G vendor/phpunit/phpunit/phpunit tests/NFR/Performance/ResponseTimeTest.php --filter 'test_cashier_operations_response_time|test_multiple_endpoints_percentile_response_time' --do-not-cache-result --log-junit storage/logs/codex-s111-performance-final.xml
```

## Remaining gates

MySQL/MariaDB concurrency and locking: NOT RUN. AssabAPP/Dashboard integration and production rollout: NOT RUN. Stable full-suite performance: OPEN, with the historical failures and final isolated PASS recorded above. Formal acceptance: not claimed. These tests verify Phase 1 correction behavior and do not establish completion of later S1-11 phases or deployment readiness.

Publication target is the existing task branch in the personal fork, using a normal push. No upstream PR or merge is authorized. The ending commit is the commit containing this report; its exact SHA and verified remote SHA are supplied in the final handoff.
