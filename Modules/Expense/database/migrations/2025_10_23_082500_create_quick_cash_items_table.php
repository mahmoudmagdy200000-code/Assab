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
        Schema::create('quick_cash_items', function (Blueprint $table) {
             $table->uuid('id');
            $table->foreignUuid('quick_cash_expense_id')
                  ->constrained('quick_cash_expenses')
                  ->cascadeOnDelete();
            $table->string('title', 255);
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quick_cash_items');
    }
};
