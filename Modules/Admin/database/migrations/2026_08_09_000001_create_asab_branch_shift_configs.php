<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shift timings could only be set per BRAND. The client runs branches whose
 * schedule differs from the rest of their brand (a 24h branch inside a brand on
 * 2×8h), so «تعيين الشفتات» now works either way: brand-wide, or one branch
 * overriding it (meeting 2026-08-09).
 *
 * Also widens the duration to MINUTES on both tables: the duration field became
 * free-text on the dashboard (it was a 4/6/8/10/12 dropdown), and a typed 7.5
 * has to survive the round trip. `duration_hours` stays as the rounded integer
 * so every existing reader keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asab_branch_shift_configs')) {
            Schema::create('asab_branch_shift_configs', function (Blueprint $t) {
                $t->uuid('id')->primary();
                // Unique: one override per branch — the natural key of the row.
                $t->uuid('branch_id')->unique();
                $t->integer('num_shifts')->default(1);
                $t->integer('duration_hours')->default(8);
                $t->integer('duration_minutes')->nullable();
                $t->string('first_shift_start', 8)->default('06:00');
                $t->json('shifts')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasColumn('asab_brand_shift_configs', 'duration_minutes')) {
            Schema::table('asab_brand_shift_configs', function (Blueprint $t) {
                $t->integer('duration_minutes')->nullable()->after('duration_hours');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asab_branch_shift_configs');

        if (Schema::hasColumn('asab_brand_shift_configs', 'duration_minutes')) {
            Schema::table('asab_brand_shift_configs', function (Blueprint $t) {
                $t->dropColumn('duration_minutes');
            });
        }
    }
};
