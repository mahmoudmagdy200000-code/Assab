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
        Schema::create('shift_sales_breakdown', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_shift_id')->constrained('cashier_shifts')->cascadeOnDelete();
            $table->foreignId('aggregator_id')->constrained('aggregators')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('cashier_shift_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_sales_breakdown');
    }
};
