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

## S1-06 calculation implementation verification — 2026-10-07

Starting state: `sprint/01-financial-foundation` at `31af7c8fed81897ae38e800a214183c879e37c13`, clean. Implementation commit: `6fcce32c0f921d51821de76114c193cf5343bd27` (`fix(S1-06): unify inclusive VAT and shift cash calculations`). Push to `origin sprint/01-financial-foundation` was attempted once and failed with `getaddrinfo() thread failed to start`; no retry was made. Remote SHA is unverified. The user directed continuation from the completed source trace and narrowed the prior blocker: D5 is a technical decision; legacy evidence gaps remain compatibility/integration limitations.

`app/Support/ShiftFinancialCalculator.php` is the shared semantic calculation path. It accepts integer-halalas gross, card, app, confirmed opening and counted values; returns net, residual VAT, expected, signed variance, shortage, surplus and variance type. `sarToHalalas()` rejects unsupported decimal precision. Non-exact tax values expose `roundingPendingD5`; the implementation retains the prior nearest-halalah compatibility behavior for two-decimal SAR storage. **Historical status at that checkpoint — D5 approval was not yet recorded; this statement is superseded by Mahmoud's later D5 approval below.** The mandatory exact vector returns net 10,000, VAT 1,500, expected 5,000, variance −2,000 and shortage 2,000 halalas.

VAT extraction in cashier save/preview and branch-manager update/summary/resource paths now uses the shared VAT calculation, replacing incorrect `gross × 0.15` computations. Cashier variance and Admin close were intentionally not redirected: the legacy model has no independent counted-cash/confirmed-opening fields; Admin `opening_float` is not receipt evidence, and `BridgeLegacyCashierShift` synthesizes its Admin count from cash sales plus opening. These are S1-10/S1-11 integration gaps. No bridge value is claimed as Contract v2 evidence. No migration, Dashboard, AssabAPP, H1/H2, D4 or later-task implementation was added.

| Check | Result | Evidence / limit |
|---|---|---|
| PHP syntax for changed PHP | PASS | Portable PHP 8.4.26 `php -l` on calculator, affected Shift files and focused unit test. |
| Exact calculator runtime vector and cases | PASS | Direct PHP runtime verified mandatory vector, balanced/surplus/sign handling, zero opening, independent count, manager card correction, exact SAR↔halalas conversion and the legacy `roundingPendingD5` result key. |
| Focused S1-06 PHPUnit | PASS — 9 tests, 37 assertions | Latest executed result supplied by the user: PHP 8.4.26 / PHPUnit 12.4.0. Command: `php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result --no-progress tests/Unit/ShiftFinancialCalculatorTest.php`. This supersedes the earlier autoload/environment limitation. Tests were not rerun during this evidence-only update. |
| Pint on S1-06 changed PHP files | PASS — 9 files | Latest executed result supplied by the user for the nine files listed below; supersedes the earlier temporary-directory/environment limitation. |
| Full-project Pint | FAIL — 1932 files checked, 2 pre-existing/out-of-scope style issues | Issues are in `Modules/BrandOwner/routes/api.php` and `Modules/FixedAssets/app/Services/HandoverService.php`. Neither file was modified by S1-06 or this evidence update. This is NOT an S1-06 blocker: both issues are outside S1-06 changed files, and all nine S1-06 changed PHP files pass Pint. |
| `git diff --check` | PASS | No whitespace errors in the current diff. |
| Fractional tax acceptance | Historical status at that checkpoint — superseded by Mahmoud's later D5 approval | The checkpoint did not yet record D5 approval; see the current approved policy below. |
| Legacy count/opening evidence | GAP — LATER INTEGRATION | Current legacy contract and bridge do not provide source-backed independent count/confirmed receipt inputs. Test factories use the shared VAT calculator so generated completed-shift fixtures follow the corrected inclusive-tax behavior. |
| S1-06 status | READY FOR REVIEW / NOT ACCEPTED | Focused PHPUnit and changed-file Pint pass. Full-project Pint has only the two out-of-scope issues noted above. D5 fractional acceptance and legacy evidence integration remain deferred. S1-07 not started. |

The successful S1-06 Pint run covered exactly these nine PHP files:

- `Modules/Shift/app/Http/Controllers/BranchManagerShiftController.php`
- `Modules/Shift/app/Http/Requests/EndShiftRequest.php`
- `Modules/Shift/app/Services/BranchManagerShiftService.php`
- `Modules/Shift/app/Services/ShiftEndService.php`
- `Modules/Shift/app/Services/ShiftFinancialService.php`
- `Modules/Shift/app/Transformers/BranchManagerShiftResource.php`
- `Modules/Shift/database/factories/CashierShiftFactory.php`
- `app/Support/ShiftFinancialCalculator.php`
- `tests/Unit/ShiftFinancialCalculatorTest.php`

## Targeted F1–F3 audit correction — 2026-10-07

This current evidence supersedes earlier runtime/test-runner and external-reference update limitations, without rewriting historical audit/handoff files. Starting repository `D:\claude\AssabERP\Assab`, branch `sprint/01-financial-foundation`, HEAD `e8fc27577aeb75bb643e87c825bb0394424832e8`, and clean worktree matched the requested gate. Local origin tracking matched HEAD. PRE-GATE PASS for local checks; live remote verification was unavailable because `git ls-remote` returned `getaddrinfo() thread failed to start`. Published baseline is supplied by the user and agrees with local tracking; fresh remote equality is not claimed.

F1: the pure calculator adds optional nonnegative integer-halalas pending incoming physically included in count, default zero and no greater than count. Expected still uses confirmed opening only; reconciled count subtracts pending classification. Source identity, physical presence and pending/confirmed exclusivity remain trusted-caller responsibilities. SAR cases A/B/C are in the money/API contracts; tests establish before/after confirmation without double counting, no adjustment for a request without physical cash, and unchanged mandatory original vector. No route receives fabricated inputs and no end-to-end transfer integration is claimed.

