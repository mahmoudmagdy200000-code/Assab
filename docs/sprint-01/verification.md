# S1-01 verification evidence

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

S1-01 is **Ready for review; not Accepted**. There is no remaining infrastructure or focused-fixture blocker. The S1-01 delivery separated the one-line fixture correction from its eight evidence documents; no local `.env`, credentials, tools, logs, databases or backup files were included. No S1-02 implementation was part of this baseline. A separately published S1-02 money-contract document is tracked as Needs correction in task-register.md.

## Targeted audit closure carried forward

* **R06 — RESOLVED:** route-map/API contract identify inline `Illuminate\Http\Request` validation and the unused end FormRequest; list the native Admin close mutation path and legacy middleware aliases; and preserve `CashierShiftObserver::updating` → `ShiftStartedEvent`/`ShiftEndedEvent` direction and pre-save / transaction timing. Financial writer and transaction traces are source-linked there.
* **R07 S1-01 evidence — RESOLVED:** the executed result is 41 tests / 144 assertions / 0 failures / 0 errors; no text treats it as proof of the future acceptance scenario; S1-01 remains Ready for review, not Accepted.
* **R08 — RESOLVED:** the fail-fast guard resolves Laravel's effective DB connection and allows `RefreshDatabase` only for `assab_s1_test` on loopback port 3310. `assab_s1_local` is explicitly protected as the migrated baseline. Current Dashboard startup uses `pnpm --filter @workspace/mockup-sandbox dev`; historical setup/recovery instructions are labeled non-current. Test evidence and the current 3310/test-schema workflow are separated from historical runs.
