# S1-01 migration safety preflight

## Final execution evidence — 2026-10-07

**S1-01 Ready for review; not Accepted.** The separately authorized one migration attempt on MySQL 8.4.11 at `127.0.0.1:3310`, schema `assab_s1_local`, succeeded: **283/283 migrations**, 212 base tables, 2 views, 2 procedures and 179 foreign keys. Runtime PDO connectivity passed. The six focused files subsequently passed on the separate `assab_s1_test` schema (41 tests / 144 assertions); their `RefreshDatabase` calls never targeted the baseline. Both schemas had 283 migration rows and no business rows after the final run.

These results supersede the pre-execution GO/NO-GO statements below. They prove this fresh disposable installation only; destructive migration and predictable imported-password risks on populated databases remain valid source findings. No migration file changed. The pre-migration checkpoint is documented below and in database-recovery.md; no automatic rollback/restore occurred. This delivery review authorizes no new database execution. See verification.md for current test evidence.

<details>
<summary>Historical migration preflights and proposed commands — preserve for audit, do not execute</summary>


## Historical pre-execution decision — isolated 3310 target

**GO for a separately authorized, controlled fresh-database migration attempt on 3310; NOT EXECUTED and not yet Accepted.** No deterministic blocker was found for this *empty disposable schema*. MySQL 8.4.11, both scoped accounts, an empty schema, PHP/PDO migrator connectivity, and a timestamped cold checkpoint were verified. This is not a guarantee that all 283 generated SQL statements will succeed. MySQL DDL can commit before a later failure; preserve the failed datadir and use the checkpoint recovery procedure below rather than assuming `migrate:rollback` is safe. The older 3307/3308 preflight following this current section is retained as **historical evidence only**; its commands and NO-GO assessment are superseded and must not be executed.

### Live identity, isolation, and checkpoint evidence

Read-only MySQL queries through the protected admin, migrator, and runtime option files returned:

