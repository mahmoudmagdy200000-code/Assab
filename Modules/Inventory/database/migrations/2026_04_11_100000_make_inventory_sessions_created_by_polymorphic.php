<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_sessions', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->string('created_by_type', 50)->after('created_by')->default('branch_manager');
        });

        DB::table('inventory_sessions')
            ->whereNull('created_by_type')
            ->orWhere('created_by_type', 'branch_manager')
            ->update(['created_by_type' => 'branch_manager']);
    }

    public function down(): void
    {
        Schema::table('inventory_sessions', function (Blueprint $table) {
            $table->dropColumn('created_by_type');

            $table->foreign('created_by')
                ->references('id')
                ->on('branch_managers')
                ->cascadeOnDelete();
        });
    }
};
