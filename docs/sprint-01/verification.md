# S1-01 verification evidence

## Focused documentation audit corrections C01–C03 — 2026-10-07

**S1-01 / S1-02 / S1-03 remain Ready for review; not Accepted.** This documentation-only correction was reviewed from branch HEAD `52ce4b0bcfe2f3e9842d5c76b268c27e067e5f3e`; it does not claim runtime acceptance.

| Correction | Static recheck | Result and boundary |
|---|---|---|
| C01 — S1-01 writer attribution | `ShiftEndService::endShiftOnly()` calls `saveSalesBreakdown()`; that method creates `ShiftSalesBreakdown` rows. `VarianceCalculationService::recordVariance()` writes variance details/alerts, not channel rows. `route-map.md` summary row now matches its detailed trace. | RESOLVED. Current source inspected; no application behavior changed or executed. |
| C02 — S1-02 receipt/report wording | Current `submitDailyReport()` closes the daily report without checking a confirmed receipt amount. BR-05–06/AC-07 permit report closure while receipt is unconfirmed and retain sender responsibility; BR-09 separately requires manager shortage-liability approval before daily submission. Contract text now states those as distinct facts and does not make receipt a precondition. Stale S1-03 “has not started” wording was corrected. | RESOLVED. Source and rule text inspected; no workflow/runtime test executed. |
| C03 — S1-03 report revisions without handover | `ShiftEndService::endShiftOnly()` commits report data without creating a handover. Native Admin `ShiftCloseService::close()` updates `asab_shifts` and creates an Admin operation without a legacy handover. Existing legacy handover rows are shift-linked requests; no current report-revision entity was found. ADR now proposes a stable report aggregate/revision key and optional zero/one/many request associations, preserving request and receipt IDs across corrections. | RESOLVED as a design correction. Proposal remains subject to S1-05 review; no migration or application implementation/test was run. |

`git diff --check` is the documentation whitespace check. No migration, seed, fixture, backend test, Dashboard test, or AssabAPP test was run. The proposed end-only → later handover request → receipt confirmation → correction fixture is planned only, not executed. S1-05 implementation remains unstarted.

## Focused MySQL rerun after BranchFactory correction — 2026-10-07 (current result)

**S1-01 status: Ready for review; not Accepted.** The previously blocked 3310 test environment is operational and the focused fixture error is resolved. Only `Modules/Branch/database/factories/BranchFactory.php` changed: the obsolete `map_coordinates` factory field was removed. The Branch creation migration introduced the column (`Modules/Branch/database/migrations/2025_10_09_100000_create_branches_table.php:20`), but the later forward migration drops it (`2026_01_25_162355_modify_branches_table_add_new_fields.php:138-151`). Read-only `information_schema.COLUMNS` checks confirmed its absence from both fully migrated `assab_s1_local` and disposable `assab_s1_test`; `location` remains present, and `opening_hours` is `datetime`. The Branch model's fillable/casts do not include `map_coordinates`. No migration, model, business logic, or schema was changed.

`php -l Modules/Branch/database/factories/BranchFactory.php` passed. Each file below was rerun in a separate PHPUnit 12.4.0 / PHP 8.4.26 process with process-local MySQL credentials targeting **only** `assab_s1_test` on `127.0.0.1:3310`; every command exited 0. Full logs remain in the ignored workspace-local `.tools/s1-01/test-results-20261007-fixture1` directory and are not part of the proposed commit. The command form was `php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result --no-progress <file>`, using the approved portable `php.exe`. No broad seed ran.

## Command and repository-state evidence

| Check | Command and context | Actual result | Tested repository state |
|---|---|---|---|
| Current Laravel route registration | From `D:\claude\AssabERP\Assab`: `D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe artisan route:list --path=api --json` | Reran for this documentation correction; exit 0; 1,734 registered routes. The focused full-URI records in `route-map.md` were individually matched to this output. Registration only; handlers were not invoked. | Backend `sprint/01-financial-foundation`, `33ecd35879f125d5de5c9fa7b1da0c7bc56a8adb`; application source unchanged during this check. |
| Focused six-file PHPUnit baseline | From the Backend root, each file separately: `D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result --no-progress <one test file>`; process-local `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3310`, `DB_DATABASE=assab_s1_test`, `DB_USERNAME=assab_s1_test`; password loaded from protected workspace credential file and not printed. | Historical executed result: 41 tests, 144 assertions, 0 failures, 0 errors; six separate runs; no broad seed. | Exact test-run HEAD was not captured in retained logs. The source state included the BranchFactory correction later committed as `21d06ffe21fd7392c44c7980340e9c42d052a8f5`; this is not asserted as the exact tested SHA. **Historical / tested SHA not independently reconstructed.** |
| Dashboard TypeScript no-emit | Earlier record: TypeScript 5.9.3 exit 0, but exact executed command and tested HEAD were not retained (**historical / command not independently reconstructed**). The current package script is `typecheck: tsc -p tsconfig.json --noEmit`. Current safe attempt from Dashboard root: `C:\Program Files\nodejs\node.exe D:\claude\AssabERP\.tools\s1-01\corepack\v1\pnpm\10.34.6\bin\pnpm.cjs --filter @workspace/mockup-sandbox typecheck`; process-local cache/state paths under `.tools\s1-01`. | Historical PASS remains qualified as prior evidence. Current attempt did **not** reach TypeScript: pnpm exited `-4048` with `EPERM` resolving `D:\claude\AssabERP\dashboard`. No source or lockfile was changed. | Historical tested SHA not captured. Current attempt used Dashboard `sprint/01-financial-foundation` at `0378530867c8a14706213768ce49553cefc203b2`. |
| PHP syntax / Pint for BranchFactory | Historical recorded commands: `php -l Modules/Branch/database/factories/BranchFactory.php`; `php vendor/bin/pint --test Modules/Branch/database/factories/BranchFactory.php`. | Both historical checks passed; exact invocation environment and tested HEAD were not retained. **Historical / tested SHA not independently reconstructed.** | BranchFactory correction later committed as `21d06ffe21fd7392c44c7980340e9c42d052a8f5`; exact test-run SHA is unknown. |
| MySQL migration and object baseline | Historical approved migration command recorded in `migration-preflight.md`: `php artisan migrate --database=mysql --no-interaction`, with migrator password supplied process-locally. Read-only migration/object inventory command details are in that document; the exact original inspection command transcript is not retained. | MySQL 8.4.11, `127.0.0.1:3310`, `assab_s1_local`; 283/283 migration records, 212 base tables, 2 views, 2 procedures, 179 foreign keys. Runtime PDO connectivity was separately recorded PASS. No seeds. **Historical / read-only inventory command not independently reconstructed.** | Backend application state at execution was not recorded by full SHA; the current provenance row elsewhere in `baseline.md` is a later review state, not the migration/test-run SHA. |

