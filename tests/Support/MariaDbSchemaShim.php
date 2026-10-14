<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Disposable-MariaDB environment shim (integration verification only).
 *
 * The baseline PKG-2 migration 2026_10_13_000002_create_asab_brand_manager_assignment_audit emits the 71-character
 * index name `asab_brand_manager_assignment_audit_brand_manager_id_created_at_index`, which MariaDB/MySQL reject
 * (limit 64), so `migrate:fresh` cannot complete on a real MySQL-family server. That migration is guarded by
 * Schema::hasTable(), so this shim pre-creates the table with short index names and then runs the normal migrations.
 * No migration file is changed. Only usable against the explicitly opted-in disposable schema.
 */
final class MariaDbSchemaShim
{
    public static function fresh(): void
    {
        $name = DB::connection()->getDatabaseName();
        if (! preg_match('/\Apkg(6|67)_disposable_[a-z0-9_]+\z/', $name)) {
            throw new \LogicException('Shim refuses a non-disposable schema: '.$name);
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (DB::select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"') as $row) {
            DB::statement('DROP TABLE `'.array_values((array) $row)[0].'`');
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        DB::statement('CREATE TABLE asab_brand_manager_assignment_audit (
            id char(36) NOT NULL PRIMARY KEY, company_id char(36) NULL, brand_manager_id char(36) NOT NULL,
            dashboard_user_id char(36) NULL, brand_ids json NULL, action varchar(16) NOT NULL, granted_by char(36) NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            KEY ambaa_company (company_id), KEY ambaa_bm (brand_manager_id), KEY ambaa_bm_created (brand_manager_id, created_at))');
        Schema::hasTable('migrations');
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new \RuntimeException('migrate failed: '.Artisan::output());
        }
    }
}