F2: original cashier submission retains complete-allocation confirmation. Manager correction and its resulting allocation/final liability do not require cashier re-approval. Contracts include cards500→400, expected+100, variance−100/shortage100, immutable receipts, audit/notification and single recognition. Runtime liability enforcement remains S1-07/S1-11.

F3: shift report submission is allowed with pending incoming transfer. Daily submission waits for every required transfer associated with the server-derived included shift/report set to complete the existing confirmed-receipt condition, even when shortage liability is approved. Unrelated out-of-scope pending transfers do not block. No new state or daily runtime gate was implemented; S1-07 owns enforcement.

Historical status at that checkpoint — superseded by Mahmoud's later D5 approval: the then-current Sprint/repository documentation and external authoritative reference search had not found a later approval. The then-existing non-exact rounding was unchanged. See the current approved policy below.

| Check | Result | Evidence |
|---|---|---|
| Focused PHPUnit | PASS — 15 tests, 54 assertions | Portable PHP 8.4.26, PHPUnit 12.4.0; `php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result --no-progress tests/Unit/ShiftFinancialCalculatorTest.php`; executed with filesystem access needed by the installed autoloader, no DB boot. |
| Changed PHP syntax | PASS — 2 files | `php -l` on calculator and focused test. |
| Changed-file Pint | PASS — 2 files | `php vendor/bin/pint --test app/Support/ShiftFinancialCalculator.php tests/Unit/ShiftFinancialCalculatorTest.php`. |
| Diff whitespace | PASS | `git diff --check`. |
| Full-project Pint | NOT RERUN | Prior two unrelated style findings remain outside scope; see historical evidence above. |
| Scope review | PASS | Calculator/tests and affected documentation only; no migrations, Dashboard, AssabAPP, H1/H2, D8 or unrelated refactor. S1-01–S1-06 not restarted; S1-07 not started. |

| Sprint file | Disposition | Reason |
|---|---|---|
| baseline.md | NO CHANGE NEEDED | Baseline evidence remains historical/source inventory. |
| route-map.md | NO CHANGE NEEDED | AS-IS routes/writers unchanged; no integration added. |
| money-contract.md | UPDATED | F1 formulas/cases, F2 scope, F3 gate and the historical D5 status as recorded at that checkpoint. |
| schema-adr.md | NO CHANGE NEEDED | No schema introduced or approved; existing evidence gaps remain. |
| state-permission-revision.md | UPDATED | Original/manager distinction and scoped required transfers. |
| api-contract.md | UPDATED | F1 semantic contract and corrected F2/F3 guards/examples. |
| task-register.md | UPDATED | Current correction status, tests and deferred ownership. |
| verification.md | UPDATED | This executed evidence and scope audit. |

**BUSINESS RULES REFERENCE UPDATED:** `D:\claude\AssabERP\project-docs\Assab-ERP-Cash-Cycle-Business-Rules-v2.0-EN.md`, outside Assab Git. BR-03 now defines pending/reconciled count with three examples; BR-07/§15 distinguish manager correction from original confirmation and define scoped required transfers. Version 2.0 and prior history are retained with a dated clarification entry. This resolves the earlier external-reference-update note for these decisions; the external file is not part of the Assab commit.

One new correction commit is intended over the published baseline. Push/remote outcome is reported after committing in the final delivery; no unexecuted push result is claimed here. **AUDIT F1–F3 CORRECTIONS COMPLETE / READY FOR REVIEW** within the pure-calculator/contract scope; runtime lifecycle acceptance remains deferred. S1-07 STARTED: NO.


## Final targeted S1-06 validation correction — 2026-10-07

PRE-GATE PASS for local checks: repository D:\claude\AssabERP\Assab, branch sprint/01-financial-foundation, HEAD f06964c18a60b2a44806fe5a8de74461d52bdaa9, clean tree and matching origin tracking; user supplied remote verification at the same SHA. Fresh ls-remote failed with getaddrinfo() thread failed to start, so live remote equality is not independently re-claimed. S1-07 not started.

Source trace: registered cashier/branch-manager shifts/{shift}/end and end-with-handover → ShiftEndController inline Validator → ShiftEndService → ShiftFinancialCalculator::calculateVatInclusiveSales → sarToHalalas. Before correction numeric|min:0 admitted 1.001; the calculator rejected it and the end catch could return 500. calculate-sales uses the same service and lacked a local catch. Branch-manager workday/end and daily-close update also accept totals/breakdown sales that reach the calculator. EndShiftRequest exists separately and is now aligned without pretending it controls the registered end route.

Shared request rules now check ordinary SAR decimal shape, at most two fractional digits, nonnegative amounts except signed variance, and the exact DECIMAL(12,2) / manager-handover DECIMAL(10,2) ceilings. Normal existing validation responses are 422; no broad exception masking was added. Calculator precision exceptions remain defensive. Inspection also exposed valid large JSON floats rejected solely by multiplication representation error; scale-aware machine epsilon fixes that without changing tax rounding, while large three-decimal values remain rejected.

| Check | Result | Evidence |
|---|---|---|
| Focused calculator | PASS — 17 tests / 58 assertions | Original 15 / 54 preserved plus large valid-float/string equivalence and excess-precision rejection. |
| Registered-route/request feature tests | PASS — 26 tests / 234 assertions | End and handover aliases reject 1.001 and other precision/shape/range failures before service; preview returns real calculation; upper valid input passes; nested aggregator/allocation/manager sales and smaller manager boundary checked. |
| Execution totals | PASS — 43 tests / 292 assertions | Combined run: 42 / 284; subsequently added nested route case: 1 / 8, executed separately. |
| HTTP validation failure | PASS | Actual end route 1.001 → 422 with total_sales error; mocked service expects no end/calculation calls. |
| Valid end input | PASS past validation | Dry-run missing-shift lookup yields 404, not validation failure; no business rows written. This is not persistence/lifecycle acceptance. |
| PHP syntax / changed-file Pint | PASS — 7 PHP files | Calculator, shared rules, two controllers, request and two test files. |
| Whitespace / scope | PASS | git diff --check; no migrations, Dashboard, AssabAPP, H1/H2, D8, liability/daily gate or unrelated refactor. |

