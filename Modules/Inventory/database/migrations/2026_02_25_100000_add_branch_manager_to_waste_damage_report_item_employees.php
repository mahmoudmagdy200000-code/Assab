<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Allow Branch Manager (report creator) to be recorded as responsible with their quantity_accountable.
     */
    public function up(): void
    {
        Schema::table('waste_damage_report_item_employees', function (Blueprint $table) {
            $table->uuid('branch_manager_id')->nullable()->after('waste_damage_report_item_id');
            $table->index('branch_manager_id', 'wd_rie_branch_manager_id_idx');
        });

        Schema::table('waste_damage_report_item_employees', function (Blueprint $table) {
            $table->uuid('cashier_id')->nullable()->change();
        });

        Schema::table('waste_damage_report_item_employees', function (Blueprint $table) {
            $table->foreign('branch_manager_id', 'wd_rie_branch_manager_id_fk')
                ->references('id')
                ->on('branch_managers')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('waste_damage_report_item_employees', function (Blueprint $table) {
            $table->dropForeign('wd_rie_branch_manager_id_fk');
            $table->dropIndex('wd_rie_branch_manager_id_idx');
            $table->dropColumn('branch_manager_id');
        });

        Schema::table('waste_damage_report_item_employees', function (Blueprint $table) {
            $table->uuid('cashier_id')->nullable(false)->change();
        });
    }
};
