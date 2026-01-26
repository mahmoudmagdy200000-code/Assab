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
        if (Schema::hasTable('order_tracking_stages')) {
            return;
        }

        Schema::create('order_tracking_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_order_id');

            // Stage type: preparing, out_for_delivery, delivered, order_confirmation, variance_logged
            $table->enum('stage_type', [
                'preparing',
                'out_for_delivery',
                'delivered',
                'order_confirmation',
                'variance_logged'
            ]);

            // Stage data stored as JSON to preserve all information
            $table->json('stage_data');

            // Timestamps for when stage started and completed
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();

            // Optional: who created/updated this stage
            $table->uuid('created_by')->nullable();
            $table->string('created_by_type')->nullable(); // Supplier, BranchManager, System

            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index('purchase_order_id');
            $table->index('stage_type');
            $table->index(['purchase_order_id', 'stage_type']);
            $table->index('started_at');

            // Foreign key
            $table->foreign('purchase_order_id')
                ->references('id')
                ->on('purchase_orders')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_tracking_stages');
    }
};