The explicit test safety guard below remains mandatory before every destructive focused PHPUnit run. It resolves Laravel's effective test connection and permits `RefreshDatabase` only on `assab_s1_test`; the migrated `assab_s1_local` schema must never be refreshed.

## CURRENT TEST SAFETY GUARD

Run from the `Assab` repository root. This guard checks Laravel's bootstrapped effective default connection, refuses any connection except MySQL at `127.0.0.1:3310/assab_s1_test`, refuses an explicit `DB_URL`, and opens a read-only connection to confirm the selected schema. It prints only a generic pass/refusal message; it never prints connection configuration or credentials. Set the test credentials in the current PowerShell process from the protected local credential file using the existing private credential-loading procedure; never put them in a tracked file or echo them.

```powershell
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'mysql'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3310'
$env:DB_DATABASE = 'assab_s1_test'
$env:DB_USERNAME = 'assab_s1_test'
# Load DB_PASSWORD process-locally from the protected test credential file. Do not print it.

$php = '..\.tools\s1-01\php-8.4.26\php.exe'
$guard = @'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$name = config('database.default');
$connection = config("database.connections.{$name}", []);
$refuse = static function (string $reason): never {
    fwrite(STDERR, "REFUSED: {$reason}; do not run tests. No credentials were printed.\n");
    exit(70);
};
if (($connection['database'] ?? null) === 'assab_s1_local') {
    $refuse('assab_s1_local is the migrated baseline and must never be refreshed');
}
if (! empty($connection['url'])) {
    $refuse('DB_URL overrides are prohibited for destructive test refreshes');
}
if ($name !== 'mysql'
    || ($connection['driver'] ?? null) !== 'mysql'
    || ($connection['host'] ?? null) !== '127.0.0.1'
    || (string) ($connection['port'] ?? '') !== '3310'
    || ($connection['database'] ?? null) !== 'assab_s1_test') {
    $refuse('effective DB target must be exactly mysql at 127.0.0.1:3310/assab_s1_test');
}
try {
    $pdo = \Illuminate\Support\Facades\DB::connection($name)->getPdo();
    $selected = $pdo->query('SELECT DATABASE()')->fetchColumn();
} catch (\Throwable $error) {
    $refuse('could not verify the approved local test schema');
}
if ($selected !== 'assab_s1_test') {
    $refuse('connected schema is not the approved disposable test schema');
}
fwrite(STDOUT, "PASS: effective target verified as 127.0.0.1:3310/assab_s1_test.\n");
'@
& $php -r $guard
if ($LASTEXITCODE -ne 0) { throw 'Test database guard refused; PHPUnit was not run.' }

# Only after the guard passes, run a focused file. Repeat the guard before each file.
& $php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result --no-progress tests/Feature/ShiftCycleFixesTest.php
```

The guard explicitly rejects `assab_s1_local`, any non-local host, any port other than 3310, URL-based connection overrides, and every unexpected database name. The current `phpunit.xml` declares SQLite defaults without `force="true"`; process-local `DB_*` values therefore remain effective. If PHPUnit configuration begins forcing database environment values, stop and move this check into the test process before any `RefreshDatabase` setup. `RefreshDatabase` / `migrate:fresh` is authorized only against `assab_s1_test`. Never run it against the migrated `assab_s1_local` baseline.

| Focused file | Tests | Assertions | PASS | Assertion FAIL | Setup/environment ERROR | BLOCKED |
|---|---:|---:|---:|---:|---:|---:|
| `tests/Feature/ShiftCycleFixesTest.php` | 6 | 14 | 6 | 0 | 0 | 0 |
| `tests/Feature/ShiftCloseChainTest.php` | 7 | 28 | 7 | 0 | 0 | 0 |
| `tests/Feature/ShiftHandoverVarianceCustodyTest.php` | 5 | 21 | 5 | 0 | 0 | 0 |
| `tests/Feature/SalesVarianceAllocationTest.php` | 8 | 42 | 8 | 0 | 0 | 0 |
| `tests/Feature/HandoverLedgerDateTest.php` | 4 | 11 | 4 | 0 | 0 | 0 |
| `tests/NFR/Security/AuthenticationTest.php` | 11 | 28 | 11 | 0 | 0 | 0 |
| **Total** | **41** | **144** | **41** | **0** | **0** | **0** |

After **each** file, read-only checks returned 283 migration records for both `assab_s1_local` and `assab_s1_test`. Final row counts over all 211 non-migration base tables in each schema found **zero nonempty tables**. The original baseline was never refreshed; only the separate test schema was refreshed by `RefreshDatabase`. No connection to 3307/3308, seed, fixture repair beyond the one field, commit, push or S1-02 work occurred.

