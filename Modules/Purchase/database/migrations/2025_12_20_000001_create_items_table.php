<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates central items table - items are shared across all branches
     */
    public function up(): void
    {
        if (Schema::hasTable('items')) {
            return;
        }

        Schema::create('items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name'); // Item name (unique across all branches)
            $table->text('logo')->nullable(); // Item logo/images
            $table->string('code')->nullable()->unique(); // Item code (SKU) - unique
            $table->string('unit')->nullable(); // Unit of measurement (kg, piece, cup, etc.)
            $table->string('category')->nullable(); // Category
            $table->string('subcategory')->nullable(); // Subcategory
            $table->text('description')->nullable(); // Item description
            $table->boolean('is_active')->default(true); // Active status
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('name');
            $table->index('code');
            $table->index('category');
            $table->index('is_active');
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
