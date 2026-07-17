<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client's 11-column English fixed-assets workbook carries a Zone, a total
 * quantity and a per-condition breakdown (Excellent / Maintenance / Problem)
 * that the register had no home for. All nullable: the 8-column Arabic template
 * supplies none of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asab_assets')) {
            return;
        }

        Schema::table('asab_assets', function (Blueprint $t) {
            if (! Schema::hasColumn('asab_assets', 'zone')) {
                $t->string('zone', 80)->nullable()->after('branch_id');
            }
            if (! Schema::hasColumn('asab_assets', 'quantity')) {
                $t->unsignedInteger('quantity')->nullable()->after('zone');
            }
            if (! Schema::hasColumn('asab_assets', 'qty_excellent')) {
                $t->unsignedInteger('qty_excellent')->nullable()->after('quantity');
            }
            if (! Schema::hasColumn('asab_assets', 'qty_maintenance')) {
                $t->unsignedInteger('qty_maintenance')->nullable()->after('qty_excellent');
            }
            if (! Schema::hasColumn('asab_assets', 'qty_problem')) {
                $t->unsignedInteger('qty_problem')->nullable()->after('qty_maintenance');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('asab_assets')) {
            return;
        }

        Schema::table('asab_assets', function (Blueprint $t) {
            foreach (['zone', 'quantity', 'qty_excellent', 'qty_maintenance', 'qty_problem'] as $col) {
                if (Schema::hasColumn('asab_assets', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