Executed with portable PHP 8.4.26 / PHPUnit 12.4.0. Commands: php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result --no-progress tests/Unit/ShiftFinancialCalculatorTest.php tests/Feature/ShiftMoneyValidationTest.php; then the feature file with --filter test_nested_amount_validation_on_registered_routes. Tests isolate authentication middleware; nested reference-existence checks are mocked; valid end lookup uses DB pretend, no migrations or business writes. Pint --test and php -l cover all seven changed PHP files.

Audit: **V1 PASS; V2 PASS; V3 PASS; V4 PASS; V5 PASS** for request-level per-column limits from migrations (no deployed-schema inspection or aggregate/persistence certification). **F1 PASS; F2 PASS; F3 PASS** in current money/API/state documents and the external Business Rules v2.0 reference; no changes to those decisions. State-permission-revision.md and the external reference need no update. **Historical status at that checkpoint — superseded by Mahmoud's later D5 approval:** the current docs/reference search had not found an approval at that time. Half-up net / residual VAT and `roundingPendingD5` were then recorded as compatibility behavior; D5's later approved policy is stated below.

S1-06 overall: **READY FOR REVIEW / NOT ACCEPTED** within this correction scope. Later integration/liability/daily-close tasks remain deferred. One new commit is intended; post-commit push outcome is reported in final delivery, without rewriting published history. S1-07 STARTED: NO.

## Mahmoud D5 approval — current status

Mahmoud explicitly approved D5 after the validation correction. Current status: **D5 APPROVED**. Approved policy: monetary SAR input maximum two decimal places; reject excess precision with HTTP 422 before calculation; validate each amount against actual participating DB column limits; calculate internally in integer halalas; calculate VAT-inclusive net with half-up rounding; set VAT to gross minus rounded net. Existing evidence remains: 1.001 → 422 and 43 tests / 292 assertions. F1/F2/F3 remain PASS. Historical verification entries above preserve their as-of status and are superseded by this approval. The legacy result key roundingPendingD5 is a retained name, not a pending decision. S1-07 has not started.

## S1-07 regression-gate recovery and source-trace disposition — 2026-10-08

Starting state rechecked: repository `D:\claude\AssabERP\Assab`, branch `sprint/01-financial-foundation`, HEAD `a210587457a761846dcbac27f19f9cee426b6a08`, clean tree before this documentation update and local tracking ref equal. Portable PHP `D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe` is PHP 8.4.26. `vendor/autoload.php` exists and a direct `require` prints `AUTOLOAD_OK`. PHPUnit itself reports the project autoloader as unreadable through this sandbox's Windows file-access check, so the focused run used a temporary bootstrap under `%TEMP%` that requires the same existing project autoloader; no dependency or project bootstrap was modified. PHPUnit 12.4.0 ran `tests/Unit/ShiftFinancialCalculatorTest.php` and `tests/Feature/ShiftMoneyValidationTest.php`: **43 tests / 292 assertions, PASS**. This includes actual route validation cases proving `1.001` returns 422 before service/calculator, the exact 115/50/25/10/30 vector, F1 pending-incoming before/after confirmation and no physical-arrival assumption, manager-card correction calculation, expense independence in expected cash, and surplus calculation. A direct runtime check also confirmed D5 fractional half-up behavior: gross 100 halalas → net 87, residual VAT 13. `php artisan route:list --path=variance --except-vendor` completed and showed the legacy Shift variance aliases and separate Admin accountant allocation routes.

### Prior findings R01–R08

| Finding | Status | Evidence / ownership |
|---|---|---|
| R01 — `1.001` is rejected before calculation with HTTP 422 | PASS | `ShiftMoneyValidationTest` route cases assert 422 and that the service is not called. |
| R02 — D5 status and calculation policy | PASS | Current money/API contracts state D5 APPROVED. Older verification checkpoints are now explicitly labeled historical and superseded, without changing their as-of results. |
| R03 — mandatory 115/50/25/10/30 vector | PASS | Calculator focused test: net 10,000, VAT 1,500, expected 5,000, variance −2,000, shortage 2,000 halalas. |
| R04 — F1 pending incoming semantics | PASS at calculator/contract scope | Focused cases prove pending counted cash is subtracted from reconciled count, confirmed opening alone affects expected cash, confirmation does not double count, and an unarrived request has no effect. Route/source evidence remains an S1-10 integration boundary. |
| R05 — F2 manager correction | GAP — S1-07/S1-11 | The financial calculator correction vector passes. The current branch-manager cashier-breakdown writer changes financial values without appending a correction revision, preserving the full prior/new/reason/actor/time record, or notifying through a correction-specific workflow. Do not claim lifecycle enforcement. |
| R06 — F3 scoped daily-submit gate | GAP — S1-07, with S1-10/S1-11 evidence dependency | Contracts define the server-derived included report/transfer scope. `submitDailyReport` currently sets the submitted flag without shortage-liability or required-transfer guards. `getShiftHandovers(..., 'to_manager')` supplies a branch/date/seven-day scope, but is not a proven complete report-revision/receipt set. |
| R07 — expense custody does not reduce cashier expected cash | PASS at calculation boundary | `ShiftFinancialCalculatorTest::test_commission_and_branch_expenses_do_not_change_expected_cash` passes; expense custody is not a calculator input. |
| R08 — surplus is branch-only, with no employee liability or sales increase | GAP — S1-07/S1-10 | The shared calculator produces a separate positive surplus and does not change gross sales. The legacy variance service also records positive `Over` variances as responsibility rows/default cashier assignments; Admin close avoids a positive-variance debit but remains a separate workflow. Correct classification in the legacy route depends on S1-10's independent count and signed-variance integration. |

**Prior-regression gate: CLEARED.** The focused S1-06 validations/calculator have no detected regression. R05/R06 and the legacy side of R08 are target implementation gaps assigned to S1-07 or explicitly deferred integration owners, not regressions introduced by S1-06. This clearance authorizes the S1-07 source/design review; it does not claim the target is implemented.