Repository search found no other factory writing `map_coordinates`. `Modules/Purchase/database/seeders/PurchaseTestDataSeeder.php:93,104,115,126,137` still writes it; that broad seeder was not invoked and was left unchanged. `examples/UpdatedBranchController.php` and some Purchase services also reference the legacy field, but they are not test factories/fixtures and were not changed. The passing focused tests establish the current implementation baseline only: they do not prove that all current assertions conform to approved BR-01–BR-25 or A01–A18/AC-01–AC-21. Mahmoud's review and the separate requirements work remain. The earlier fixture-error results below are retained as history and are superseded by this rerun.

## Historical disposable MySQL focused-test run — before fixture correction

**Historical status at the time of this failed run: Blocked; not Accepted.** The six-test `RefreshDatabase` blocker described below was later resolved by a separate `assab_s1_test` schema on the isolated MySQL 8.4.11 server at `127.0.0.1:3310`, followed by the passing rerun recorded above. The normal `assab_s1_local` `.env` was not changed. PHPUnit used process-local `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3310`, `DB_DATABASE=assab_s1_test`, `DB_USERNAME=assab_s1_test` and a password read from the protected workspace-local credential file; its contents are not included here. `vendor/phpunit/phpunit/src/TextUI/Configuration/PhpHandler.php:105-121` does not replace an existing process environment value unless `force` is specified; `phpunit.xml` does not set `force`. A Laravel bootstrap query confirmed the effective MySQL connection, database and authenticated account before execution. No tested file has a hardcoded schema name, explicit secondary DB connection or broad seeder invocation. All six use `RefreshDatabase` and therefore ran `migrate:fresh` **only in the disposable test schema**.

The test account was absent before creation and now has `USAGE ON *.*` plus `SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER, CREATE VIEW, CREATE ROUTINE, ALTER ROUTINE ON assab_s1_test.*`. MySQL automatically added `EXECUTE, ALTER ROUTINE` on the two procedures that this account created during migration; those grants are specific to `assab_s1_test` procedures. There is no `GRANT OPTION`, global DDL/admin privilege, or grant on `assab_s1_local` or `mysql` objects. Direct SELECT attempts on `assab_s1_local.migrations` and `mysql.user` were denied (SQLSTATE 42000). The credential file is owned by the operator, with ACL principals limited to the operator, SYSTEM and Administrators; its contents are not included here. The schema had zero tables and routines before the first test run.

Individual command form: the portable PHP executable under `.tools/s1-01/php-8.4.26` ran `vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result [--no-progress] <file>`. Every file was a separate process, with the above process-local DB environment and no seed option. Each nonzero exit below is PHPUnit's exit code 2. `FAIL` here means an executed test method ended in an error; **zero financial assertions failed, because fixture setup stopped first**.

| File | Executed | PASS | FAIL (errors) | BLOCKED/not run | Actual error / classification |
|---|---:|---:|---:|---:|---|
| `tests/Feature/ShiftCycleFixesTest.php` | 6 | 0 | 6 | 0 | All six: SQLSTATE 42S22 / MySQL 1054, unknown `branches.map_coordinates` during `Branch::factory()` in `setUp()` (`:40`). Fixture-source mismatch; 0 assertions. |
| `tests/Feature/ShiftCloseChainTest.php` | 7 | 0 | 7 | 0 | All seven: same branch-factory insert error (`:48`); fixture-source mismatch; 0 assertions. |
| `tests/Feature/ShiftHandoverVarianceCustodyTest.php` | 5 | 0 | 5 | 0 | All five: same branch-factory insert error (first `:30`); fixture-source mismatch; 0 assertions. |
| `tests/Feature/SalesVarianceAllocationTest.php` | 8 | 0 | 8 | 0 | All eight: same branch-factory insert error (`:44`); fixture-source mismatch; 0 assertions. |
| `tests/Feature/HandoverLedgerDateTest.php` | 4 | 0 | 4 | 0 | All four: same branch-factory insert error (`:42`); fixture-source mismatch; 0 assertions. |
| `tests/NFR/Security/AuthenticationTest.php` | 11 | 2 | 9 | 0 | Nine methods using manager/branch fixtures hit the same error; `test_unauthorized_access_without_token` and `test_invalid_token_rejection` passed (3 assertions total). Fixture-source mismatch, not a failing security assertion. |
| **Total** | **41** | **2** | **39** | **0** | All 39 errors share one setup cause; no business-rule behavior was established. |

The exact failed methods are every `test_*` method in the five financial files, plus these nine in `AuthenticationTest.php`: `test_token_expiration_24_hours`, `test_session_timeout_15_minutes`, `test_invalid_credentials_rejection`, `test_role_based_access_control`, `test_permission_granularity`, `test_token_refresh_capability`, `test_logout_invalidates_token`, `test_password_hashing_security`, and `test_cross_user_access_prevention`. Their names and line numbers are in the respective source files and the retained PHPUnit logs. The two passing security methods assert 401 for unauthenticated and invalid-token requests; they do not cover authenticated financial flows.

The source cause is concrete: `Modules/Branch/database/factories/BranchFactory.php:19-25` still emits `location` and `map_coordinates`, whereas `Modules/Branch/database/migrations/2026_01_25_162355_modify_branches_table_add_new_fields.php:136-152` drops those legacy columns after converting location data. Both the migrated baseline and refreshed test schema lack `map_coordinates`. The factory also emits an old `opening_hours` string, while the migration changes that field to TIME; further fixture errors may surface after the first mismatch is corrected. This is a **fixture issue in first-party test support**, not a Windows/MySQL setup failure, proven business-logic defect, or verified outdated expectation. No factory, migration or application code was changed in this task.

At the end of this earlier test attempt, both schemas retained their migration records and the fixture errors blocked financial assertions. That blocker was resolved by the one-field BranchFactory correction documented above. The sections below are historical snapshots and are not the current status.

