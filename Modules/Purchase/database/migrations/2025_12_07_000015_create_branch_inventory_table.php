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
        if (Schema::hasTable('branch_inventory')) {
            return;
        }

        Schema::create('branch_inventory', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('item_id');

            // Quantities
            $table->decimal('available_quantity', 12, 3)->default(0);
            $table->decimal('reserved_quantity', 12, 3)->default(0);
            $table->decimal('daily_consumption', 12, 3)->default(0);
            $table->decimal('weekend_forecast', 12, 3)->default(0);

            // Next supply
            $table->date('next_supply_date')->nullable();

            // Quality and expiry
            $table->enum('quality', ['economy', 'standard', 'premium'])->nullable();
            $table->date('earliest_expiry_date')->nullable();
            $table->boolean('cooling_status')->default(false);

            // Last update
            $table->timestamp('last_inventory_update')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('branch_id');
            $table->index('item_id');
            $table->unique(['branch_id', 'item_id']);

            // Foreign keys
            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_inventory');
    }
};
