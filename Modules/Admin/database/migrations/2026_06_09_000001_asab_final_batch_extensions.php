<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final-batch column additions (FE completion request §1.2 / §1.7):
 *  - asab_brands.auto_reminder_enabled — brand-level auto-reminder toggle.
 *  - asab_upload_status progress columns — per-upload progress polling.
 * Portable types only (uuid/string/int/bool/json/timestamp) so the SQLite test
 * driver runs them unchanged.
 */
return new class extends Migration
{
    private function add(string $table, string $column, callable $def): void
    {
        if (Schema::hasTable($table) && ! Schema::hasColumn($table, $column)) {
            Schema::table($table, fn (Blueprint $t) => $def($t));
        }
    }

    public function up(): void
    {
        $this->add('asab_brands', 'auto_reminder_enabled', function (Blueprint $t) {
            $t->boolean('auto_reminder_enabled')->default(true)->after('status');
        });

        $this->add('asab_upload_status', 'status', function (Blueprint $t) {
            // queued|processing|done|failed
            $t->string('status', 16)->default('done')->after('upload_type');
            $t->unsignedTinyInteger('progress_pct')->default(100)->after('status');
            $t->integer('parsed_rows')->default(0)->after('progress_pct');
            $t->integer('failed_rows')->default(0)->after('parsed_rows');
            $t->text('failure_reason')->nullable()->after('failed_rows');
            $t->timestamp('started_at')->nullable()->after('failure_reason');
            $t->timestamp('finished_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('asab_brands', 'auto_reminder_enabled')) {
            Schema::table('asab_brands', fn (Blueprint $t) => $t->dropColumn('auto_reminder_enabled'));
        }
        foreach (['status', 'progress_pct', 'parsed_rows', 'failed_rows', 'failure_reason', 'started_at', 'finished_at'] as $col) {
            if (Schema::hasColumn('asab_upload_status', $col)) {
                Schema::table('asab_upload_status', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
