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
        Schema::create('branch_shift_roster_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->foreignUuid('cashier_id')->constrained('cashiers')->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'shift_id', 'effective_from'], 'roster_branch_shift_effective_idx');
            $table->index(['cashier_id', 'effective_from'], 'roster_cashier_effective_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_shift_roster_assignments');
    }
};
