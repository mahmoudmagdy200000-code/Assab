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
        Schema::create('shift_variance_details', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cashier_shift_id')->constrained('cashier_shifts')->cascadeOnDelete();
            $table->decimal('variance_amount', 12, 2);
            $table->enum('variance_type', ['over', 'short']);
            $table->enum('responsibility_type', ['self', 'self_and_others', 'other_factors', 'mixed']);
            $table->foreignUuid('responsible_cashier_id')->nullable()->constrained('cashiers')->nullOnDelete();
            $table->decimal('assigned_amount', 12, 2)->nullable();
            $table->text('reason')->nullable();
            $table->json('supporting_files')->nullable();
            $table->timestamps();

            $table->index('cashier_shift_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_variance_details');
    }
};