### S1-07 source trace and ownership review

| Path | Current behavior | Problem | S1-07 disposition |
|---|---|---|---|
| Legacy variance routes: `Modules/Shift/routes/api.php`; `ShiftEndController@endShiftOnly`; `ShiftVarianceController@recordVariance` | Registered cashier and branch-manager aliases call inline request validators then `VarianceCalculationService::recordVariance`. End-with-handover and end-only are separate writers. | No explicit allocation confirmation field; standalone route loads shift by ID without owner/branch check; callers validate IDs only by global `exists:cashiers`; service uses floats, deletes existing detail rows, assigns current-cashier remainder, and accepts external/mixed remainder. | Requires complete branch-scoped S1-07 validation. Do not write until source variance/state ownership can be safely represented. |
| Variance calculation / `ShiftEndService` / `CashierShift::calculateVariance` / handover service | Legacy formula is `total_sales - (cash_collected + card_payments + apps)`; positive is treated as shortage/`SHORT`. Handover approval can then re-dispatch `VarianceRecorded`. | Does not use independent counted cash or confirmed opening from the approved equation and has opposite shortage sign. | GAP owned by S1-10 integration; do not invent count/opening evidence or reverse sign based on ambiguous legacy `cash_collected`. |
| Allocation model and schema | `shift_variance_details.responsible_cashier_id` has a foreign key to `cashiers`; a row has one `responsibility_status` and reviewer fields. `CashierShiftHistory` is an append-only action/old/new text record. | A BranchManager identity cannot be a valid assignee in the allocation FK. Employee accept/object status and final manager approval currently write the same status; manager approval updates all rows, overwriting employee responses. | Current S1-03 schema ADR is “Ready for review; not Accepted” and does not authorize new allocation identity/state schema. No migration added. A typed identity and distinct state representation require an accepted ADR/technical decision or a proven existing mapping. |
| Employee identity / branch scope | Legacy Shift assignees are `Cashier` identities; branch is available through `cashiers.branch_id` and the parent `Shift.branch_id`. Admin employee allocation separately scopes `Employee` by `Operation.company_id` and `branch_id`. | Cashier and Admin Employee/BranchManager IDs are distinct identity domains; arbitrary cross-domain IDs cannot be safely treated as interchangeable. | Do not infer or expose cross-company/branch identity. Any mapping must be an explicit S1-10/S1-11 integration decision. |
| Default assignment and surplus | `VarianceCalculationService` automatically creates current-cashier shares and mixed external remainder. Positive legacy variance is recorded as `OVER` and may create cashier/manager custody entries through `VarianceRecorded`. | Default cashier and external remainder are not a valid final allocation; surplus must not create employee responsibility. | Remove defaults and make positive signed surplus branch-only in the S1-07 path, once S1-10 supplies authoritative signed variance. |
| Employee accept/object | Cashier endpoints update each detail's `responsibility_status` and reviewer/time; objection requires a reason. | The same field is reused by manager approval. Handover approval automatically marks the submitting cashier's own share approved; objection does not gate handover but state can be overwritten. | Keep response separate from final manager approval; the current schema representation is insufficient without a distinct state/evidence design. |
| Manager final approval | `ShiftVarianceController@approveResponsibility` checks branch and sets all variance details to approved; reject does similarly. | Current route conflates final approval and employee response, with no complete-allocation/current-revision check. | Require an explicit branch-manager decision over the current complete allocation; manager self-share must remain permitted. |
| Daily submit / required handovers | `BranchManagerShiftController@submitDailyReport` finds the authenticated manager's completed workday, sets submitted flags, and sweeps approved handovers from `getShiftHandovers(..., 'to_manager')`. | No shortage-liability gate or required-transfer completion gate. Existing helper's branch/date/seven-day inclusion does not prove all revision-bound incoming/outgoing transfers. | Implement only after source-backed server scope and distinct liability state exist; exact transfer evidence is S1-10/S1-11. |
| Manager correction | `BranchManagerShiftService::bulkUpdateCashierShifts` updates sales/payment/variance fields; no current variance-detail recalculation or cashier notification is performed there. | No correction-specific liability path or preserved revision evidence; unchanged handovers and no duplicate shortage are not proven. | S1-07 must honor F2; full revision/receipt immutability belongs to S1-11. |
| Admin accountant/head close | `ShiftCloseService::setVarianceAllocations` records accountant allocations; `onFinalApproved` posts them or defaults the full negative gap to the shift cashier. | Head approval can create final employee movements from accountant allocation or implicit cashier fallback, without branch-manager final liability approval. The Admin actor/employee identity mapping to legacy Shift is separate. | No silent final fallback. Cross-system enforcement requires an approved bridge/ownership design; do not reinterpret Admin roles here. |
| Events, custody, payroll | `VarianceRecorded` listener writes cashier custody and, for manager handovers, personal-ledger entries for approved cashier details. No automatic salary deduction was found in these traced variance paths. | Custody effects depend on the conflated `approved` status; this is not evidence of payroll authority. | Preserve no payroll inference; keep employee response, final liability approval, and custody receipt distinct. Full financial atomicity remains S1-08. |

### Design/ownership disposition

S1-07 application changes were **not started**. The complete required behavior cannot be implemented safely on the current legacy data contract: the source variance is not the approved signed counted-cash variance, the manager share cannot satisfy the existing cashier-only foreign key, and employee response/final manager approval currently overwrite the same state. The available schema ADR explicitly remains unaccepted and does not authorize allocation schema changes. The task also forbids fabricating S1-10/S1-11 count, revision, and receipt integration. These are implementation blockers, not a new business-rule ambiguity: S1-10 must provide the source-backed signed variance; S1-11/accepted technical schema design must provide stable correction/state and cross-identity representation before the end-to-end S1-07 requirements can be met. No S1-08, S1-09, S1-10, or S1-11 implementation was started.

## Audit corrections — 2026-10-08

