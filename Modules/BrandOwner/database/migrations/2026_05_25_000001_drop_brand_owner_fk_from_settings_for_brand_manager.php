<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brand Manager (Modules\BrandOwner\Models\BrandManager) shares all brand-owner
 * endpoints. The 5 settings tables FK their `brand_owner_id` column to
 * `brand_owners.id`, which fails when the authenticated user is a brand manager
 * (their UUID lives in `brand_managers`). Drop the FK; the column now stores
 * either a brand-owner or brand-manager UUID. Index is preserved.
 */
return new class extends Migration
{
    private array $tables = [
        'brand_owner_setting_approvals' => 'bo_setting_approval_fk',
        'brand_owner_setting_reports' => 'bo_setting_report_fk',
        'brand_owner_setting_securities' => 'bo_setting_security_fk',
        'brand_owner_setting_retentions' => 'bo_setting_retention_fk',
        'brand_owner_setting_notifications' => 'bo_setting_notif_fk',
    ];

    public function up(): void
    {
        // SQLite (test driver) does not support dropping foreign keys by name and
        // does not enforce these FKs anyway — skip; MySQL/production runs the drop.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach ($this->tables as $table => $fk) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($fk) {
                try {
                    $blueprint->dropForeign($fk);
                } catch (\Throwable $e) {
                    // FK may have been dropped already; ignore.
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table => $fk) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($fk) {
                $blueprint->foreign('brand_owner_id', $fk)
                    ->references('id')
                    ->on('brand_owners')
                    ->cascadeOnDelete();
            });
        }
    }
};
