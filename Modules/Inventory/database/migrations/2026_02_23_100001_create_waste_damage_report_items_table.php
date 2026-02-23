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
        if (Schema::hasTable('waste_damage_report_items')) {
            return;
        }

        Schema::create('waste_damage_report_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('waste_damage_report_id');
            $table->uuid('branch_id');
            $table->uuid('item_id');
            $table->uuid('purchase_order_item_id')->nullable();
            $table->string('problem_type', 20);
            $table->string('cause_of_damage', 40)->nullable();
            $table->decimal('quantity', 12, 3);
            $table->string('reason', 30);
            $table->string('unit', 20)->nullable();
            $table->decimal('total_value', 12, 2);
            $table->text('justification_text')->nullable();
            $table->string('photo_path')->nullable();
            $table->decimal('price_per_unit', 12, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('waste_damage_report_id');
            $table->index('branch_id');
            $table->index('item_id');
            $table->index('purchase_order_item_id');

            $table->foreign('waste_damage_report_id')
                ->references('id')
                ->on('waste_damage_reports')
                ->cascadeOnDelete();

            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->cascadeOnDelete();

            $table->foreign('item_id')
                ->references('id')
                ->on('items')
                ->cascadeOnDelete();

            $table->foreign('purchase_order_item_id')
                ->references('id')
                ->on('purchase_order_items')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('waste_damage_report_items');
    }
};
