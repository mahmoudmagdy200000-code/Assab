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
        if (Schema::hasTable('price_histories')) {
            return;
        }

        Schema::create('price_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Item reference
            $table->uuid('item_id');
            $table->string('item_name');

            // Source type
            $table->enum('source_type', [
                'direct_supplier',
                'via_purchasing_officer',
                'internal_transfer'
            ]);

            // Source reference
            $table->uuid('source_id')->nullable(); // Supplier ID, Officer ID, or Branch ID
            $table->string('source_name')->nullable();

            // Price data
            $table->decimal('unit_price', 12, 2);
            $table->enum('quality_level', ['economy', 'standard', 'premium'])->nullable();
            $table->enum('unit_of_measurement', ['kg', 'pk', 'unit', 'box', 'liter', 'piece'])->default('kg');

            // Delivery information
            $table->integer('delivery_days')->nullable();
            $table->decimal('rating', 3, 2)->nullable();

            // Period
            $table->date('recorded_date');
            $table->string('period_month'); // Format: YYYY-MM

            $table->timestamps();

            // Indexes
            $table->index('item_id');
            $table->index('source_type');
            $table->index('source_id');
            $table->index('recorded_date');
            $table->index('period_month');
            $table->index(['item_id', 'source_type', 'period_month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_histories');
    }
};