Implemented by Claude at Mahmoud's explicit request, after Claude's independent audit of `9a8e0ff..217c2716` (`Assab-Commit-Audit-9a8e0ff-to-217c2716-2026-10-08.md` in the Project). This entry is **implementer evidence, not independent review evidence**; acceptance remains with Mahmoud. Starting state: `sprint/01-financial-foundation` at `217c2716`, no later remote commits. Code commits `dc73ab29` and a follow-up after a separate-agent review of the diff; documentation in the accompanying commits.

| Finding | Change | Evidence |
|---|---|---|
| N-01 (P2) AssabAPP computed amount rejected | `ShiftMoneyValidation::normalizeRepresentationNoise` before validation on all legacy money routes; exponent text is normalized only when it is noise around zero. SAR rules also on reassign-with-handover, handover record/edit, record-variance (these newly return 422 for `40.123`; noise `40.00000000000001` passes validation). Implements D10 option (a) — **Mahmoud to confirm D10**. | `ShiftLegacyMoneyCompatibilityTest`: exact app strings `0.09999999999999432` and `1.4210854715202004e-14` through `POST /api/branch-manager/shifts/{id}/end` as the cashier (real Sanctum auth, migrated SQLite) → 200, shift `completed`, stored 115.50 / 100.43 / 15.07. `1.001`, `0.009`, `10.999`, `123.456`, `0.0015`, `1.5e-3` → 422, shift stays `in_progress`. Existing `ShiftMoneyValidationTest` (incl. `1e2` → 422) unchanged and passing. |
| N-02 (P2) historical VAT recomputed on read | Stored split for persisted rows; derive only when none is stored; handover summaries sum stored per-shift splits in halalas (`BranchManagerShiftResource`, `ShiftFinancialService`, `getManagerFinalHandover`). Follow-up: when manager end/daily close takes its total from the cashier shifts, `resolveFinancialValues` stores that same summed split, so detail and history views agree. | Old manager row 97.75 / 17.25 stays 97.75 / 17.25 in resource and service; summary of shifts (97.75/17.25) + (100/15) = 197.75 / 32.25; two 115.50 shifts store 200.86 / 30.14 from the shifts, a manager-typed 231.00 gives 200.87 / 30.13. |
| N-03 (P3) negative legacy values threw on read | `ShiftFinancialCalculator::storedSarToHalalas` accepts signed stored values; reads no longer throw. | Manager row total −5.00 renders with net/VAT 0. The replica count of negative stored totals was **not run** (no DB access). |
| Reassign VAT (§12 of the audit) | reassign-with-handover stores the VAT-inclusive split. | Manager reassign 115.00 → stored 100.00 / 15.00. |
| N-08.1 | `roundingPendingD5` → `netRounded`; calculator header states D5 as approved. | Calculator unit tests updated; half-up case 100.00 → 86.96 / 13.04 added. |
| N-04 (P2) | `money-contract.md` FIN-01 vector wire forms replaced with the correct legacy (`cash_collected` 40 + `aggregators` 25) and Admin forms. | Doc diff. |
| N-05 (P2) | v2.0 task and acceptance tables copied into `task-register.md`; FIN-05/FIN-06/RX-01/RX-03 descriptions corrected (`baseline.md`, `task-register.md`). | Doc diff. |
| N-06 (P2) | Historical proposal; D11's 500/480 physical pending-incoming rule and rule 3 for this case were approved by Mohamed on 2026-10-08. Structured evidence remains S1-10 and daily-submit integration remains S1-11. | `money-contract.md` decision synchronization. |
| N-07 (P3) | Historical provenance gap; D3/D9 business decisions were confirmed as approved by Mohamed on 2026-10-08. This does not claim runtime completion. | `api-contract.md` approved decision synchronization. |
| N-08.2–8.7 | ShiftCycleFixesTest note; D6 key consistency (`cash_variance`) in examples; route-map D3 and client-caller fixes; accountant alias roles and idempotency; D5 heading; removed `openingConfirmed` flag; C-7/D7 legacy token paragraph in `api-contract.md` §11; "Writers unambiguous" qualified. | Doc diff. |

Commands (PHP 8.3.6, PHPUnit 12.4.0, SQLite in-memory): `php vendor/bin/phpunit tests/Feature/ShiftLegacyMoneyCompatibilityTest.php tests/Unit/ShiftFinancialCalculatorTest.php tests/Feature/ShiftMoneyValidationTest.php` → **OK, 62 tests / 389 assertions**. Full suite → 1292 tests, 71 errors + 2 failures; the 73 failing tests are identical by name to those at `217c2716` and `9a8e0ff` (RecurringOrder, Procurement and NFR suites); **0 new**. `./vendor/bin/pint --test` on the changed PHP files → PASS. A separate agent reviewed the diff before push (no blockers; its should-fix items are included in the follow-up).

Historical state at this S1-06 audit: no migration, no Dashboard or AssabAPP change, H1/H2 not implemented, S1-07 then remained blocked. Current decision state: D11/D12/D13 and D3/D9 are approved by Mohamed on 2026-10-08; D10 confirmation and N-03 replica count remain separate open items. Later task sections supersede historical implementation status.

## S1-07 APPROVE A internal implementation verification — 2026-10-08

Starting SHA: `217c271659d6a5c28a52efa4e2d266577138904f`. User authorized additive schema, liability model/services and feasible tests, explicitly deferring missing real-source integration. See `schema-adr.md` and `s1-07-implementation-handoff.md`.

Executed locally with portable PHP 8.4.14 and locked Composer dependencies (no composer.json/lock changes). No production migrations, external database writes, Dashboard changes or Flutter changes were made.