## Earlier executed baseline evidence

This table preserves earlier recorded outcomes while incorporating the current route-list rerun and the current Dashboard typecheck attempt. The evidence table above distinguishes the new run from historical outcomes:

| Check | Result | Evidence boundary |
|---|---|---|
| Portable tools, locked dependencies, Composer platform requirements | PASS | PHP 8.4.26, Composer 2.10.3, pnpm 10.34.6; no dependency upgrade |
| Laravel package discovery | PASS | Exit 0; valid manifests with 12 package entries and 37 service providers |
| Bounded first-party PHP lint | PASS | 1,173 files, zero syntax errors; vendor/generated files excluded |
| Dashboard TypeScript no-emit check | Historical PASS; current attempt BLOCKED before TypeScript | Earlier TypeScript 5.9.3 exit 0 is retained as historical evidence with command/SHA not independently reconstructed. Current pnpm invocation failed at Node `realpath` with EPERM; details above. No Dashboard dev server/build claim. |
| API route registration | PASS | Current rerun: 1,734 registered routes, exit 0; focused routes cross-checked against explicit records. An earlier 29-signature comparison is historical; registration is not full endpoint acceptance. |
| Baseline migrations on isolated 3310 | PASS | 283/283; recorded 212 base tables, 2 views, 2 procedures, 179 FKs; not upgrade-safety evidence for populated databases |
| Runtime PDO and read-only financial services | PASS | Runtime identity/schema/port verified; empty handover summary and custody balances were zero |
| Prior service-check harness invocation | Corrected diagnostic failure | Exit 255 because the direct checking command omitted Composer autoload; corrected invocation exited 0; no app defect inferred |
| Older infrastructure blockers | Historical, superseded | 3307 root TCP 1045; unsupported mysqlcheck option corrected in recovery test; 3308 identity/access issue avoided through separately approved 3310 instance |
| Dashboard build/dev-server and comprehensive API-to-UI acceptance | NOT RUN | No PASS claim |
| BR/AC/Sprint business acceptance | NOT ACCEPTED | Existing tests are baseline evidence only; Mahmoud owns acceptance |

## Final developer delivery review — 2026-10-07

The six saved PHPUnit summaries independently total **41 passed tests, 144 assertions, 0 failures, 0 errors**. The changed factory passed `php vendor/bin/pint --test Modules/Branch/database/factories/BranchFactory.php` (exit 0, one file); PHP lint also passed. The full application test suite was not run.

Documentation review corrected stale current-status statements, archived superseded machine setup/recovery proposals, removed unnecessary personal workstation identifiers and the attachment-cache path, corrected the API open-shift source status to 201 and the A14–A18 mapping, and retained UNKNOWN/REQUIRES VERIFICATION boundaries. Secret-pattern review found no credential values, API keys, private keys or sensitive personal data in the proposed file set. Workspace-local log/checkpoint paths are retained only as evidence references; their contents and credentials are not proposed for Git. Raw test logs remain local. The Purchase test-data seeder finding is recorded separately and the seeder remains unchanged.

| Lockfile | Recorded unchanged SHA-256 |
|---|---|
| Assab/composer.lock | 225D5318D742B0B70245B603324DD875B5F09816B106CD2191F681F08F529789 |
| dashboard/pnpm-lock.yaml | 6A26FAC861BE4EFFD2CA272076EC92983BB87825FC0802C299F4E54D2C35EE1A |

S1-01 is **Ready for review; not Accepted**. There is no remaining infrastructure or focused-fixture blocker. The S1-01 delivery separated the one-line fixture correction from its eight evidence documents; no local `.env`, credentials, tools, logs, databases or backup files were included. No S1-02 implementation was part of this baseline. The S1-02 and S1-03 documentation tasks were later corrected and are separately marked Ready for review; not Accepted in `task-register.md`.

## Targeted audit closure carried forward

* **R06 — RESOLVED:** route-map/API contract identify inline `Illuminate\Http\Request` validation and the unused end FormRequest; list the native Admin close mutation path and legacy middleware aliases; and preserve `CashierShiftObserver::updating` → `ShiftStartedEvent`/`ShiftEndedEvent` direction and pre-save / transaction timing. Financial writer and transaction traces are source-linked there.
* **R07 S1-01 evidence — RESOLVED:** the executed result is 41 tests / 144 assertions / 0 failures / 0 errors; no text treats it as proof of the future acceptance scenario; S1-01 remains Ready for review, not Accepted.
* **R08 — RESOLVED:** the fail-fast guard resolves Laravel's effective DB connection and allows `RefreshDatabase` only for `assab_s1_test` on loopback port 3310. `assab_s1_local` is explicitly protected as the migrated baseline. Current Dashboard startup uses `pnpm --filter @workspace/mockup-sandbox dev`; historical setup/recovery instructions are labeled non-current. Test evidence and the current 3310/test-schema workflow are separated from historical runs.

## S1-02 contract documentation verification — 2026-10-07

**S1-02 status: Ready for review; not Accepted.** `docs/sprint-01/money-contract.md` was rebuilt from current backend migrations/models/controllers/services/resources/bridges, Dashboard hooks/types/helper/screens and read-only AssabAPP payload models. Source SHAs are recorded in the contract. The field matrix covers gross, net, VAT, cards, apps, `cash_collected`, counted, opening, closing, expected, variance, handover and ledgers, including DB type/default/nullability, casts and current boundary representations. AS-IS gaps are separated from the BR-01–03/05–06/12–14 target.

Checks executed for this documentation task:

