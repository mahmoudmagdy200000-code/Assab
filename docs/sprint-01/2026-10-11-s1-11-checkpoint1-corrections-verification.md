# S1-11 Checkpoint 1 — Mahmoud findings correction evidence

Date: 2026-10-11 (Africa/Cairo). Personal fork: `mohameelsherbini/Assab`.

## Scope and Git state

- `PREVIOUS_SHA=ef955149210726227788cefcf8f7044969d93015`
- Isolated checkout: `D:\claude\AssabERP\Assab-cp1-corrections`.
- Implementation branch: `task/s1-11-cp1-corrections`, created at the reviewed SHA.
- Intended publication branch: `origin/task/s1-11-corrections-and-liability`.
- Ending SHA is the commit containing this report; its full SHA and verified remote branch SHA are recorded in the delivery message.
- The primary checkout contains unpublished Antigravity Phase 5 work. That work was preserved; no corrections were applied to that dirty checkout.
- Scope is CP1-F01, CP1-F02, CP1-F03 and their verification. No Phase 5/Block B, Foundation/main changes, upstream push, PR, merge or deployment.

## Finding closure evidence

### CP1-F01 — required idempotency and revision for same-recipient correction

Both registered prefixes, `/api` and `/api/v1`, now require `asab.idempotency:required,transaction,legacy` on `POST cashier/shifts/{shift}/handover/edit`.

The request requires `expected_revision`, an integer at least zero. Zero represents a legacy report with no versioned aggregate; it does not bypass validation. The status reader supplies `data.report_revision_number`, and successful correction supplies the new number in `data.edit_details.report_revision_number`. Existing clients of this command must send the required key and observed revision.

The writer locks and re-reads the source and current report revision, rejects a mismatch with `409 STALE_REPORT_REVISION` before evaluating the changed correction state, and passes the expected number into the revision writer. Ownership checks remain at the public controller. Same recipient retains the request identity. Changing recipient through this command is still refused; a later presentation creates the next physical attempt under the existing contract.

The durable middleware stores the successful response within the financial transaction. Required transactional legacy commands use `IDEMPOTENCY_PAYLOAD_MISMATCH` for a changed payload; optional/Admin contracts and other-actor key conflicts retain their existing codes. The only routes using that required transactional legacy combination are the S1-11 replacement, reopen and correction routes in `Modules/Shift/routes/api.php`.

Regression coverage proves required fields, exact successful replay, one new revision, unchanged effect counts on retry, changed-payload refusal, stale rejection with no evidence mutation, recipient-change refusal, and lost-response replay after correction has become pending. The real MySQL gate separately proves replay after an actual commit, connection purge and cache loss with no outer rollback-only test transaction.

Manager-to-cashier verification: `ShiftTransferReceiptService::correctManagerCashTransfer` has no public mutation caller in the reviewed tree. Its only callers are three direct service tests. No new public manager correction endpoint was introduced. Its existing same-request and physical-return behavior remains covered by the union regression set.

### CP1-F02 — submitted daily workday does not advertise reopen

Daily submission persists `can_reopen=false`. `BranchManagerShiftResource` always exposes false in both `daily_close.can_reopen` and `available_actions.can_reopen` while D-PR remains unresolved. Submission returns a refreshed model so its response reflects the committed submitted state.

Tests cover actual submission, both false capabilities, the persisted false flag, direct reopen returning `409 REPORT_REOPEN_REQUIRED`, and unchanged database/history afterward. Historical submitted, timestamp-only and approved records with a stored true flag are serialized as disabled without rewriting those records. Reopen columns and historical evidence remain intact. No reopen authority was invented.

### CP1-F03 — monetary evidence type

All seven monetary evidence writes in `ShiftReportCorrectionService` use `monetary_halalas`. Corresponding schema and service tests use the same contract; string/json handling is unchanged.

New forward migration `2026_10_10_000010_normalize_shift_report_correction_monetary_type.php` normalizes existing `monetary` labels only. IDs, values, integer `old_halalas`/`new_halalas`, actor metadata and timestamps remain unchanged. Its down operation intentionally does not restore the ambiguous label. Tests compare the complete raw evidence row before/after and prove repeatability without changing money. No previously applied migration file was rewritten for this checkpoint.

## Existing-schema upgrade — PASS

`EXISTING_SCHEMA_UPGRADE=PASS`

Engine: MySQL 8.4.11 / InnoDB / REPEATABLE READ, local private server `127.0.0.1:3318`.
Schema: `s111_phase234_audit_cp1_upgrade20261010`.

`tests/Support/ShiftCheckpoint1UpgradeProbe.php prepare` built the pre-Phases-2–4 logical schema using the reviewed historical migration set with all `2026_10_10_*` migrations excluded. The existing identifier compatibility changes already present at the reviewed SHA were retained. Preparation recorded 293 baseline migrations and committed an existing report, rejected handover, aggregate, revision and physical count. Assertions proved the lifecycle columns, correction table and supersession lock column were absent.

