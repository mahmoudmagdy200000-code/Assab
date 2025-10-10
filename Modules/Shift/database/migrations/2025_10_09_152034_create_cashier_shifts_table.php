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
        Schema::create('cashier_shifts', function (Blueprint $table) {
           $table->id();
            $table->foreignId('cashier_id')->constrained('cashiers')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->date('shift_date');
            $table->enum('status', ['not_started', 'in_progress', 'completed', 'reassigned'])->default('not_started');

            $table->decimal('opening_balance', 12, 2)->default(0.00);
            $table->decimal('closing_balance', 12, 2)->default(0.00);
            $table->decimal('expected_balance', 12, 2)->default(0.00);
            $table->decimal('variance', 12, 2)->default(0.00);

            $table->timestamp('actual_start_time')->nullable();
            $table->timestamp('actual_end_time')->nullable();

            $table->foreignId('next_cashier_id')->nullable()->constrained('cashiers')->nullOnDelete();
            $table->timestamp('handed_over_at')->nullable();
            $table->text('handover_notes')->nullable();

            $table->foreignId('original_cashier_id')->nullable()->constrained('cashiers')->nullOnDelete();
            $table->foreignId('reassigned_by')->nullable()->constrained('branch_managers')->nullOnDelete();;
            $table->text('reassignment_reason')->nullable();
            $table->timestamp('reassigned_at')->nullable();

            $table->timestamps();

            $table->index('cashier_id');
            $table->index('shift_date');
            $table->index('status');
            $table->index('shift_id');
            $table->unique(['cashier_id', 'shift_id', 'shift_date'], 'unique_cashier_shift_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cashier_shifts');
    }
};
