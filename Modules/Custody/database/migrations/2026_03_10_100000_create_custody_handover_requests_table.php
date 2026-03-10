<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custody_handover_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('from_cashier_id')->constrained('cashiers')->cascadeOnDelete();
            $table->foreignUuid('to_cashier_id')->constrained('cashiers')->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('additional_notes', 500)->nullable();

            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            $table->index('to_cashier_id');
            $table->index('status');
            $table->index(['to_cashier_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custody_handover_requests');
    }
};