| Check | Command/context | Result and limit |
|---|---|---|
| Source reference/field inventory | Read-only source inspection against current migrations, models, active controller/service paths, bridges, resources, Dashboard source and AssabAPP source cited in `money-contract.md`; PowerShell checked `Test-Path` for all 39 cited source files and checked the 13 required money concepts in the contract. | PASS for the static trace and reference-path integrity. This is not runtime API or UI evidence. |
| Contract arithmetic vector | PowerShell command below: decimal-string values convert to integer halalas and back; BR-03 example uses integer arithmetic. | PASS for the documented target arithmetic only. Does not call or test application conversion code. VAT rounding for other gross values remains a review decision. |
| Cross-boundary application acceptance | No test command run; no application/Dashboard source changed and no database/API calls made. | NOT RUN: 115 through persisted DB/API/bridge/UI, explicit zero versus null, fractional values through actual adapters, and opening-count-once remain required S1-10/S1-14 integration evidence. |
| Diff/scope review | `git diff --check`; `git status --short`; `git diff --stat`. | PASS: diff check is clean; exactly `money-contract.md`, `task-register.md`, and `verification.md` are modified. No application, migration, test, Dashboard or AssabAPP source changed. |

```powershell
$ErrorActionPreference = 'Stop'
$culture = [Globalization.CultureInfo]::InvariantCulture
$values = '115.00', '0.00', '0.01', '115.37', '-20.00'
foreach ($text in $values) {
    $sar = [decimal]::Parse($text, $culture)
    $scaled = $sar * 100
    if ($scaled -ne [decimal]::Truncate($scaled)) { throw "unsupported scale: $text" }
    $halalas = [long] $scaled
    $roundTrip = ([decimal] $halalas) / 100
    if ($roundTrip -ne $sar) { throw "round-trip mismatch: $text" }
    Write-Output "$text SAR -> $halalas halalas -> $($roundTrip.ToString('0.00', $culture)) SAR"
}
$gross = [long] 11500; $cards = [long] 5000; $apps = [long] 2500
$opening = [long] 1000; $counted = [long] 3000
$net = [long] 10000; $vat = $gross - $net
$expected = $gross - $cards - $apps + $opening
$variance = $counted - $expected
if ($net -ne 10000 -or $vat -ne 1500 -or $expected -ne 5000 -or $variance -ne -2000) {
    throw 'BR-03 vector mismatch'
}
Write-Output "BR vector: net=$net VAT=$vat expected=$expected variance=$variance"
```

The task-register row moves S1-02 from Needs correction to Ready for review because the assigned source-trace deliverable is complete. This does not mark it Accepted or claim the future round-trip tests passed. Open review decisions are input scale, safe maximum, halala tax rounding and unset-versus-zero/API compatibility design. No migration, seed, database test, business-logic change, Dashboard change or mobile change was made. S1-03 remains separately gated on its documentation review; high-impact implementation remains gated on S1-05 review.

### S1-02 R04 liability-authority documentation correction

`money-contract.md` now contains the explicit **CUSTODY AND SHORTAGE RESPONSIBILITY — AS-IS VS TO-BE** flow. Source inspection covers `HandoverService`, `ShiftVarianceController`, `VarianceCalculationService`, `BranchManagerShiftController::submitDailyReport`, the custody ledger writer/listener, and native Admin `ShiftCloseService` / `Accountant\ShiftController`, plus BR-05–12. The seven-step table distinguishes cash custody, unreceived handover responsibility, provisional allocation, cashier confirmation, employee response, branch-manager final responsibility approval, and accountant reporting/finalization. Each step states actor, current source/state, current authority, approved rule, and gap.

R04 recheck at that review point: **RESOLVED for the documentation deliverable.** The document labels accountant allocation and head final-posting as AS-IS/GAP, not target authority; identifies branch-manager approval before daily submission as BR-09 target; keeps employee objection separate from receipt and non-blocking for handover under BR-10; and states that custody remains with the sender until receipt is confirmed under BR-05/12. It also records the legacy self-variance auto-approval shortcut, missing explicit confirmed-receipt/remainder state, external-factor allocation gap, and absence of a daily-submit liability gate. No fields/events were invented. This was read-only source/doc review: no database/API tests or business behavior were executed or changed. S1-02 remains **Ready for review; not Accepted**; S1-03 had not started at that point and is now documented below.

`git diff --check` passed. All cited source files exist, the complete documentation diff was reviewed, and the credential/token/private-key pattern scan found no matches. No application tests were run because this correction changes documentation only.

### S1-03 schema and compatibility ADR correction

`schema-adr.md` was revalidated against current migrations, models, services, middleware, the S1-02 money contract, Business Rules v2.0, and Sprint Plan v2.0. It resolves audit findings R01, R05, and R07 for the S1-03 documentation deliverable. The ADR now records that Admin shift fields are integer halalas and the legacy bridge performs the SAR conversion once; it removes the unsupported second conversion. It treats each existing handover UUID as the candidate request identity, rejects a shift-only revision key, lists source-backed readers/writers and destructive reset behavior, and requires a duplicate/cardinality data preflight before any shift-level uniqueness decision. Additive revision snapshots and distinct receipt records are proposed; operation/idempotency schema and exact compatibility fields are explicitly deferred to S1-05. Unknown historical receipt state is not backfilled.

Verification for this S1-03 documentation task: source and document review only; no database query, migration, backfill, seed, fixture, or application acceptance test was run. Migration and acceptance checks remain **NOT RUN**. S1-03 is **Ready for review; not Accepted**. The proposed schema remains non-authoritative until reviewed.

### S1-04 state, permission, and revision invariant design

**S1-04 status: Ready for review; not Accepted.** `state-permission-revision.md` is a documentation-only, source-backed matrix for shift/report, handover, allocation, employee response, manager liability approval, daily submission, revision, and receipt states. It maps existing identities and observed ownership/branch checks to the BR-05–10,11–17,24–25 target and explicitly separates AS-IS from proposed behavior. No application code, migration, test, Dashboard, or AssabAPP file changed.

