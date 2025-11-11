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
        Schema::create('items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code')->unique();
            $table->foreignUuid('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->enum('unit', ['KG', 'PK', 'L', 'PC'])->default('PC');
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->decimal('min_stock', 12, 3)->default(0);
            $table->decimal('max_stock', 12, 3)->default(0);
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->decimal('selling_price', 12, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('category_id');
            $table->index('code');
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
