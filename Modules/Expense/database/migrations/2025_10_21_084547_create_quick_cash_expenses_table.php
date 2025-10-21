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
        Schema::create('quick_cash_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('expenses')->cascadeOnDelete();
            $table->date('expense_date');
            $table->string('expense_name', 255);
            $table->boolean('has_vat')->default(true);
            $table->string('invoice_number', 100)->nullable();
            $table->timestamps();

            $table->unique('expense_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quick_cash_expenses');
    }
};
