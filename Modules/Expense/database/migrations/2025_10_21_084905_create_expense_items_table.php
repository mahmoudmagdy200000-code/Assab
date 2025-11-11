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
        Schema::create('expense_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('expense_id')->constrained('expenses')->cascadeOnDelete();
            $table->foreignUuid('invoice_detail_id')->nullable()->constrained('invoice_details')->cascadeOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->string('name', 255);
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_amount', 12, 2);

            $table->timestamps();

            $table->index('expense_id');
            $table->index('invoice_detail_id');
            $table->index('category_id');
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_items');
    }
};