| Check | Verified 3310 result |
|---|---|
| Server | MySQL Community Server `8.4.11`, `@@bind_address=127.0.0.1`, `@@port=3310`, unique pipe `AssabS1Local3310` |
| Datadir | `D:\claude\AssabERP\.tools\s1-01\mysql-local\data\` |
| Schemas | `assab_s1_local` plus MySQL system schemas only |
| Application objects | 0 tables/views, 0 routines; no migration repository table or application rows |
| Migrator TCP identity | `USER()=CURRENT_USER()=assab_s1_migrator@127.0.0.1`; selected `DATABASE()=assab_s1_local` |
| Migrator grants | `USAGE ON *.*`; `SELECT, INSERT, UPDATE, CREATE, DROP, REFERENCES, INDEX, ALTER, CREATE VIEW, CREATE ROUTINE, ALTER ROUTINE ON assab_s1_local.*`; no global DDL/admin privilege or `GRANT OPTION` |
| Runtime TCP identity | `USER()=CURRENT_USER()=assab_s1_runtime@127.0.0.1`; selected `DATABASE()=assab_s1_local` |
| Runtime grants | `USAGE ON *.*`; `SELECT, INSERT, UPDATE, DELETE ON assab_s1_local.*`; no DDL or `GRANT OPTION` |
| Cross-schema check | Both scoped accounts were denied access to the `mysql` schema (MySQL error 1044) |
| PHP transport | Portable PHP 8.4.26 PDO read-only query authenticated as the migrator and returned version `8.4.11`, schema `assab_s1_local`, port `3310`, zero objects; exit 0 |
| Server settings | `local_infile=0`, InnoDB, strict SQL mode with `ONLY_FULL_GROUP_BY`; binary log enabled; `log_bin_trust_function_creators=0` (the chain creates procedures, not stored functions) |
| Cold checkpoint | `D:\claude\AssabERP\.tools\s1-01\mysql-local\backups\pre-migration-20261007-093624979` contains 173 data/config files; source and backup had identical relative paths, lengths and SHA-256 values (195,632,367 bytes). Both manifest files have SHA-256 `ABBE5884FC2B1BA0AC933904673EB30157F5324ACD2267643C8714CCE12430AB`. The original 3310 instance restarted from its unchanged config/datadir and was reverified empty. Credentials remain separately under protected `mysql-local\credentials`; they are not copied into the checkpoint. |

The Backend's ignored `.env` selects `DB_HOST=127.0.0.1`, `DB_PORT=3310`, `DB_DATABASE=assab_s1_local`, `DB_USERNAME=assab_s1_runtime`, with `DB_PASSWORD` empty by design. Its runtime password remains only in the protected credential file and must be supplied process-locally for application checks. The migrator is selected only by the process-local overrides below. `bootstrap/cache/config.php` is absent; `config/database.php:46-63` reads the MySQL URL/host/port/database/username/password/socket from environment. The migrator has privileges only on the named schema; no reviewed migration contains `CREATE DATABASE`, `DROP DATABASE`, `USE` or a named secondary connection. Isolation relies on effective-target checks and the schema-scoped grant, not on claiming that schema-level `CREATE` forbids recreating its own schema. The approved execution began with the intended schema already present.

### Chain and fresh-install decision

The current checkout contains **283** unique first-party migration basenames: 7 root migrations and 276 across **16 enabled module paths** (`modules_statuses.json`; each module provider calls `loadMigrationsFrom`). Laravel's `Migrator::getMigrationFiles()` keys by basename and sorts globally by that key (`vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:575-583`); it does not run one module at a time. The eight timestamp ties and their lexical order are inventoried in the archived section below. No duplicate basename was found. The prior bounded PHP lint passed all 283 files. No `--path` should be passed to `artisan migrate`, or registered module paths may be omitted.

| Migration / operation | Fresh empty-schema path | Residual risk |
|---|---|---|
| Purchase `2025_12_20_000002` replaces `branch_item`; `000004` reads `branch_item_old_backup` only if it exists | Earlier chain can create `branch_item`, but there are no application rows to lose; backup-table data-copy branch returns because no migration creates that backup table | **High on populated upgrades**: intended data copy is skipped. No fresh-data loss, but resulting constraints remain runtime-unverified. |
| Inventory `2025_12_30_150601` drops/recreates `inventory_items` | Earlier creation may have produced the table; with no fixtures/seeds it has no business rows | **High on populated upgrades**; MySQL DDL is non-transactional across the chain. |
| Settings `2026_05_22_000001` truncates `user_settings`, then changes ID type | MySQL path runs; freshly created settings table should have no rows | **High on populated upgrades**; empty-table assumption must be checked after any unexpected earlier data write. |
| Branch/Shift column drops, MySQL `ENUM` alterations, FK/index drops | Earlier schema creation and guards control these paths; there is no legacy user data | **Medium**: generated DDL, constraint names and SQL mode are not proven until execution. |
| Supplier data copies and Admin branch-hierarchy backfill | Source tables are expected empty; copy/update loops should have zero records | **Medium**: model observers or migration-created defaults may make a branch nonempty; inspect any unexpected rows before continuing after failure. |
| Cashier `2025_10_09_152609` drops/recreates two views and two procedures after checking four prerequisite tables | Those tables are created earlier in global order; `DROP ... IF EXISTS` is schema-local. No explicit `DEFINER`; objects should default to the migrator | **Medium**: if prerequisites are absent, the migration records success but silently skips objects; verify all four objects and definers afterward. |

Forward `up()` scanning found no `DELETE` requirement (the identified `->delete()` calls are in `down()` methods), no trigger/event/stored-function creation, and no external host/HTTP/shell command in the migration files. The scoped grant covers the reviewed forward DDL/DML: `DROP` is necessary for `TRUNCATE` and replacements; `CREATE VIEW`/`SELECT` cover views; `CREATE ROUTINE`/`ALTER ROUTINE` cover procedures; `REFERENCES`/`INDEX`/`ALTER` cover foreign keys and indexes. Automatic routine-specific grants, if MySQL adds any on creation, should be reviewed after execution. Binary logging with `log_bin_trust_function_creators=0` restricts *stored function* creation; no first-party `CREATE FUNCTION` was found. [MySQL binary-log variable](https://dev.mysql.com/doc/refman/8.4/en/replication-options-binary-log.html), [view privileges](https://dev.mysql.com/doc/refman/8.4/en/create-view.html), [routine privileges](https://dev.mysql.com/doc/refman/8.4/en/stored-routines-privileges.html).

### Exact proposed migration command — **do not execute without separate approval**

Run in a fresh PowerShell process under `<local-operator>` at Medium integrity. The protected client option file is read only into process memory; it is not an application config file. This command does not run seeds, specify a migration subset, create a database, or place a password in the command line or a tracked file.

```powershell
$ErrorActionPreference = 'Stop'
$repo = 'D:\claude\AssabERP\Assab'
$credentialFile = 'D:\claude\AssabERP\.tools\s1-01\mysql-local\credentials\mysql-migrator.cnf'
$php = 'D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe'
$keys = @('APP_ENV','DB_CONNECTION','DB_URL','DB_HOST','DB_PORT','DB_SOCKET','DB_DATABASE','DB_USERNAME','DB_PASSWORD')
$prior = @{}
foreach ($key in $keys) { $prior[$key] = [Environment]::GetEnvironmentVariable($key, 'Process') }
$passwordLine = Get-Content -LiteralPath $credentialFile | Where-Object { $_ -match '^password=' } | Select-Object -First 1
if (-not $passwordLine) { throw 'Protected migrator credential is missing' }
try {
    $env:APP_ENV = 'local'
    $env:DB_CONNECTION = 'mysql'
    $env:DB_URL = 'null'
    $env:DB_HOST = '127.0.0.1'
    $env:DB_PORT = '3310'
    $env:DB_SOCKET = 'null'
    $env:DB_DATABASE = 'assab_s1_local'
    $env:DB_USERNAME = 'assab_s1_migrator'
    $env:DB_PASSWORD = $passwordLine.Substring('password='.Length)
    Set-Location -LiteralPath $repo
    & $php artisan migrate --database=mysql --no-interaction
    if ($LASTEXITCODE -ne 0) { throw "Migration failed with exit code $LASTEXITCODE; preserve the failed datadir" }
}
finally {
    foreach ($key in $keys) { [Environment]::SetEnvironmentVariable($key, $prior[$key], 'Process') }
    $passwordLine = $null
}
```

Immediately before any separately approved execution, repeat the 3310 identity, empty-schema, grants, config-cache absence, checkpoint-manifest, and Git/lockfile checks. Stop on any difference. Do **not** run `migrate:fresh`, `migrate:reset`, `migrate:rollback`, `--seed`, or `--force` as a substitute. The command's first schema write should be Laravel's `migrations` repository table in the already selected schema.

### Exact post-migration verification checklist — proposed, not run

Use the same process-local credential setup and cleanup as above, substituting the protected runtime option file only for runtime checks. The following MySQL client reads use the admin named pipe solely to inspect this 3310 datadir; no other instance is contacted.

```powershell
$mysql = 'D:\claude\AssabERP\.tools\s1-01\mysql-8.4.11-winx64\bin\mysql.exe'
$admin = 'D:\claude\AssabERP\.tools\s1-01\mysql-local\credentials\mysql-admin.cnf'
$runtime = 'D:\claude\AssabERP\.tools\s1-01\mysql-local\credentials\mysql-runtime.cnf'
$adminArgs = @("--defaults-extra-file=$admin", '--get-server-public-key', '--batch', '--skip-column-names')
& $mysql @adminArgs -e "SELECT VERSION(),@@bind_address,@@port,@@datadir; SELECT COUNT(*),COUNT(DISTINCT migration),MAX(batch) FROM assab_s1_local.migrations; SELECT TABLE_TYPE,COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='assab_s1_local' GROUP BY TABLE_TYPE; SELECT ROUTINE_TYPE,ROUTINE_NAME,DEFINER FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='assab_s1_local' ORDER BY ROUTINE_NAME; SELECT TABLE_NAME,DEFINER FROM information_schema.VIEWS WHERE TABLE_SCHEMA='assab_s1_local' ORDER BY TABLE_NAME; SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA='assab_s1_local' AND CONSTRAINT_TYPE='FOREIGN KEY';"
& $mysql "--defaults-extra-file=$runtime" --batch --skip-column-names -e "SELECT CURRENT_USER(),DATABASE(),@@port; SELECT COUNT(*) FROM migrations;"
& $mysql @adminArgs -e "SELECT TABLE_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='assab_s1_local' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME; SHOW DATABASES; SHOW GRANTS FOR 'assab_s1_migrator'@'127.0.0.1';"
$tables = & $mysql @adminArgs -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='assab_s1_local' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME;"
if ($LASTEXITCODE -ne 0) { throw 'Cannot enumerate application tables' }
foreach ($table in $tables) {
    $escaped = $table.Replace('`', '``')
    $sql = 'SELECT COUNT(*) FROM `assab_s1_local`.`{0}`;' -f $escaped
    $rowCount = & $mysql @adminArgs -e $sql
    if ($LASTEXITCODE -ne 0) { throw "Cannot count table $table" }
    if ($rowCount -ne '0') { "NONEMPTY $table $rowCount" }
}
# Inspect every NONEMPTY result other than `migrations` against the specific migration source.