| Check | Result | Boundary |
|---|---|---|
| New allocation rules + liability service/guard tests | PASS — 38 tests / 64 assertions | Actual service persistence using disposable SQLite fixture schema; actual new migration up/down. No MySQL concurrency claim. |
| Combined S1-06 regression and S1-07 suite | PASS — 81 tests / 356 assertions | Four files below; existing S1-06 route-validation tests retain their documented authentication/reference isolation. |
| Changed-file Pint | PASS — 14 PHP files | Only changed/new PHP files, no unrelated cleanup. |
| Missing trusted report or daily scope provider | PASS — fail-closed conflicts, no new liability writes | Runtime provider remains unavailable; test-only evidence adapter is not registered in production. |
| Old approval after report revision/amount or allocation change | PASS — stale/reapproval enforced | Old allocation and employee objection retained; complete report lifecycle/history remains S1-11. |
| Company/branch/type isolation, owner-only allocation, manager-only approval/self-share | PASS | Existing identity records checked, not ID-domain guessing. |
| Required transfer pending despite approved liability | PASS — daily guard rejects | Trusted synthetic receipt/scope source; actual source-backed membership/receipt adapter remains deferred. |
| Real legacy/Admin route enforcement, source adapters, MySQL locks, financial posting/replay | NOT IMPLEMENTED / NOT RUN in this bounded change | S1-07 integration with S1-10/S1-11; atomic financial writers/replay with S1-08/S1-09. |

Executed test command (PHP executable may be replaced by the environment's PHP 8.4):

```sh
php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result --no-progress tests/Unit/ShiftFinancialCalculatorTest.php tests/Feature/ShiftMoneyValidationTest.php tests/Unit/ShiftAllocationRulesTest.php tests/Feature/ShiftLiabilityServiceTest.php
php vendor/bin/pint --test Modules/Shift/app/Liability Modules/Shift/app/Models/ShiftLiabilityAllocation.php Modules/Shift/app/Models/ShiftLiabilityShare.php Modules/Shift/app/Providers/ShiftServiceProvider.php Modules/Shift/database/migrations/2026_10_08_000001_create_shift_liability_allocations.php tests/Unit/ShiftAllocationRulesTest.php tests/Feature/ShiftLiabilityServiceTest.php
```

S1-07: **INTERNAL IMPLEMENTATION READY FOR REVIEW / NOT ACCEPTED / END-TO-END INTEGRATION STILL BLOCKED**. The existing daily-submit route is not silently changed to reject all legacy workdays; the new guard is not advertised as active on it. No old approved status is promoted to confirmed receipt evidence or new liability approval. Review deployment/schema against MySQL before activating the future integration.

**Integration onto the sprint branch (2026-10-08).** Commit `ea5e8d90` (`codex/s1-07-liability`, started from `217c2716`) was cherry-picked onto `sprint/01-financial-foundation` at `c18c2f23`. Only `task-register.md` and `verification.md` conflicted (documentation; both sides kept: S1-06 row from `c18c2f23`, S1-07 row from `ea5e8d90`; both verification entries). No code conflict; the S1-07 code does not use the calculator keys renamed in `c18c2f23`. After integration (PHP 8.3.6, SQLite in-memory): `ShiftLiabilityServiceTest`, `ShiftAllocationRulesTest`, `ShiftLegacyMoneyCompatibilityTest`, `ShiftFinancialCalculatorTest`, `ShiftMoneyValidationTest` → **OK, 100 tests / 453 assertions**; full suite → 1330 tests with the same 73 pre-existing failures as `217c2716`, **0 new**. This integration does not review or accept S1-07; its additive migration still needs review before S1-08 builds on it.

## S1-07 corrected-report liability readiness — 2026-10-08

The pre-fix logic was reproduced by inspection: `assertDailyReportReady()` returned early for nonnegative variance only if there was no allocation row. An existing shortage allocation therefore reached `current()` against a corrected balanced/surplus revision and failed as stale. The fix returns from the liability portion whenever the current trusted variance is nonnegative; the daily guard continues to check required receipt evidence after that call. No row is deleted or mutated by this readiness check.

Regression coverage verifies shortage → zero and shortage → surplus readiness while preserving the old allocation, approval, and objection; it also verifies surplus → later shortage requires a fresh allocation and manager approval. The latter retains manager-correction behavior without cashier reconfirmation.

| Check | Result | Evidence / boundary |
|---|---|---|
| Focused S1-07 service suite | PASS — 27 tests / 65 assertions | `ShiftLiabilityServiceTest.php`, isolated SQLite schema including the real additive migration. |
| Combined S1-07/S1-06 focused regression | PASS — 102 tests / 469 assertions | Liability, allocation rules, calculator, money validation, and legacy compatibility files. Initial default-memory run exhausted 128 MB; rerun with 512 MB passed. |
| PHP syntax and changed-file Pint | PASS | Both changed PHP files lint; Pint passed on those two files only. |
| Migration | NO CHANGE | Existing S1-07 migration was reviewed, not edited or rerun against a production database. |

S1-10/S1-11 evidence boundaries and fail-closed production provider remain unchanged. No HTTP route was wired, and no S1-08, S1-10, or S1-11 work was started.

## S1-07 internal layer completion — 2026-10-08

Implemented by Claude at Mahmoud's request on top of `a946b1f4` (developer's stale-liability fix). Mahmoud decided on 2026-10-08 to close S1-07 as an internal layer and re-sequence route integration to S1-08/S1-10/S1-11, and decided D4 = no rollout flag. This entry is implementer evidence, not independent review; acceptance remains with Mahmoud.

