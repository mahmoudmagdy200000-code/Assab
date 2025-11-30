<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_manager_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_manager_id')->constrained('branch_managers')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->date('shift_date');
            $table->enum('status', ['not_started', 'in_progress', 'completed'])->default('not_started');

            // Timing
            $table->timestamp('actual_start_time')->nullable();
            $table->timestamp('actual_end_time')->nullable();

            // Financial Summary (aggregated from all cashier shifts)
            $table->decimal('total_sales', 12, 2)->default(0.00);
            $table->decimal('net_sales', 12, 2)->default(0.00);
            $table->decimal('vat_amount', 12, 2)->default(0.00);
            $table->decimal('cash_collected', 12, 2)->default(0.00);
            $table->decimal('card_payments', 12, 2)->default(0.00);
            $table->decimal('aggregator_payments', 12, 2)->default(0.00);

            // Handover
            $table->decimal('opening_balance', 12, 2)->default(0.00);
            $table->decimal('closing_balance', 12, 2)->default(0.00);
            $table->decimal('expected_balance', 12, 2)->default(0.00);
            $table->decimal('variance', 12, 2)->default(0.00);

            $table->foreignUuid('next_manager_id')->nullable()->constrained('branch_managers')->nullOnDelete();
            $table->timestamp('handed_over_at')->nullable();
            $table->text('handover_notes')->nullable();

            // Statistics
            $table->integer('total_cashier_shifts')->default(0);
            $table->integer('completed_cashier_shifts')->default(0);
            $table->integer('pending_cashier_shifts')->default(0);

            // Daily Report Submission - NEW
            $table->boolean('daily_report_submitted')->default(false);
            $table->timestamp('daily_report_submitted_at')->nullable();
            $table->text('daily_report_notes')->nullable();

            // Reopen capability - NEW
            $table->boolean('can_reopen')->default(false);
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();

            // Final approval tracking - NEW
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();


            // $table->string('next_manager_id')->nullable();
            // $table->decimal('closing_balance', 10, 2)->default(0);
            // $table->timestamp('handed_over_at')->nullable();
            // $table->text('handover_notes')->nullable();
            $table->enum('handover_status', ['not_submitted', 'pending', 'completed'])->default('not_submitted');
            $table->enum('handover_timing', ['today', 'yesterday'])->nullable();

            // Auto-archive tracking - NEW (Section E)
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            $table->index(['branch_manager_id', 'shift_date']);
            $table->index('status');
            $table->index('daily_report_submitted');
            $table->unique(['branch_manager_id', 'shift_date'], 'unique_manager_shift_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_manager_shifts');
    }
};
