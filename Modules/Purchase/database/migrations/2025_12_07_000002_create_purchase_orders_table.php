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
        if (Schema::hasTable('purchase_orders')) {
            return;
        }

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('order_number')->unique();

            // Order Type (polymorphic source support)
            $table->enum('order_type', [
                'direct_supplier',
                'via_purchasing_officer',
                'internal_transfer',
                'multiple_sources',
                'transfer_received',
            ]);

            // Status tracking
            $table->enum('status', [
                'draft',
                'pending',
                'pending_confirmation',
                'pending_approval',
                'partial_confirmation',
                'confirmed',
                'preparing',
                'on_the_way',
                'delivered',
                'closed',
                'canceled',
                'rejected',
                'delayed',
            ])->default('draft');

            // Branch information
            $table->uuid('branch_id');
            $table->uuid('requested_by'); // Branch Manager ID

            // Source information (polymorphic)
            $table->uuidMorphs('sourceable'); // supplier_id OR purchasing_officer_id OR from_branch_id

            // For internal transfers
            $table->uuid('from_branch_id')->nullable();
            $table->uuid('to_branch_id')->nullable();

            // Supplier specific
            $table->uuid('supplier_id')->nullable();

            // Quality and processing
            $table->enum('quality_level', ['economy', 'standard', 'premium'])->nullable();
            $table->enum('processing_time', ['standard', 'urgent'])->nullable();
            $table->enum('priority', ['high', 'normal'])->default('normal');

            // Delivery information
            $table->date('preferred_delivery_date')->nullable();
            $table->date('latest_delivery_date')->nullable();
            $table->timestamp('expected_delivery_at')->nullable();
            $table->timestamp('actual_delivery_at')->nullable();

            // Notification preferences
            $table->json('notification_channels')->nullable(); // ['email', 'whatsapp', 'app', 'sms']

            // Financial information
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(15.00); // VAT 15%
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);

            // Item counts
            $table->integer('total_items')->default(0);
            $table->integer('received_items')->default(0);

            // Messages and notes
            $table->text('message')->nullable();
            $table->text('special_instructions')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('delay_reason')->nullable();

            // Transfer specific
            $table->string('transport_method')->nullable();
            $table->string('estimated_transport_hours')->nullable();
            $table->string('driver_name')->nullable();
            $table->string('driver_contact')->nullable();
            $table->string('vehicle_number')->nullable();
            $table->decimal('temperature', 5, 2)->nullable();
            $table->boolean('cooling_status')->nullable();

            // Ready time for transfers
            $table->enum('ready_time', ['3_minutes', '1_hour', '2_hours', '3_hours', 'more_than_3_hours'])->nullable();

            // Relationship to parent order (for multiple sources)
            $table->uuid('parent_order_id')->nullable();

            // Timestamps
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('preparation_started_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('rejected_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('order_number');
            $table->index('status');
            $table->index('order_type');
            $table->index('branch_id');
            $table->index('supplier_id');
            $table->index('requested_by');
            $table->index('created_at');
            $table->index(['status', 'order_type']);
            $table->index(['branch_id', 'status']);

            // Foreign keys
            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->cascadeOnDelete();

            $table->foreign('from_branch_id')
                ->references('id')
                ->on('branches')
                ->nullOnDelete();

            $table->foreign('to_branch_id')
                ->references('id')
                ->on('branches')
                ->nullOnDelete();

            $table->foreign('supplier_id')
                ->references('id')
                ->on('purchase_suppliers')
                ->nullOnDelete();

            $table->foreign('parent_order_id')
                ->references('id')
                ->on('purchase_orders')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