| Item | Change | Evidence |
|---|---|---|
| S1-07 channel separation | `ShiftFinancialCalculator::salesChannelCheck`: cards + apps ≤ gross; derived cash channel; reported cash difference is a channel difference, never a shortage. | Unit tests: 115/50/25 → cash channel 40; mistyped 25 → difference −15 while `calculate()` variance stays −20; cards + apps > gross invalid; FIN-06 app channel 115. |
| Audit A1 | `allocate` returns 409 `NO_SHORTAGE_LIABILITY` for a balanced/surplus report; no empty allocation record. | Fixture and real-schema tests: 0 allocation rows for surplus/zero. |
| Audit A2 | Employee acceptance: status, timestamp, no overwrite. | Real-schema test (`EMPLOYEE_RESPONSE_ALREADY_RECORDED` on a second response). |
| Daily-submit lock | Migration `2026_10_08_000002` (`shift_liability_daily_locks`); `DailyLiabilityGuard::lockSubmittedDay` / `releaseDay`; `allocate`/`confirm`/`approve` refused while any active lock exists; `respond` allowed (BR-10). One lock row per (report, workday) for carry-over; plain reads under the workday/cashier-shift row locks. | Real-schema tests: lock → refused commands → reopen with reason (rows kept) → manager correction → fresh approval → resubmit; carried-over report stays locked until both days are reopened; other manager refused. Fixture test: lock/release outside a transaction refused. |
| Real-schema check | `ShiftLiabilityRealSchemaTest` resolves the services from the container on the real migrated schema (company/brand/branch, cashiers, branch manager, Admin employee). | 7 tests. |
| Docs | D4 decision (`api-contract.md`, `money-contract.md`, `schema-adr.md`); S1-07 row and completion/re-sequencing in `task-register.md` and `s1-07-implementation-handoff.md`; stale "proposed flag" and "guard implemented in S1-07" statements corrected. | Doc diff. |

Commands (PHP 8.3.6, PHPUnit 12.4.0, SQLite in-memory): S1-06 + S1-07 focused files (`ShiftLiabilityRealSchemaTest`, `ShiftLiabilityServiceTest`, `ShiftAllocationRulesTest`, `ShiftLegacyMoneyCompatibilityTest`, `ShiftFinancialCalculatorTest`, `ShiftMoneyValidationTest`) → **OK, 113 tests / 535 assertions**. Full suite → 1343 tests; the 73 failing tests are the same by name as at `217c2716` (RecurringOrder, Procurement, NFR); **0 new**. Pint `--test` on the changed PHP files → PASS. A separate agent reviewed the diff before commit; its findings (carry-over lock per workday, MySQL gap-lock-free reads, locked version read, doc corrections) are included.

Not run / not claimed: MySQL locking and concurrency; any HTTP route (none uses this layer); a real `LiabilityEvidenceSource` (still `UnavailableLiabilityEvidence`). S1-08 not started.

## S1-08 Phase 1 implementation verification — 2026-10-08

Implemented locally from `c018fa01caaf439a5d5718d63b99fcefe6611835`, at Mahmoud's approval and within the receipt/transfer/revision boundary. This entry is implementation evidence, not independent review or task acceptance.

| Check | Result | Boundary |
|---|---|---|
| New receipt and transfer tests + existing handover/custody regression | PASS — 15 tests / 68 assertions | `ShiftTransferReceiptTest.php`, `ShiftHandoverVarianceCustodyTest.php`; real migrated SQLite schema. |
| Combined S1-06/S1-07, custody, handover, and S1-08 focused regression | PASS — 142 tests / 676 assertions | Includes `ShiftLiabilityRealSchemaTest`, `ShiftLiabilityServiceTest`, allocation rules, legacy compatibility, calculator, money validation, T09 custody, owner-transfer receipt, handover variance/custody, and new transfer receipt tests. |
| Final direct receipt/handover rerun | PASS — 15 tests / 69 assertions | Repeated after the cashier acceptance controller was tightened to require a pending handover addressed to that cashier. |
| PHP syntax | PASS | All 22 changed/new PHP files. |
| Changed-file Pint | PASS | Only the 22 changed/new PHP files; 5 existing style issues in touched files fixed. |
| Whitespace check | PASS | `git diff --check`. |
| New migration | SQLite up exercised by focused tests; rollback path reviewed statically | No production migration executed; no MySQL schema or lock test. |

The first test attempt exposed an unsupported `Blueprint::check()` call; it was removed because this Laravel version has no such API. Exactly-one-source is enforced by the sole service writer, while each typed source FK is unique and constrained. The next run exposed and fixed a missing `cashier_shifts` join. Regression testing also confirmed that legacy manager approval must retain its variance-review effect while no longer posting handover custody/receipt evidence; this behavior is preserved and tested.

Not run: the full project suite; MySQL lock/deadlock/concurrency behavior; deployment table-size/online-DDL preflight. No new public endpoints, daily-submit/reopen wiring, trusted count adapter, S1-10 evidence source, generic replay framework, or S1-11 correction history were added. See `s1-08-implementation-handoff.md` for the resulting transaction/lock order and boundaries.

## S1-08 Phase 2 verification — 2026-10-08

Phase 2 hardens required write ownership and rollback while retaining the Phase 1 receipt design. `ShiftCloseService::close` computes from a locked, re-read Admin shift. `OperationService::finalApprove` writes final state, approval step, native shift close, and shortage allocation in one transaction. A required allocation error rolls all of them back. Cashier report close commits its report, sales breakdown, revision, declaration custody row, and legacy variance rows together. Bridge and statistics listeners are non-authoritative after-commit projections; failures are logged. Variance approval remains an independent variance effect. Manager review of a cashier-to-cashier handover is audit-only and leaves the request pending for the named cashier; it neither finalizes variance liability nor proves receipt. The approval-triggered manager `Total Sales` ledger writer was removed. Receipt remains solely written by `ShiftTransferReceiptService` on actual recipient confirmation.

S8-01 adds exact-amount confirmation and a correction lifecycle for cashier and manager-to-cashier transfer requests. A mismatch creates no receipt or movement; intentional correction retains the rejected request and submitted variance evidence, records old/new or attempted amount, actor, time and reason, then requires an exact confirmation. `actual_shortage` does not infer an S1-07 liability. S8-02 lets the addressed Branch Manager confirm a cashier-to-manager handover, writing the manager-bound receipt, cashier debit, manager `Total Sales` credit, request state, and audit atomically. Manager review of cashier-to-cashier handover remains review-only.

