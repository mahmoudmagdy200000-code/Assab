<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waste_damage_reports', function (Blueprint $table) {
            // Drop the FK that only allows branch_managers
            $table->dropForeign(['created_by']);

            // Add polymorphic type column
            $table->string('created_by_type', 50)->after('created_by')->default('branch_manager');
        });

        // Backfill existing rows
        DB::table('waste_damage_reports')
            ->whereNull('created_by_type')
            ->orWhere('created_by_type', 'branch_manager')
            ->update(['created_by_type' => 'branch_manager']);
    }

    public function down(): void
    {
        Schema::table('waste_damage_reports', function (Blueprint $table) {
            $table->dropColumn('created_by_type');

            $table->foreign('created_by')
                ->references('id')
                ->on('branch_managers')
                ->cascadeOnDelete();
        });
    }
};
