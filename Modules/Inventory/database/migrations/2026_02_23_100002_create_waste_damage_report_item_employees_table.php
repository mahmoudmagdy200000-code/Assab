<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('waste_damage_report_item_employees')) {
            return;
        }

        Schema::create('waste_damage_report_item_employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('waste_damage_report_item_id');
            $table->uuid('cashier_id');
            $table->decimal('quantity_accountable', 12, 3);
            $table->timestamps();

            $table->index('waste_damage_report_item_id', 'wd_rie_report_item_id_idx');
            $table->index('cashier_id', 'wd_rie_cashier_id_idx');

            $table->foreign('waste_damage_report_item_id', 'wd_rie_report_item_id_fk')
                ->references('id')
                ->on('waste_damage_report_items')
                ->cascadeOnDelete();

            $table->foreign('cashier_id', 'wd_rie_cashier_id_fk')
                ->references('id')
                ->on('cashiers')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('waste_damage_report_item_employees');
    }
};