The current `BranchManagerShiftController::submitDailyReport()` path was traced to its route/middleware and handler. It checks the authenticated branch manager’s own completed current-day workday and duplicate submission, but does not require a complete current-revision shortage allocation or branch-manager final-liability approval. The S1-04 deliverable defines the minimal target guard and does not implement it. It also records the two-rejection cap and destructive handover reset paths as AS-IS conflicts with BR-15–17 and BR-24.

R02 remains **PARTIALLY RESOLVED**: the state design distinguishes cash-channel sales from independent counted-cash evidence, calls out the bridge-synthesized actual, and does not claim the 115/50/25/10/30 application proof. R05 remains **PARTIALLY RESOLVED**: the state design does not assume one row per shift or a deployed revision/receipt schema; duplicate/cardinality preflight is still NOT RUN, and endpoint-specific idempotency/retry decisions remain S1-05. S1-01/S1-02/S1-03 remain Ready for review; not Accepted.

Static source-reference, route/scope, transition consistency, Business Rule mapping, revision invariant, daily-submit guard, and R02/R05 carry-forward checks: **PASS**. Runtime permission enforcement, new states, revision/receipt migrations, DB preflight, application acceptance, replay/concurrency, Dashboard, and mobile compatibility: **NOT RUN**. This is the S1-04 as-of record; S1-05 work is recorded below.

## S1-05 API blueprint and design review package — earlier pre-correction record, 2026-10-07

**Status: Ready for Mahmoud design review / Not Accepted.** This task changed documentation only. `docs/sprint-01/api-contract.md` is the primary blueprint; this section supersedes the earlier statement that S1-05 had not started. The former source-trace and test evidence remain historical AS-IS evidence. Nothing here claims runtime enforcement or accepts S1-05 on Mahmoud's behalf. S1-06 remains blocked until the design review is complete.

### Files and references inspected

- Sprint plan v2.0 and Business Rules v2.0 English reference under `../project-docs/`.
- `baseline.md`, `route-map.md`, `money-contract.md`, `schema-adr.md`, `state-permission-revision.md`, `api-contract.md`, `task-register.md`, and this verification file.
- Backend route registrations: `Modules/Shift/routes/api.php`, `Modules/Admin/routes/api.php`; relevant Shift end/handover/variance and BranchManager controllers/services; Admin BranchCompany and Accountant Shift controllers, `ShiftCloseService`, `ShiftPresenter`, bridge/listener and idempotency middleware; current relevant migrations, models, observers/listeners, and existing focused test sources.
- Read-only compatibility sources: Dashboard shift API hooks, types, money helpers, operation hooks and consumers under `../dashboard/`; AssabAPP end/handover request models and datasource under `../AssabAPP/`.
- `Assab-Backend-Commit-Audit-2026-10-07-1.md` and `Assab-S1-01-S1-02-S1-03-Audit-2026-10-07.md` under `../project-docs/`, including carry-forward C01–C03/R02/R05 findings.

### Earlier design proposals (corrected/superseded below)

- SAR legacy fields convert to integer halalas only at the explicit adapter; Company/Admin `*Halalas` remain integer halalas. Gross includes VAT; net is rounded half-up to the nearest halala from `gross×100/115`; VAT is the residual. Accept two decimal SAR digits, reject excess precision, and use verified per-column limits: DECIMAL(12,2) = SAR 9,999,999,999.99 and `branch_manager_shifts.handover_amount` DECIMAL(10,2) = SAR 99,999,999.99; validate every participating column.
- Null/omitted evidence is unknown; explicit zero is evidence only when the relevant count/receipt confirmation is explicitly present. Physical count remains independent of `cash_collected`; the current legacy bridge synthesis is forbidden as evidence. AssabAPP cannot satisfy the new count contract without future client work or an explicitly approved exception; no runtime closure is claimed.
- Stable shift/report aggregate owns immutable revisions without requiring a handover. Each revision allows zero or multiple independently identified handover requests. Receipt has immutable request/revision/recipient/destination identity and exact requested amount; an intentional partial transfer uses its own smaller request and leaves the remainder with sender. A mismatched unchanged request must be rejected and corrected. Opening applies exactly once per receipt/receiving shift, with no settings fallback.
- Report close, receipt, allocation, employee response, manager liability approval and accountant review remain separate facts. Branch manager final approval for each current shortage revision is required before daily submit; accountant is not liability authority; objection does not block handover; no automatic payroll deduction; surplus is branch-only.
- Replay keys are bound to tenant/branch/actor/method/route/resource/revision/canonical payload; authorization precedes replay. Permanent business-effect identity prevents duplicates beyond the 90-day replay cache. Lock order and essential same-transaction write set are specified; projections/notifications run after commit only.
- Existing legacy and Admin response envelopes, route families, fields and units are preserved as the compatibility boundary. New target evidence fields are additive, and unsupported old clients fail explicitly rather than having values inferred.

### Verification results and boundaries

