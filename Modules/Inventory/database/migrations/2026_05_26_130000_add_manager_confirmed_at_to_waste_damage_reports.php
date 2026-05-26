<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waste_damage_reports', function (Blueprint $table) {
            $table->timestamp('manager_confirmed_at')->nullable()->after('approved_by');
        });

        // Backfill: any existing staff-submitted reports that were previously auto-completed
        // by the legacy confirm flow are treated as manager-confirmed at their updated_at.
        DB::table('waste_damage_reports')
            ->where('assigned_to_type', 'staff')
            ->whereNotNull('submitted_at')
            ->where('status', 'completed')
            ->whereNull('manager_confirmed_at')
            ->update(['manager_confirmed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('waste_damage_reports', function (Blueprint $table) {
            $table->dropColumn('manager_confirmed_at');
        });
    }
};
