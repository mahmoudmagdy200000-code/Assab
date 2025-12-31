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
        // Drop the old table and recreate with correct structure
        Schema::dropIfExists('inventory_items');

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('inventory_session_id');
            $table->uuid('purchase_order_item_id')->nullable();
            $table->uuid('item_id')->nullable();
            $table->string('item_name');
            $table->decimal('quantity_inventory', 12, 3)->default(0);
            $table->text('notes')->nullable();
            $table->uuid('branch_id');
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('inventory_session_id');
            $table->index('purchase_order_item_id');
            $table->index('item_id');
            $table->index('branch_id');

            // Foreign keys
            $table->foreign('inventory_session_id')
                ->references('id')
                ->on('inventory_sessions')
                ->cascadeOnDelete();

            $table->foreign('purchase_order_item_id')
                ->references('id')
                ->on('purchase_order_items')
                ->nullOnDelete();

            $table->foreign('item_id')
                ->references('id')
                ->on('items')
                ->nullOnDelete();

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
        Schema::dropIfExists('inventory_items');

        // Recreate the original simple structure
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }
};