| Check | Result | Evidence / limit |
|---|---|---|
| Source-reference verification | PASS | Primary plan, business rules and both audit files exist and were read; current route/controller/service/model/migration and frontend references were inspected. No runtime guarantee inferred. |
| Route/request/response trace | PASS | Legacy `/api` and `/api/v1` Shift aliases, role groups, Company/Admin close/open/allocation, daily submit, operation review paths, existing success/error envelope families and consumer payloads traced against route files and controller source; target additions are labeled design. |
| Money-unit consistency | PASS | 11,500/5,000/2,500/1,000/3,000-halalas vector recalculated by integer arithmetic; target net 10,000, VAT 1,500, expected 5,000, variance −2,000. Conversion count and null/zero rules reviewed. No app code run. |
| State/revision consistency | PASS | C03 covered by aggregate-owned report revisions; optional request cardinality, current revision, stale-decision rejection, supersession and immutable receipt reviewed against S1-03/S1-04. |
| Permission review | PASS | Target roles/scopes checked against route middleware and S1-04 matrix; accountant retained as review, manager as liability authority. Runtime enforcement NOT RUN. |
| Idempotency design review | PASS | Actor/resource/route/revision/payload scope, authorization-before-replay, processing/completed/key-reuse/lost-response semantics, expiry, and durable business-effect uniqueness specified. No replay/concurrency test run. |
| Transaction/writer-authority review | PASS | Deterministic lock order, retry policy, required atomic facts, post-commit projections, and single-authority/projection mapping reviewed against route-map. No DB transaction/concurrency execution. |
| Compatibility review | PASS | Dashboard Admin halalas and Sanctum/tenant scope recorded; AssabAPP payload omission, legacy doubles, recipient-key mismatch and no-count limitation retained as exact GAP. Dashboard/mobile runtime acceptance NOT RUN. |
| Audit carry-forward | PASS | R02 remains a runtime compatibility concern; C03 has a design resolution; C01/C02 and R01–R08 evidence treated as findings, not authority for redesign. |
| `git diff --check` | PASS | Executed after edits; no whitespace errors. |
| `git status --short` / allowed-file check | PASS | Only `docs/sprint-01/api-contract.md`, `docs/sprint-01/task-register.md`, and `docs/sprint-01/verification.md` changed. |
| Full `git diff` review | PASS | Reviewed complete three-file documentation diff; no application/migration/test/Dashboard/AssabAPP source changed. |
| Secret scan | PASS | Targeted token/password/private-key patterns returned no matches in changed files. |
| Conflict-marker scan | PASS | Standard merge-conflict marker patterns returned no matches in changed files. |
| Unrelated-file scan | PASS | Changed-file allowlist matches exactly the three permitted documentation files. |
| Runtime routes/tests/migrations/concurrency/UI acceptance | NOT RUN | Documentation/design-only task; no runtime claim. |

### Independent final audit

All sixteen S1-05 post-edit audit assertions are individually listed in the final audit table in `api-contract.md` and are PASS as **design consistency checks only**. No assertion is a runtime PASS. The amount ceiling is bounded by existing legacy DECIMAL(12,2) evidence and JS/PHP integer limits; implementation must stop if an actual deployed schema is narrower. No application, migration, test, Dashboard, or AssabAPP file changed.

## Consolidated S1-01–S1-05 correction pass and second read-only re-audit — 2026-10-07

This section **supersedes the earlier S1-05 design descriptions above** wherever they described short confirmation of an unchanged handover request, treated technical numeric choices as finalized, or lacked route/session specifics. It records a documentation/source audit, not execution of target APIs. Starting backend HEAD was `af9c9781b20aae74a02df802bb9b1d550ef79d53` on `sprint/01-financial-foundation` with a clean worktree; all S1-01–S1-05 commits were ancestors. S1-01–S1-04 remain Ready for review / Not Accepted. Corrected S1-05 is Ready for Mahmoud design review / Not Accepted. S1-06 remains blocked and has not started.

One model is now used in `schema-adr.md`, `state-permission-revision.md`, and `api-contract.md`: stable report aggregate, bootstrap revision 0, first snapshot revision 1, correction N→N+1, and zero-to-many requests per report revision with each request linked to exactly one original revision. Handover and liability decisions do not advance the report revision. A confirmed receipt belongs permanently to one request and one destination. A mismatched count requires reject → recount → report/request correction → **new exact request** → full confirmation. An intentional partial custody transfer uses a separately requested exact portion; the rest stays sender responsibility. Current-revision decisions are checked under lock, except completed authorized same-key/same-payload replay returns the stored result first. The target permanent money-effect identity is tenant/company + branch + request UUID + effect type + receiving custody/shift identity; the receipt UUID and replay key serve different purposes.

| Finding | Before (latest consolidated audit) | After: second read-only audit | Evidence and boundary |
|---|---|---|---|
| C01 | Short confirmation of unchanged request conflicted with BR-13/14 | RESOLVED | State and API now reject mismatch; 6,150→6,100 example includes reject/correct/new request/confirm; intentional partial uses its own request. |
| C02 | Report revisions were ambiguously tied to handover | RESOLVED | S1-03/04/05 stable aggregate; end-only/native close can have no request. |
| C03 | Competing request/revision cardinalities | RESOLVED | One-to-many revision→request; each request one original revision; receipt never rebound. |
| C04 | Cashier/employee actions mapped to accountant route | RESOLVED | S1-04 and API §7 distinguish AS-IS partial routes from explicit target cashier/employee/manager commands. |
| C05 | No paired route examples | RESOLVED | API §11A has 15 paired request/success/error cases with auth, key, revision and unit context; nonexistent commands are marked target routes. |
| C06 | Dashboard session contract absent | RESOLVED | API §11 traces actual auth routes/controller/client and assigns atomic rotation/revocation/retry to S1-12/13. |
| G01 | Receipt UUID alone did not prevent duplicate effect | PROPOSED — MAHMOUD APPROVAL REQUIRED | API §9 distinguishes record/replay/effect and proposes one durable tenant/branch/request/effect/destination tuple. |
| G02 | Receiving shift/open identity circular or absent | PROPOSED — MAHMOUD APPROVAL REQUIRED | API §6 defines existing shift, pre-open intent, manager custody, zero, missing destination and cross-branch guards. |
| G03 | First expectedRevision and advancement unclear | RESOLVED | S1-03/04/05 bootstrap 0→1 and command matrix; only report submit/correction advance. |
| G04 | Financial writer map generic | PROPOSED — MAHMOUD APPROVAL REQUIRED | API §10 names source writers/destinations and proposed authoritative command writer for every required fact, with bridge/listener risk. |
| G05 | Liability actor/route reachability unclear | RESOLVED | S1-04 and API §7 show registered partial AS-IS routes/gates and target route/actor map; accountant is review-only for liability. |
| G06 | Stale check could defeat completed replay | RESOLVED | API §10 branches completed/same-key-changed/in-progress before stale new-intent validation; §11A cases 13–15. |
| G07 | Startup/test handoff scattered | RESOLVED | `baseline.md` current reproducible handoff points to protected test-schema guard in this file; no archived reset path revived. |
| G08 | Stale task prose | RESOLVED | `task-register.md`, S1-04 status text and this supersession retain S1-01–04 review-only, S1-05 design-review-only, S1-06 blocked. |
| G09 | Migration/populated-data proof absent | DEFERRED CORRECTLY | No migration, backfill, FK/cardinality or rollback execution; later implementation/migration gate with preflight. |
| G10 | API/DB/UI/concurrency acceptance absent | DEFERRED CORRECTLY | Runtime routes, backend acceptance, concurrency, Dashboard and mobile validation **NOT RUN**; later S1-06–15 evidence required. |

