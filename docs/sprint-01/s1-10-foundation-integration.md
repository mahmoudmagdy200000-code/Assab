# S1-10 → Foundation integration — 2026-10-09

## Current S1-10 acceptance — 2026-10-09

**S1-10 = ACCEPTED BY MAHMOUD / CLOSED. F01 = ACCEPTED. F02 = ACCEPTED.**
Accepted S1-10 code SHA: `29b5c208055c7db34d8cb9826778dbe256cca204`.
Authority: Mahmoud's explicit resumed-session integration instruction identifying this accepted SHA and authorizing normal integration, publication and ancestry-verified branch retirement. This supersedes historical S1-10 NOT ACCEPTED/in-progress and review-branch-only instructions; historical evidence is retained.

The integration baseline is separate: merge commit `453cea89191ed5b3142ebc5c024cbe6b6c5c442d` preserves both histories. The subsequent documentation commit records acceptance without changing application code; its published Foundation SHA becomes the working baseline.

Release gates remain **OPEN / NOT_RUN**: MySQL concurrency, AssabAPP integration/release, Dashboard integration/release, production MySQL rollout. S1-11 remains **NOT STARTED**; R5/R4b/R6 and other carryovers remain S1-11 work. Acceptance is not deployment approval. Fresh evidence: `s1-10-foundation-integration.md`.

## Git facts and preservation

- Repository: `mohameelsherbini/Assab`; upstream and `main` untouched.
- Foundation base: `b84d195214d96a319a929b181ec94edd2a7341ce`.
- Review / accepted code: `29b5c208055c7db34d8cb9826778dbe256cca204`.
- Merge base: `193ea3c7cc2f88b6bbf0723941335b4eb5accf1d`.
- Divergence: 3 Foundation-only commits (workflow/docs and merge); 8 Review-only commits (accepted S1-10 history).
- Temporary branch: `integration/sprint-01-s1-10`, from refreshed remote Foundation.
- Explicit two-parent merge: `453cea89191ed5b3142ebc5c024cbe6b6c5c442d`; no conflicts/manual resolutions.
- Tested tree: `63e127f854267ae6451dac46b23df4541209b774`.
- All 2742 Review file modes/blobs preserved exactly. Foundation-only `AGENTS.md` and `docs/ai-workflow.md` preserved exactly. Review contributes 46 changed/added files. No unexpected application changes/deletions.
- Acceptance documentation is a separate commit; accepted history is not rewritten, squashed or cherry-picked.

## Local isolation

The prior primary checkout was clean with four unpublished closure commits above stale remote `47066902`. Other old checkouts contained staged/unstaged/untracked work. Binary working/index patches, untracked lists/copies, and a bundle of local history were saved and verified under local temporary `integration-safety`. A fresh clone of the requested fork was clean at remote Foundation before integration. Old checkouts were excluded; no old implementation or local acceptance documentation was reapplied. No published branch was reset/rebased.

Only ignored dependency files were reused; Composer regenerated autoload metadata against the new checkout. Reflection verified the source resolves inside `Assab-integration`. Before tests, effective Laravel environment/connection/database/PDO driver were `testing` / `sqlite` / `:memory:` / `sqlite`. No production or preserved database was used.

## SOURCE_INSPECTION_ONLY

F01: operational chain/end fields; responsibility at actual start only; independent predecessor reports; no receipt/opening/liability/financial close at replacement start; separate Admin operational visibility.
F02: typed attempts; recipient initiates, original sender confirms return; attempt-scoped retained amounts and redelivery; immutable history; confirmed receipts alone supply opening; physical return invalidates stale count through revision/recount.
R1 reminder-only timeout, C2 historical source branch, C3 transaction-local locks/re-reads, C4 attempt-upload cleanup, FIN-01, D14/D17/D18, receipt immutability and idempotency remain byte-identical to accepted Review. Relevant source/tests were inspected. This is not MySQL concurrency evidence.

## DEVELOPER_EXECUTED — fresh validation

PHP 8.4.14 / PHPUnit 12.4.0 / ParaTest 7.14.1. Working directory: fresh `Assab-integration`. `$PHP` denotes `../php-runtime/php`. Test-only process environment:

```sh
export APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:
export APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
```

The key is a disposable test value. Focused command:

```sh
$PHP -d memory_limit=-1 vendor/bin/phpunit --do-not-cache-result --no-progress --log-junit /tmp/assab-focused.xml tests/Feature/ShiftOperationalResponsibilityTest.php tests/Feature/ShiftTransferPhysicalReturnTest.php tests/Feature/ShiftReturnFinalizationGuardTest.php tests/Feature/ShiftReassignHandoverCountTest.php tests/Feature/ShiftRejectionCorrectionTest.php tests/Feature/ShiftCashCountHttpTest.php tests/Feature/ShiftCashCountServiceTest.php tests/Feature/ShiftCommandIdempotencyHttpTest.php tests/Feature/ShiftCloseChainTest.php tests/Feature/ShiftLegacyMoneyCompatibilityTest.php tests/Feature/LiveShiftBoardTest.php tests/Feature/ManagerLiveShiftBoardTest.php tests/Unit/ShiftFinancialCalculatorTest.php
```

Exit 0: **166 tests / 1119 assertions / 0 failures / 0 errors / 0 skipped**, 01:24.002. F01/F02/R1/C2/C3/C4/FIN-01/D14/D17/D18 and idempotency covered. FIN-01 real HTTP and preview: gross115/cards50/apps25/confirmedOpening10/count30/net100/VAT15/expected50/variance−20, complete shortage allocation.

Full configured Unit/Feature/NFR command, four independent SQLite memory workers:

```sh
$PHP -d memory_limit=-1 vendor/bin/paratest -p 4 --passthru-php="'-d' 'memory_limit=-1'" --log-junit /tmp/assab-full.xml
```

Exit 0: **1522 tests / 8997 assertions / 0 failures / 0 errors / 1 skipped**, 04:53.200 wall time. Skipped identity: `Tests\Feature\BranchFixedAssetsUploadPersistenceTest::test_a_driver_error_is_not_leaked_to_the_client` because SQLite does not enforce column length. Full suite includes immutable receipt and broader idempotency regressions. Logs/JUnit remain temporary local evidence.

Static commands executed on the pending merge index:

```sh
git diff --cached --name-only -- '*.php' > /tmp/assab-merged-php.txt
while IFS= read -r p; do $PHP -l "$p" || exit 1; done < /tmp/assab-merged-php.txt
$PHP vendor/bin/pint --test $(cat /tmp/assab-merged-php.txt)
git diff --check
git diff --cached --check
```

Syntax/Pint: **37/37 PHP files passed**. Both diff checks passed. Subsequent acceptance edits are documentation only; no application code changed after testing.

## NOT_RUN — gates and scope

- MYSQL CONCURRENCY = **OPEN / NOT_RUN**; no deployment-equivalent independent MySQL connections executed.
- AssabAPP integration/release = **OPEN / NOT_RUN**.
- Dashboard integration/release = **OPEN / NOT_RUN**.
- Production MySQL rollout = **OPEN / NOT_RUN**.
- S1-11 = **NOT STARTED**; carryovers unchanged. No clients, deployment, global money-unit migration or business-rule changes.

## Publication/retirement

Publish and review temporary integration first; update Foundation by normal history only after verification. Delete Review/temporary branches only after remote Foundation contains Review, no unique Review commits remain, acceptance docs are preserved and green evidence still applies. Archive `sprint-01-s1-10-accepted` at the accepted code SHA, not the integration SHA. Final publication/retirement outcome and Foundation SHA are recorded in the delivery report.
