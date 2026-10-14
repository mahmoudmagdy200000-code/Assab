<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * B-01 — the PKG-2 delegation-audit migration on a REAL MySQL-family server (opt-in, disposable DB only).
 * No schema shim: every scenario runs the actual migration files through `migrate` / `migrate:fresh`.
 */
class Pkg67MigrationB01Test extends TestCase
{
    private const TABLE = 'asab_brand_manager_assignment_audit';

    private const ORIGINAL = '2026_10_13_000002_create_asab_brand_manager_assignment_audit';

    private const REPAIR = '2026_10_15_000001_repair_asab_brand_manager_assignment_audit_indexes';

    protected function setUp(): void
    {
        parent::setUp();
        if (env('PKG67_MARIADB_TESTS') !== '1' || DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('NOT_RUN: opt-in MariaDB/MySQL gate (PKG67_MARIADB_TESTS=1).');
        }
        if (! preg_match('/\Apkg67_disposable_[a-z0-9_]+\z/', DB::connection()->getDatabaseName())
            || ! in_array(DB::connection()->getConfig('host'), ['localhost', '127.0.0.1'], true)) {
            throw new \LogicException('Refusing non-disposable schema');
        }
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
    }

    /** Re-open the audit migrations as "pending" on top of a fully migrated schema, with a hand-built table. */
    private function pendingWith(?string $createSql): void
    {
        DB::statement('DROP TABLE IF EXISTS '.self::TABLE);
        DB::table('migrations')->whereIn('migration', [self::ORIGINAL, self::REPAIR])->delete();
        if ($createSql !== null) {
            DB::statement($createSql);
        }
    }

    private function indexColumns(): array
    {
        $out = [];
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            $out[$index['name']] = $index['columns'];
        }

        return $out;
    }

    private function seedRows(): string
    {
        foreach ([['grant', '["a"]'], ['change', '["a","b"]'], ['revoke', null]] as [$action, $brands]) {
            DB::table(self::TABLE)->insert(['id' => (string) Str::uuid(), 'company_id' => (string) Str::uuid(),
                'brand_manager_id' => (string) Str::uuid(), 'action' => $action, 'brand_ids' => $brands]);
        }

        return $this->fingerprint();
    }

    private function fingerprint(): string
    {
        return md5(DB::table(self::TABLE)->orderBy('id')->get()->toJson());
    }

    private const FULL = 'CREATE TABLE asab_brand_manager_assignment_audit (id char(36) NOT NULL PRIMARY KEY, company_id char(36) NULL,
        brand_manager_id char(36) NOT NULL, dashboard_user_id char(36) NULL, brand_ids json NULL, action varchar(16) NOT NULL,
        granted_by char(36) NULL, created_at timestamp NOT NULL DEFAULT current_timestamp()';

    public function test_fresh_install_uses_the_real_migrations_and_every_identifier_fits(): void
    {
        $columns = $this->indexColumns();
        $this->assertSame(['brand_manager_id', 'created_at'], $columns['asab_bm_assign_audit_bm_created_idx']);
        $this->assertSame(['brand_manager_id'], $columns['asab_brand_manager_assignment_audit_brand_manager_id_index']);
        $this->assertSame(['company_id'], $columns['asab_brand_manager_assignment_audit_company_id_index']);
        $names = DB::select('SELECT DISTINCT table_name t, index_name n FROM information_schema.statistics WHERE table_schema = DATABASE()');
        $this->assertGreaterThan(900, count($names));
        foreach ($names as $row) {
            $this->assertLessThanOrEqual(64, strlen($row->n), $row->n);
        }
        $this->assertSame(2, DB::table('migrations')->whereIn('migration', [self::ORIGINAL, self::REPAIR])->count());
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]), 'second migrate is a no-op');
    }

    public function test_existing_complete_schema_keeps_every_row_and_gains_no_duplicate_index(): void
    {
        $this->pendingWith(self::FULL.', KEY ambaa_company (company_id), KEY ambaa_bm (brand_manager_id), KEY ambaa_bm_created (brand_manager_id, created_at))');
        $before = $this->seedRows();
        $indexesBefore = $this->indexColumns();

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]), Artisan::output());

        $this->assertSame($before, $this->fingerprint());
        $this->assertSame(3, DB::table(self::TABLE)->count());
        $this->assertSame($indexesBefore, $this->indexColumns(), 'a complete table (any index names) is left untouched');
    }

    public function test_partial_schema_from_the_failed_run_is_completed_without_touching_rows(): void
    {
        // Exactly what the old migration left behind: table + primary key + one secondary index.
        $this->pendingWith(self::FULL.', KEY asab_brand_manager_assignment_audit_brand_manager_id_index (brand_manager_id))');
        $before = $this->seedRows();

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]), Artisan::output());

        $this->assertSame($before, $this->fingerprint());
        $columns = $this->indexColumns();
        $this->assertSame(['brand_manager_id', 'created_at'], $columns['asab_bm_assign_audit_bm_created_idx']);
        $this->assertSame(['company_id'], $columns['asab_brand_manager_assignment_audit_company_id_index']);
        $this->assertCount(4, $columns, 'primary + exactly the three secondary indexes, no duplicates');
    }

    public function test_table_missing_required_columns_fails_safely_and_changes_nothing(): void
    {
        $this->pendingWith('CREATE TABLE asab_brand_manager_assignment_audit (id char(36) NOT NULL PRIMARY KEY, brand_manager_id char(36) NOT NULL)');
        DB::table(self::TABLE)->insert(['id' => (string) Str::uuid(), 'brand_manager_id' => (string) Str::uuid()]);
        $before = $this->fingerprint();
        $indexesBefore = $this->indexColumns();

        try {
            Artisan::call('migrate', ['--force' => true]);
            $this->fail('An incomplete table must abort the repair.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('missing columns', $error->getMessage());
            $this->assertStringContainsString('nothing was changed', $error->getMessage());
        }

        $this->assertSame($before, $this->fingerprint());
        $this->assertSame($indexesBefore, $this->indexColumns());
        $this->assertSame(0, DB::table('migrations')->where('migration', self::REPAIR)->count(), 'a failed repair is not recorded as run');
    }

    public function test_rollback_guard_survives_the_name_change(): void
    {
        $this->seedRows();
        $migration = require base_path('Modules/Admin/database/migrations/'.self::ORIGINAL.'.php');
        try {
            $migration->down();
            $this->fail('down() must refuse while history exists');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('append-only', $error->getMessage());
        }
        $this->assertSame(3, DB::table(self::TABLE)->count());

        DB::table(self::TABLE)->delete();
        $migration->down();
        $this->assertFalse(Schema::hasTable(self::TABLE), 'an empty table rolls back cleanly');
    }
}
