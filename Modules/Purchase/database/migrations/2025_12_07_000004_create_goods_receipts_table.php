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
        if (Schema::hasTable('goods_receipts')) {
            return;
        }

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('receipt_number')->unique();
            $table->uuid('purchase_order_id');
            $table->uuid('branch_id');
            $table->uuid('received_by'); // Branch Manager ID

            // Status
            $table->enum('status', [
                'draft',
                'in_progress',
                'completed',
                'has_variance'
            ])->default('draft');

            // Delivery details
            $table->string('driver_name')->nullable();
            $table->string('driver_contact')->nullable();
            $table->string('driver_image')->nullable();
            $table->string('vehicle_number')->nullable();
            $table->timestamp('arrival_time')->nullable();
            $table->string('delivery_address')->nullable();
            $table->text('delivery_notes')->nullable();

            // Inspection summary
            $table->integer('total_items_expected')->default(0);
            $table->integer('total_items_received')->default(0);
            $table->integer('quantity_variances')->default(0);
            $table->integer('quality_variances')->default(0);

            // Financial
            $table->decimal('expected_amount', 12, 2)->default(0);
            $table->decimal('received_amount', 12, 2)->default(0);
            $table->decimal('variance_amount', 12, 2)->default(0);

            // Document type
            $table->enum('document_type', [
                'invoice',
                'delivery_note',
                'receipt_without_document'
            ])->nullable();

            // Completion
            $table->timestamp('inspection_started_at')->nullable();
            $table->timestamp('inspection_completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('receipt_number');
            $table->index('purchase_order_id');
            $table->index('branch_id');
            $table->index('status');

            // Foreign keys
            $table->foreign('purchase_order_id')
                ->references('id')
                ->on('purchase_orders')
                ->cascadeOnDelete();

            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_receipts');
    }
};
