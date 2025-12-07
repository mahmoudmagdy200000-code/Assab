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
        if (Schema::hasTable('return_orders')) {
            return;
        }

        Schema::create('return_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('return_number')->unique();
            $table->uuid('purchase_order_id');
            $table->uuid('supplier_id')->nullable();
            $table->uuid('branch_id');
            $table->uuid('created_by'); // Branch Manager ID

            // Dates
            $table->date('return_date');

            // Status
            $table->enum('status', [
                'draft',
                'pending',
                'approved',
                'rejected',
                'escalated',
                'closed',
                'resolved'
            ])->default('draft');

            // Required action
            $table->enum('required_action', [
                'replacement',
                'cash_refund',
                'credit_future_order'
            ]);

            // Financial
            $table->decimal('total_return_amount', 12, 2)->default(0);
            $table->decimal('refund_amount', 12, 2)->nullable();
            $table->string('refund_method')->nullable();

            // Notes
            $table->text('additional_notes')->nullable();

            // Supplier response
            $table->uuid('responded_by')->nullable();
            $table->text('response_notes')->nullable();
            $table->json('response_files')->nullable();
            $table->timestamp('responded_at')->nullable();

            // Rejection details
            $table->text('rejection_reason')->nullable();
            $table->timestamp('rejected_at')->nullable();

            // Escalation
            $table->boolean('is_escalated')->default(false);
            $table->text('escalation_reason')->nullable();
            $table->uuid('escalated_to')->nullable();
            $table->timestamp('escalated_at')->nullable();

            // Resolution
            $table->enum('resolution_type', ['replacement', 'refund'])->nullable();
            $table->text('resolution_notes')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();

            // Submission
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('return_number');
            $table->index('purchase_order_id');
            $table->index('supplier_id');
            $table->index('branch_id');
            $table->index('status');
            $table->index('created_by');

            // Foreign keys
            $table->foreign('purchase_order_id')
                ->references('id')
                ->on('purchase_orders')
                ->cascadeOnDelete();

            $table->foreign('supplier_id')
                ->references('id')
                ->on('purchase_suppliers')
                ->nullOnDelete();

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
        Schema::dropIfExists('return_orders');
    }
};