# In a fresh PowerShell process, set only process-local runtime DB credentials
# for the read-only Laravel status and PHP/PDO connectivity checks.
$runtimePasswordLine = Get-Content -LiteralPath $runtime | Where-Object { $_ -match '^password=' } | Select-Object -First 1
if (-not $runtimePasswordLine) { throw 'Protected runtime credential is missing' }
$keys = @('APP_ENV','DB_CONNECTION','DB_URL','DB_HOST','DB_PORT','DB_SOCKET','DB_DATABASE','DB_USERNAME','DB_PASSWORD')
$prior = @{}
foreach ($key in $keys) { $prior[$key] = [Environment]::GetEnvironmentVariable($key, 'Process') }
try {
    $env:APP_ENV='local'; $env:DB_CONNECTION='mysql'; $env:DB_URL='null'; $env:DB_SOCKET='null'
    $env:DB_HOST='127.0.0.1'; $env:DB_PORT='3310'; $env:DB_DATABASE='assab_s1_local'
    $env:DB_USERNAME='assab_s1_runtime'; $env:DB_PASSWORD=$runtimePasswordLine.Substring('password='.Length)
    Set-Location -LiteralPath 'D:\claude\AssabERP\Assab'
    $php = 'D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe'
    & $php artisan migrate:status --database=mysql --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Laravel migration-status check failed' }
    & $php -r '$pdo = new PDO("mysql:host=127.0.0.1;port=3310;dbname=assab_s1_local;charset=utf8mb4", "assab_s1_runtime", getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); echo implode(" | ", $pdo->query("SELECT CURRENT_USER(),DATABASE(),COUNT(*) FROM migrations")->fetch(PDO::FETCH_NUM)), PHP_EOL;'
    if ($LASTEXITCODE -ne 0) { throw 'Runtime PHP/PDO connectivity failed' }
}
finally {
    foreach ($key in $keys) { [Environment]::SetEnvironmentVariable($key, $prior[$key], 'Process') }
    $runtimePasswordLine = $null
}
```

Expected migration repository count is **283 distinct names, all reported Ran** if every registered file executes; check the exact count rather than assuming success from an exit code. Inventory tables and views separately; require `vw_cashier_summary`, `vw_pending_shifts`, `GetNextCashier`, and `CheckShiftAvailability` with `assab_s1_migrator@127.0.0.1` as definer. Review all FK names/counts against generated schema and investigate missing constraints. For exact row counts, enumerate base-table names from `information_schema.TABLES` for `assab_s1_local`, then issue `SELECT COUNT(*)` per quoted table; flag any nonzero row outside `migrations` for source review rather than assuming all data migrations are inert. Verify no unexpected new schema and inspect any automatic routine-specific migrator grants. Test PHP/PDO runtime connectivity with `DB_PASSWORD` supplied only process-locally from `mysql-runtime.cnf`; the ignored `.env` password is intentionally empty. Do not treat `migrate:status` alone as object/row verification.

### Failure handling from the verified checkpoint — proposed, not run

1. On any nonzero migration exit, **stop**; record the failed migration and error without exposing secrets. Do not retry, rollback, seed, or manually drop objects. MySQL DDL may have committed even without a corresponding `migrations` row.
2. Re-verify that the server is still the isolated 3310 instance and gracefully stop it through `mysqladmin --defaults-extra-file=<protected mysql-admin.cnf> --get-server-public-key shutdown`. Require both 3310 server processes and the listener to exit before any file copy; never stop 3307/3308.
3. Preserve the failed `mysql-local\data` directory unchanged for diagnosis. Recheck both checkpoint manifest SHA-256 values against `ABBE5884FC2B1BA0AC933904673EB30157F5324ACD2267643C8714CCE12430AB` and compare the 173 listed files in the checkpoint with its backup manifest. Do not overwrite the original data directory.
4. Under a **separately approved recovery action**, copy the checkpoint's `data` and `config/mysql-3310.ini` to a *new, empty*, ACL-protected `mysql-local\restore\<timestamp>` directory; compare relative-path hashes again. Adapt only the copy's port, named pipe, log and PID paths for a disposable restore verification (for example, loopback port 3311), then verify its identity, accounts, empty schema, and integrity before deciding how to replace or rebuild 3310. Keep the original and failed state until Mahmoud reviews the evidence. Credentials stay in the existing protected credential directory.

No migration, seed, fixture, test requiring a database, account/privilege change, or recovery operation was performed during this refresh. S1-01's database execution evidence remains pending explicit migration approval.

## Archived 3307 migration preflight — superseded, do not execute its commands

**Historical disposition: NO-GO for the earlier 3307 target.** The target was empty and isolated and its account scoped, but root TCP shutdown authentication failed before a tested recovery path was available. The later 3308 instance was inaccessible in a different Windows execution context. Neither historical condition describes the current verified 3310 instance. The remainder of this document is retained to preserve the original 283-file review and line references.

## Verified target

Read-only query through `mysql-migration.cnf` returned:

| Property | Verified value |
|---|---|
| Server | MySQL Community Server 8.4.11 |
| Host / bind | `127.0.0.1` / `127.0.0.1` |
| Port | `3307` |
| Selected schema | `assab_s1_local` |
| Authenticated principal | `assab_s1_migrator@127.0.0.1` |
| Tables / views | 0 / 0 |
| Grants | `ALL PRIVILEGES` on `assab_s1_local.*`; no `GRANT OPTION`; `USAGE` globally |

The MySQL listener was observed at `127.0.0.1:3307`; no listener appeared on `33060`. The server reports `local_infile=0`, `secure_file_priv=NULL`, and plugin `mysqlx` is `DISABLED`. The Backend ignored `.env` is `APP_ENV=local`, `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3307`, `DB_DATABASE=assab_s1_local`, `DB_USERNAME=assab_s1_local`; `DB_URL` is present but empty and no config cache exists. No credential values are recorded here. The runtime account has only `SELECT, INSERT, UPDATE, DELETE` on `assab_s1_local.*`; migration-account verification was independently successful.

**Important:** Artisan currently reads the runtime account from `.env`, which lacks DDL grants. A migration command without process-local credential overrides will fail on its first schema operation. The private `mysql-migration.cnf` is a MySQL client option file; Laravel does not automatically consume it.

## Inventory and registration

Static inventory covered **283 first-party timestamped migration files**: 7 in `database/migrations` and 276 across 16 module migration directories. All 16 module status flags in `modules_statuses.json` are enabled. Their service providers call `loadMigrationsFrom(module_path(..., 'database/migrations'))`; `config/modules.php:109,167` defines the conventional module migration path and root path. The root Laravel migration path is also included by the framework. Sanctum's vendored migration is published into the root migration directory; vendor migration directories are not separately part of the 283-file set, and no `loadMigrationsFrom` registration for those package directories was found.

Execution order is Laravel's global lexical ordering by migration basename across registered directories, not module-by-module ordering. There are no duplicate migration basenames and no nonconventional timestamped filenames. Eight timestamp ties exist; their order is lexical by complete basename:

| Timestamp | Same-time migration basenames (lexical order) |
|---|---|
| `2026_05_18_000001` | `drop_user_fks_from_expenses`; `make_preferred_receipt_method_nullable_on_custody_requests` |
| `2026_05_22_000001` | `create_saved_price_comparisons_table`; `fix_user_settings_userable_id_to_uuid` |
| `2026_07_06_000001` | `add_dashboard_decision_to_purchase_orders`; `add_type_to_asab_inventory_catalog` |
| `2026_07_09_000001` | `add_purchase_item_id_to_asab_supplier_items`; `create_asab_brand_packages` |
| `2026_07_12_000001` | `add_savings_eta_to_purchase_order_groups`; `add_shift_cashier_and_type` |
| `2026_07_12_000002` | `add_brand_id_to_asab_supplier_items`; `add_t09_ledger_columns` |
| `2026_07_26_000001` | `backfill_branch_asab_hierarchy`; `create_asab_accountant_restaurant_modules` |
| `2026_08_04_000001` | `create_notification_alert_settings_table`; `make_procurement_item_price_company_nullable` |

PHP syntax validation completed for all 283 migration files: **283 passed, 0 failed**. This validates PHP syntax only; it does not parse/execute generated SQL or establish schema compatibility. The earliest filename is `0001_01_01_000000_create_users_table`; the latest is `2026_08_15_000001_add_owns_purchase_item_to_asab_supplier_items`.

## Risk-classified findings

### High — data loss / irreversibility on a nonempty legacy schema

* `Modules/Purchase/database/migrations/2025_12_20_000002_refactor_branch_item_to_pivot_table.php:16-124` drops `branch_item` when it exists and recreates it. A repository-wide migration search found no migration that creates `branch_item_old_backup`. The following `2025_12_20_000004_migrate_branch_item_data_to_items.php:15-20,118-122` only migrates data if that backup exists and has a no-op `down()`. Thus on the normal chain the stated data-copy path is skipped and old `branch_item` records are lost. Empty local schema means this will have no existing rows to lose, but this is unsafe for any populated database.
* `Modules/Inventory/database/migrations/2025_12_30_150601_update_inventory_items_table.php:12-55` unconditionally drops and recreates `inventory_items`; existing rows are discarded. Its `down()` also recreates a materially reduced table (`:61-70`). On this fresh chain the table was created earlier that day and will be empty, so this is locally data-safe but not a safe upgrade for populated data.
* `Modules/Settings/database/migrations/2026_05_22_000001_fix_user_settings_userable_id_to_uuid.php:17-40` truncates `user_settings` before changing its morph ID type. Its rollback truncates again (`:46-67`). It intentionally resets preferences; it cannot preserve settings data.
* `Modules/Branch/database/migrations/2026_01_25_165932_drop_branch_manager_columns_from_branches_table.php:13-70` removes `branch_manager_id` and `branch_manager_image`; rollback recreates empty nullable columns, not their former values (`:76-87`).
* `Modules/Shift/database/migrations/2025_11_28_165000_update_shift_handover_status_table_for_polymorphic_reviewer.php:12-27` drops `reviewed_by` and adds polymorphic reviewer fields; review identities in the old field are not copied in this migration.
* Supplier data migrations `Modules/Supplier/database/migrations/2025_12_24_103132_migrate_purchase_suppliers_data.php:19-63` and `.../2025_12_24_103136_migrate_expense_suppliers_data.php:35-55` assign `bcrypt('default_password_'.$id)` to imported records. They do not reverse. Existing supplier data therefore gets a predictable initial secret (with first-login flags); this needs security-owner review before any populated-schema execution.

### Medium — ordering, conditional branches, and partial-run risk

* Eight timestamp tie groups above are deterministic under Laravel's global filename sort and have unique names. The ties are not automatically errors, but review should not assume module-directory order.
* Purchase migrations `2025_12_20_000002` through `...000004` form a fragile sequence: `0002` drops/recreates the pivot; `0003` assumes `branch_inventory` exists and adds a foreign key to `items`; `0004` silently skips if the absent backup table is not present. The chain can complete while its intended data migration never occurs.
* Many migrations guard with `Schema::hasTable/hasColumn` and return early. Laravel can still record such a migration as completed. That makes a partial/legacy starting schema appear migrated even when the guarded object was skipped. This is intentional in some files but is not proof of a complete schema.
* The `2025_10_09_152609_create_cashier_views_and_procedures.php` migration requires `cashiers`, `branches`, `branch_managers`, and `cashier_shifts`; it returns without creating objects if any are missing (`:9-22`). On the normal fresh order those tables precede it, but on partial schemas it may be marked complete without its views/routines.
* MySQL DDL (including `CREATE TABLE`, `ALTER TABLE`, `TRUNCATE`, view and routine operations) can implicitly commit. A failed migration may therefore leave partially applied DDL even if Laravel does not record that migration as complete. A plain `migrate:rollback` is not a reliable recovery plan.
* `Modules/Branch/database/migrations/2026_01_25_162355_modify_branches_table_add_new_fields.php` converts branch time/location text using conditional parsers and row updates (`:31-125`); malformed historic values can be normalized or cleared, and this data transformation is not fully reversible.

### Low on this verified empty local target; material on populated data

* Information-schema introspection occurs in branch/purchase/cashier index/FK migrations. Queries use `TABLE_SCHEMA = DATABASE()` or bind the name returned by the current configured connection (`Modules/Branch/...drop_branch_manager_columns...:25-32`; `Modules/Purchase/...refactor...:22-28,64-71`; `Modules/Purchase/...update_branch_inventory...:23-31`). Seven `DB::connection()` occurrences were found; each checks the current default driver or retrieves the current default database name. No named secondary connection was found in migration files.
* First-party migration scan found no `CREATE DATABASE`, `DROP DATABASE`, explicit `USE <schema>`, external database host, HTTP/client request, shell/process call, or trigger creation. One Cashier migration creates two views and two stored procedures; its drops are unqualified and therefore operate in the selected default schema. No migration has a `DEFINER` clause.
* `Modules/Admin/database/migrations/2026_07_26_000001_backfill_branch_asab_hierarchy.php:18-41` uses Eloquent models to update matching rows. It can fire model events if rows exist; no direct network call appears in the migration. The verified fresh schema has no rows, but provider/observer effects are not proven by a static migration-file scan.

## Raw SQL, routines, and destructive-operation review

The migration scan found one first-party file with raw stored-object SQL: `Modules/Cashier/database/migrations/2025_10_09_152609_create_cashier_views_and_procedures.php`. It drops/recreates views `vw_cashier_summary` and `vw_pending_shifts`, then, for MySQL/MariaDB, drops/recreates procedures `GetNextCashier` and `CheckShiftAvailability` (`:27-85,90-148`). There are **zero `CREATE TRIGGER` statements**. The migration account's verified schema `ALL PRIVILEGES` includes required schema-level DDL/routine rights without global privileges; the MySQL 8.4 manual specifies `CREATE ROUTINE` for procedure creation and `CREATE VIEW` for views ([routine privileges](https://dev.mysql.com/doc/refman/8.4/en/stored-routines-privileges.html), [CREATE PROCEDURE](https://dev.mysql.com/doc/refman/8.4/en/create-procedure.html), [CREATE VIEW](https://dev.mysql.com/doc/refman/8.4/en/create-view.html)).

Other forward destructive operations found by scanning each `up()` body include: the two table drop/recreates above; the settings `TRUNCATE`; conditional column/FK/index drops; and status `ENUM` alterations using explicit MySQL SQL. Typical `down()` methods drop tables created by their matching migration; several data migrations intentionally have no-op or lossy reversals. MySQL documents implicit commits for many DDL statements, including table alterations and truncation ([implicit commits](https://dev.mysql.com/doc/refman/8.4/en/implicit-commit.html)).

## MySQL 8.4.11 compatibility

* Project runtime is Laravel `^12.0`, PHP requirement `^8.2` (`composer.json`); the approved portable PHP 8.4.26 has `pdo_mysql`. The MySQL PDO connection config uses `utf8mb4`, strict mode, host/port from environment (`config/database.php`, MySQL connection block). The target accepted authenticated queries on 8.4.11.
* Reviewed SQL forms are compatible in principle with MySQL 8.4: InnoDB schema DDL, indexes/foreign keys, `ENUM` `ALTER TABLE ... MODIFY`, views, and stored procedures. There is no migration execution or generated-DDL test, so end-to-end compatibility is **NOT VERIFIED**.
* The explicit ENUM edits in `database/migrations/2026_01_07_193612_update_canceled_to_cancelled_in_statuses.php` contain a SQLite branch and MySQL raw ALTERs; `Modules/Custody` and `Modules/Purchase` also alter ENUMs via raw SQL. Strict SQL mode may reject existing values omitted from replacement ENUMs on a populated schema. On a blank fresh schema the tables are created earlier in the chain and have no user rows, but actual resulting schema remains untested.
* Cashier views group every selected nonaggregate column, which is compatible with MySQL's default `ONLY_FULL_GROUP_BY`; view and procedure creation is conditioned on prerequisite tables. MySQL 8.4's `mysqlx` plugin is disabled in the live instance.
* The migrator grant is sufficient for schema-scoped standard migrations and routines, with rights scoped to the named schema only. No migration contains `CREATE DATABASE`/`DROP DATABASE`; the database itself must remain pre-created. No global `SUPER`, `FILE`, `PROCESS`, or `GRANT OPTION` is granted to the migration account.
* MySQL 8.4.11 and project PHP were verified locally, but **no SQL migration was executed**. Static inspection cannot confirm every generated Laravel grammar statement, constraint-name collision, MySQL data conversion, or runtime branch.

## Target isolation and account selection

Artisan's normal `.env` uses the DML-only runtime account, so the unmodified command below is wrong for migrations. Use process-local overrides from the private migration option file and pass Laravel's explicit `mysql` connection. Set `DB_URL` to Laravel's null sentinel so URL parsing cannot redirect the connection; set host, port, database, and socket explicitly; do not rely on a `--path` that would omit module migration paths. `bootstrap/cache/config.php` is absent, so stale cached connection settings were not found.

**Exact proposed command (not executed):**

```powershell
$ErrorActionPreference = 'Stop'
Set-Location 'D:\claude\AssabERP\Assab'
$credentialFile = 'D:\claude\AssabERP\.tools\s1-01\mysql-migration.cnf'
$passwordLine = Get-Content -LiteralPath $credentialFile | Where-Object { $_ -match '^password=' } | Select-Object -First 1
if (-not $passwordLine) { throw 'Migration credential is missing' }
$env:APP_ENV = 'local'
$env:DB_CONNECTION = 'mysql'
$env:DB_URL = 'null'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3307'
$env:DB_SOCKET = 'null'
$env:DB_DATABASE = 'assab_s1_local'
$env:DB_USERNAME = 'assab_s1_migrator'
$env:DB_PASSWORD = $passwordLine.Substring('password='.Length)
try {
    & 'D:\claude\AssabERP\.tools\s1-01\php-8.4.26\php.exe' artisan migrate --database=mysql
    if ($LASTEXITCODE -ne 0) { throw "Migration command failed with exit code $LASTEXITCODE" }
}
finally {
    foreach ($name in 'APP_ENV','DB_CONNECTION','DB_URL','DB_HOST','DB_PORT','DB_SOCKET','DB_DATABASE','DB_USERNAME','DB_PASSWORD') {
        Remove-Item "Env:$name" -ErrorAction SilentlyContinue
    }
}
```

This selects the migration principal, pinned loopback, port 3307, and the one pre-created schema. It does not request database creation, seed, or force mode. Laravel will create its migration repository table in `assab_s1_local`; module providers register the root and enabled module paths. If later approved, check the resolved connection identity and schema again immediately before execution. Do not run this command as written until the recovery blocker below is resolved and execution is separately approved.

## Backup and recovery proposal

The pre-migration target is empty, so there is no application data to preserve, but the exact schema/account setup should still be recoverable. Preferred recovery is a **cold copy** of `.tools\s1-01\mysql-data` after a clean MySQL shutdown, stored under `.tools\s1-01\backups\mysql-data-pre-migrations-<timestamp>`, plus a checksum and a schema-only SQL dump. On a failed migration, stop the same portable server, preserve the failed data directory for diagnostics, and restore the cold copy; do not use `migrate:rollback` as the recovery mechanism.

The root option file is private, but root is `root@localhost`; with `skip-name-resolve`, TCP authentication as `root@127.0.0.1` failed. The live preflight therefore could not verify a clean administrative shutdown or exercise full-instance restore. The schema-scoped migrator can perform DDL within its granted schema but cannot manage the server or access unrelated application schemas. Resolve this by an approved, local-only recovery method (for example, a verified Windows local transport/admin path) before a migration run. Do not broaden account grants or install a service as part of this preflight.

## GO / NO-GO

**NO-GO now.** The target identity, blank schema, and migration-account scope are verified, and the exact command is constrained to the local database. However, the chain includes destructive legacy migrations and there is no currently verified cold-backup/restore procedure because the local administrative connection path is unavailable. The unsupported data-copy path in the Purchase migrations is a separate blocker for populated databases, even though the approved target currently has no tables or data.

To reconsider, first establish and verify a clean local server shutdown plus cold-copy restore path, review/accept the listed migration data-loss risks, then obtain separate authorization to run the proposed command. No migration, seed, fixture, source edit, privilege change, commit, or push was performed.

</details>
