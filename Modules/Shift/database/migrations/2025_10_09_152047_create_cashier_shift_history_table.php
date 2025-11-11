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
            $table->uuid('id')->primary();
            $table->foreignUuid('cashier_shift_id')->constrained('cashier_shifts')->cascadeOnDelete();
            $table->string('action', 50);
            $table->unsignedBigInteger('performed_by');
            $table->string('performed_by_type', 50);
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
