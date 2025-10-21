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
        Schema::create('expense_timelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('expenses')->cascadeOnDelete();

            $table->enum('action', ['created', 'updated', 'submit', 'view', 'approve', 'reject', 'resubmit', 'edit']);
            $table->unsignedBigInteger('performed_by');
            $table->enum('performed_by_type', ['branch_manager', 'brand_owner', 'system']);
            $table->string('status', 50);
            $table->text('notes')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('expense_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_timelines');
    }
};