| Check | Result | Evidence / boundary |
|---|---|---|
| Focused affected regression | PASS — 45 tests / 203 assertions / 0 failures | Receipt/transfer, all four `HandoverLedgerDateTest` cases, variance/custody, Admin shift close chain, shift-end atomicity, and mobile bridge; PHP 8.4.26 and SQLite. |
| S1-06/S1-07 focused regression | PASS — 113 tests / 535 assertions / 0 failures | The money compatibility fixture supplies the required `correction_reason` for handover edit; the isolated compatibility test also passed. |
| MySQL lock/deadlock/concurrency | NOT RUN | SQLite verifies rollback/re-read paths only; it is not deadlock-safety evidence. |
| Changed-file Pint | PASS — 23 PHP files | Changed-file `pint --test`; formatter fixes were confined to those files. |
| PHP syntax / whitespace | PASS — 23 PHP files; `git diff --check` PASS | PHP 8.4.26. |
| Full PHPUnit suite | NOT CLEAN — 1,364 tests / 7,534 assertions / 75 errors / 21 failures / 1 skipped | One serial run, PHP 8.4.26 + SQLite. Errors include temp-folder/file-read limitations and NFR SQLite `migrate:fresh`/transaction incompatibility. Failures are in RecurringOrder, Procurement, exports, credential/email, and NFR-facing tests; no S1-08 receipt/transfer/close test failed. This exceeds the historical baseline count of 73 failures, and the baseline test-name list is not retained here, so zero new failures is NOT confirmed. JUnit: `storage/logs/s1-08-phase2-full-suite-serial.xml` (local run artifact). |
| Migration | Phase 2: additive migration 000004 | `2026_10_08_000004_add_manager_recipient_receipt_fields.php` was added in Phase 2; no applied migration was rewritten. |

S1-08 Phase 2 was committed at `569d00e77dea922c03782c001c3c26c2cd30cba9` after an exact `classname::method` comparison of its serial JUnit to the `c018fa01` baseline showed **zero new failure/error identities**. The earlier count-only caution above is historical and superseded by that comparison. Current Phase 3/4 and Mahmoud corrections are local and uncommitted; their final evidence is recorded below. S1-09 replay, S1-10 trusted count, and S1-11 public liability/full correction/daily-submit-reopen integration remain deferred.

## S1-08 final Mahmoud decisions and deployment gates — 2026-10-08

S8-09 is **APPROVED — DOCUMENTATION / RELEASE GATE ONLY**. No S1-08 code change restores the legacy coupling: confirming cash receipt neither approves nor posts the cashier's self-declared shortage. S1-11 final branch-manager liability approval must post exactly one cashier personal-ledger movement for that shortage, with no duplicate; there is **no release before S1-11** acceptance.

S8-12 is **APPROVED — DRAIN-BEFORE-DEPLOY**. Resolve pending handovers in the current system by confirmation or rejection. After maintenance mode begins, run the read-only pending count in `s1-08-deployment-readiness.md`; only zero permits deployment. A nonzero result postpones deployment. No backfill is part of normal deployment. An exceptional unresolved historical request requires a separate, idempotent, evidence-preserving backfill design before proceeding.

D12 dashboard/mobile branch-manager assignment now shares one transaction and rejects an occupied destination; the focused manager sync and credential tests pass (15 tests / 52 assertions). This SQLite evidence does not establish MySQL concurrency safety. Final focused and serial comparison results must be appended only after their runs complete.

S8-05 is **RESOLVED** by Mahmoud's accounting decision: manager-to-cashier confirmation writes one `Handover to Cashier` manager personal-ledger cash-out with the receiving `cashier_name`, exact confirmed amount and receipt ID. The cashier custody entry remains `Handover Received`; manager available cash remains personal sales cash less pending outgoing requests, excluding expense custody. Existing `transaction_type` string(50) needs no migration. AssabAPP D4 must localize the English API value to `تسليم نقدية لكاشير`. Focused transfer evidence appears below.

### Final corrected-worktree serial suite

After aligning all eight prior current-only identities with the approved D12 and receipt-derived opening behavior, the corrected worktree ran each requested serial stage once with PHP 8.4.26, SQLite in-memory, `--do-not-cache-result`, and `--no-progress`. All three fresh JUnit files are valid. Unit, Feature, and NFR exited nonzero only for the retained baseline and SQLite environment failures listed by identity below. No stage was restarted.

| Stage | Tests | Assertions | Errors | Failures | Skipped | JUnit |
|---|---:|---:|---:|---:|---:|---|
| Unit | 62 | 1,762 | 0 | 1 | 0 | `storage/logs/s1-08-corrected-final-unit.xml` |
| Feature | 1,179 | 5,680 | 0 | 1 | 1 | `storage/logs/s1-08-corrected-final-feature.xml` |
| NFR | 153 | 315 | 71 | 0 | 0 | `storage/logs/s1-08-corrected-final-nfr.xml` |
| Combined | 1,394 | 7,757 | 71 | 2 | 1 | Logical merge by `classname::method` |
| Accepted Phase 2 | 1,364 | 7,534 | 75 | 21 | 1 | `storage/logs/s1-08-phase2-full-suite-serial.xml` |

The exact identity comparison against the accepted Phase 2 report yields **73 shared**, **23 Phase 2 only**, and **0 current only** failing/error identities. All eight formerly current-only identities are absent from the fresh reports. There are **0 status changes** among shared identities and **0 new product regressions**. The complete names are recorded in `s1-08-final-serial-comparison.md`. The 71 current NFR errors are SQLite `migrate:fresh`/VACUUM-in-transaction environment errors, all shared with Phase 2; the Unit RecurringOrder and Feature Procurement failures are also shared baseline identities. The focused rerun of the eight corrected identities passed **48 tests / 187 assertions**.

Other focused evidence: manager branch synchronization and credential tests **15 / 52 PASS**; S1-08 shift, transfer, custody, and manager batch **120 / 636 PASS** before S8-05; S1-06/S1-07 **113 / 535 PASS**; S8-05 receipt and handover batch **54 / 276 PASS**, including the new 10 SAR manager cash-out, recipient name, same receipt, cashier custody, balance decrease, old-type absence, and duplicate confirmation rejection. Changed-file Pint `--test` passed for 36 PHP files, PHP syntax passed for all 36, and `git diff --check` passed. MySQL/deployment-equivalent locking validation remains unavailable and unproven.
