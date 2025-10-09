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
        Schema::create('cashier_shift_history', function (Blueprint $table) {
             $table->id();
            $table->foreignId('cashier_shift_id')->constrained('cashier_shifts')->cascadeOnDelete();
            $table->enum('action', ['assigned', 'started', 'completed', 'reassigned', 'handed_over']);
            $table->unsignedBigInteger('performed_by');
            $table->enum('performed_by_type', ['branch_manager', 'cashier', 'system']);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('cashier_shift_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cashier_shift_history');
    }
};
