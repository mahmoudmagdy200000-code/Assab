<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shift_handovers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Reference to cashier shift
            $table->foreignUuid('cashier_shift_id')->constrained('cashier_shifts')->cascadeOnDelete();

            // Handover recipient (polymorphic: branch_manager or cashier)
            $table->uuid('handover_to_id');
            $table->string('handover_to_type'); // 'branch_manager' or 'cashier'

            // Handover details
            $table->decimal('handover_amount', 12, 2);
            $table->decimal('variance_amount', 12, 2)->default(0);
            $table->text('variance_reason')->nullable();
            $table->json('variance_files')->nullable();
            $table->text('handover_notes')->nullable();
            $table->date('handover_date');
            $table->timestamp('handover_time');

            // Status and approval
            $table->enum('status', ['pending', 'approved', 'rejected', 'rejected_final'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->integer('rejection_count')->default(0);
            $table->timestamp('first_rejected_at')->nullable();
            $table->timestamp('second_rejected_at')->nullable();

            // Approver (polymorphic: branch_manager or cashier)
            $table->uuid('approved_by_id')->nullable();
            $table->string('approved_by_type')->nullable();
            $table->timestamp('approved_at')->nullable();

            // Handover timestamp
            $table->timestamp('handed_over_at')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes for performance
            $table->index(['handover_to_id', 'handover_to_type']);
            $table->index(['approved_by_id', 'approved_by_type']);
            $table->index('status');
            $table->index('handover_date');
            $table->index('cashier_shift_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_shift_handovers');
    }
};
