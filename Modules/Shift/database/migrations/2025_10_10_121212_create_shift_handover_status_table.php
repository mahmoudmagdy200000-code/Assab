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
        Schema::create('shift_handover_status', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cashier_shift_id')->constrained('cashier_shifts')->cascadeOnDelete();
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('cashiers')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->json('rejection_files')->nullable();
            $table->text('manager_comment')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique('cashier_shift_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_handover_status');
    }
};
