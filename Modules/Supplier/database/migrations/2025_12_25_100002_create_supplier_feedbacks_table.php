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
        if (Schema::hasTable('supplier_feedbacks')) {
            return;
        }

        Schema::create('supplier_feedbacks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('supplier_id');
            $table->uuid('purchase_order_id');
            $table->uuid('branch_id');
            $table->decimal('rating', 3, 2)->comment('Rating from 1.00 to 5.00');
            $table->text('quality_feedback')->nullable();
            $table->text('delivery_satisfaction')->nullable();
            $table->text('improvement_insights')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('supplier_id')
                ->references('id')
                ->on('suppliers')
                ->onDelete('cascade');

            $table->foreign('purchase_order_id')
                ->references('id')
                ->on('purchase_orders')
                ->onDelete('cascade');

            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->onDelete('cascade');

            $table->index('supplier_id');
            $table->index('purchase_order_id');
            $table->index('branch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_feedbacks');
    }
};