Second-pass consistency checks are design/read-only results: BR-01–17 and BR-24/25 meaning is preserved; AC-01–13 and AC-18/20/21 have compatible target contracts, **not runtime PASS**. Count is independent of sales; receipt is independent of submission/liability; surplus is branch-only; manager approves current shortage allocation; accountant cannot assume employee liability authority; confirmed receipt is immutable; failed essential ledger/audit writes roll back with the source fact; one named writer owns each target effect. `schema-adr.md`, `state-permission-revision.md`, and `api-contract.md` were compared directly for aggregate/revision/request/receipt/mismatch/partial/destination/liability/replay/effect/writer meaning. The numeric rounding, precision, ceiling, JSON boundaries, pre-open receiving token, receipt-effect tuple and proposed target writer storage remain **technical proposals for Mahmoud**. The only potential Mohamed decision is a future exception allowing report close without independent physical count; this pass grants none.

Historical S1-01 focused tests and route-list checks above were **not rerun** for this documentation correction. G09 and G10 remain respectively `SAFE TO DEFER TO IMPLEMENTATION / MIGRATION GATE` and `RUNTIME VALIDATION ONLY`. No S1-06 application implementation was performed.

## Final documentation correction verification — 2026-10-07

Documentation/source reconciliation only. Starting HEAD: `9a8e0ff75dc70e542420a8e4e3f78b46d7cd76f0`. The authoritative Mahmoud handoff and all eight existing sprint documents were read. No application/runtime tests, migrations, database operations, Dashboard edits, or AssabAPP edits were made.

| Audit assertion | Result |
|---|---|
| No receipt computes opening 0; evidence state remains distinct | Documented |
| Configured opening is never receipt evidence | Documented |
| Start independent of confirmation; late receipt joins same shift once | Documented; current report-submission behavior follows approved transfer decision |
| Manager opening uses personal sales-cash, never expense custody | Documented; storage remains proposed |
| 500/480 reject/correct/resubmit/confirm; actual shortage remains sender-side | Documented |
| Report submission differs from receipt confirmation | Documented |
| Server submit trigger/set identified; no current client caller | Documented; client wiring deferred |
| Branch Manager correction authority and safeguards | Approved and synchronized in current working docs |
| D4–D8 status | D4–D7 proposals remain; D8 not authorized; D9 resolved for the transfer cases in this update |
| Legacy variance keys unchanged; no mobile refresh invented | Documented as proposal/target |
| No new employee system, manager-workday system, or approval layer | Preserved |
| No runtime/application PASS claimed | Confirmed |

S1-15 regression map: RX-01 → TX-02 / FIN-06; RX-02 → SEC-01; RX-03 → AUTH-02. Each regression requires status, amount, and relevant row-count assertions; `assertTrue(true)` is not evidence. MySQL is required for locking/concurrency; SQLite in-memory is insufficient. Tests remain NOT RUN.

This correction is **READY FOR MAHMOUD DOCUMENTATION REVIEW / NOT ACCEPTED**. D1/D2 applied; manager correction and transfer decisions synchronized; D4–D7 proposed; D8 not authorized; D9 is resolved for the newly approved cases. H1/H2 were not implemented and remain unauthorized. S1-06 was not started.

## Approved decision synchronization verification — 2026-10-07

The current working documents now reflect the user-approved decisions: Branch Manager direct report corrections with prior/new values, reason, actor/time, cashier notification, no cashier approval, immutable receipts and one recalculation; pending incoming transfer does not block shift report submission, drawer cash is counted, sender remains responsible, pending status is explicit/not surplus, and accountant daily submission waits for required transfer completion; excessive manager sales-cash transfers are rejected after reserved/committed balance checks without movement or automatic shortage, and cash must be legitimately recorded before transfer. Earlier D3/D9 entries in the historical handoff remain historical and are not current decision state. The only authoritative Business Rules copy identified is outside the repository write boundary; synchronization there remains required outside repository.
**REFERENCE UPDATE REQUIRED OUTSIDE REPOSITORY.** The single current approved business-rules copy found is `D:\claude\AssabERP\project-docs\Assab-ERP-Cash-Cycle-Business-Rules-v2.0-EN.md`; it is outside the writable repository root, so it was not edited. The current Sprint plan copy, `D:\claude\AssabERP\project-docs\Assab-ERP-Sprint-01-Agent-Implementation-Plan.md`, is also outside the writable root and is an execution aid under D1, not the business-rule authority; it was not edited. No same-name duplicate of the v2.0 Business Rules file was found in the workspace search. The prior final handoff/audits are historical records and were intentionally left unchanged. Update the authoritative Business Rules version/change log and any needed current execution-plan cross-reference in the owning repository/location before declaring reference synchronization complete.
