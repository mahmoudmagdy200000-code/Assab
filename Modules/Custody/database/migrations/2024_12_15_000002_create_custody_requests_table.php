<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custody_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_manager_id')->constrained('branch_managers')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();

            $table->decimal('requested_amount', 12, 2);
            $table->text('purpose');
            $table->enum('preferred_receipt_method', ['Cash Handover', 'Bank Transfer']);
            $table->text('additional_notes')->nullable();

            $table->enum('status', ['Pending', 'Approved', 'Rejected', 'Completed', 'Cancelled'])->default('Pending');

            // Approval tracking
            $table->foreignUuid('approved_by')->nullable()->constrained('branch_managers')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('rejected_by')->nullable()->constrained('branch_managers')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // View tracking
            $table->timestamp('viewed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('branch_manager_id');
            $table->index('status');
            $table->index('created_at');
            $table->index(['branch_manager_id', 'status'], 'idx_cr_bm_id_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custody_requests');
    }
};