Next, ordinary `artisan migrate --force` applied exactly the six pending migrations (000001–000005 and the new 000010). It did not rebuild the database or manually repair its schema. The verification probe checked new schema objects and compared every original column/value and ID in all five seeded tables; migration count became 299.

This is an upgrade of an isolated existing schema with data, not a production snapshot claim. Runtime baseline migration copies and sentinel JSON remain outside Git.

Reproduction after selecting an owned disposable local schema and setting its DB environment:

```powershell
$phpPath = 'D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe'
& $phpPath tests/Support/ShiftCheckpoint1UpgradeProbe.php prepare
& $phpPath artisan migrate --force
& $phpPath tests/Support/ShiftCheckpoint1UpgradeProbe.php verify
```

The probe rejects non-MySQL, non-local and non-CP1 upgrade schemas. Preparation requires a new empty schema; verify compares to the persisted baseline state.

## Verification results

Environment: PHP 8.4.26 / PHPUnit 12.4.0. Test evidence applies to this isolated working patch, identified by its source manifest, not to the unchanged previous SHA by itself.

| Gate | Result |
|---|---|
| CP1 regression class | PASS — 15 tests / 91 assertions; included in first batch |
| Affected regression batch | PASS — 178 tests / 1,147 assertions; zero failures/errors/skips |
| Remaining focused union batch | PASS — 206 tests / 1,206 assertions; zero failures/errors/skips |
| Real MySQL bounded gate | PASS — 10 tests / 85 assertions; zero failures/errors/skips |
| Existing-schema upgrade | PASS — 293 baseline + 6 normal pending migrations; data preserved |
| Syntax | PASS — 25 changed/new PHP files |
| Pint | PASS — 25 changed/new PHP files |
| git diff --check | PASS |

Final focused SQLite union: **384 tests / 2,353 assertions**. Adding the separately executed MySQL gate gives **394 tests / 2,438 assertions**, zero failures/errors/skips. No tested PHP source hash changed after verification. The owned MySQL server was shut down gracefully and both server processes exited.

Relevant S1-09 idempotency/commit/Shift HTTP subset: **17 tests / 150 assertions**, PASS. Relevant S1-10 physical-return/finalization/count HTTP/service subset: **45 tests / 421 assertions**, PASS. These subsets are included in the SQLite union and are not additional runs.

The real MySQL gate includes the original eight Phase 2–4 cases plus competing same-recipient corrections (waiting writer rejects revision 1 after revision 2 commits) and durable HTTP lost-response replay after connection loss. Workers use separate processes/connections and bounded barriers. SQLite results are not used as locking evidence.

The focused union uses 39 selected test files from Phase 1–4 and the affected S1-09/S1-10 contracts, split into two batches to avoid re-running the already verified cases. It includes optional/Admin idempotency, commit recovery, Shift command replay, receipts, attempts/physical returns, finalization guards, cash-count HTTP/service, legacy correction, immutable snapshots, liability and daily boundaries. The full suite was not run.

Failures before the fix were preserved as red evidence. The initial new regression class exposed the missing contract and misleading reopen capabilities. Old tests were updated only to supply the newly required revision/key or assert the approved evidence type/error code; existing financial/history/business assertions were retained. An initial MySQL server launch failed for insufficient host memory; the successful isolated run used a smaller buffer pool. Neither environment issue was reported as a passing test.

Local raw artifacts: `C:\Users\COMPUMARTS\.codex\visualizations\2026\10\10\01a12511-c924-7b40-83ed-e2d39ae30638\cp1-corrections` (JUnit, target inventory, upgrade logs and source hashes). They contain no production data and are not committed.

## Review and publication boundary

The implementation self-review checked the actual patch, route/caller inventory, replay and stale ordering, request identity, false reopen capabilities, metadata-only migration and preservation of existing work. No additional confirmed finding was identified within this patch scope. This is implementation verification, not Mahmoud's independent acceptance or a guarantee about every possible defect.

The user explicitly authorized commit and push to `task/s1-11-corrections-and-liability` after reviewing the completed verification summary. Publication is limited to the personal fork. `PR=NONE; merge=NONE`.

`CP1-F01=PASS; CP1-F02=PASS; CP1-F03=PASS` (implementation verification, independent reviewer acceptance pending).

The pre-publication remote SHA was freshly verified as `ef955149210726227788cefcf8f7044969d93015`. Final working-tree cleanliness and remote equality are verified after publication and recorded in the delivery message. Cleanliness applies to the isolated correction checkout; the primary Antigravity checkout contains preserved unrelated changes.

The separate publication approval required by `AGENTS.md` and `docs/ai-workflow.md` is satisfied. After successful push and verification, the delivery status is `CHECKPOINT_1_CORRECTIONS_READY_FOR_MAHMOUD_REVIEW`. Formal acceptance and further phases remain gated by Mahmoud's review at the identified corrected SHA.
