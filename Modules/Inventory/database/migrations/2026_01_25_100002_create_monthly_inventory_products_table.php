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
        if (Schema::hasTable('monthly_inventory_products')) {
            return;
        }

        Schema::create('monthly_inventory_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('monthly_inventory_id');

            $table->uuid('item_id')->nullable();
            $table->uuid('purchase_order_item_id')->nullable();
            $table->string('item_name');
            $table->string('unit', 32)->nullable();
            $table->decimal('quantity_inventory', 12, 3)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->string('category')->nullable();
            $table->string('subcategory')->nullable();

            $table->string('count_method', 32)->nullable();
            $table->json('count_metadata')->nullable();

            $table->uuid('handled_by_id')->nullable();
            $table->string('handled_by_type', 64)->nullable();
            $table->timestamp('locked_at')->nullable();

            $table->uuid('branch_id');
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('monthly_inventory_id');
            $table->index('item_id');
            $table->index(['handled_by_id', 'handled_by_type']);
            $table->index('locked_at');
            $table->index('branch_id');

            $table->foreign('monthly_inventory_id')
                ->references('id')
                ->on('monthly_inventories')
                ->cascadeOnDelete();

            $table->foreign('item_id')
                ->references('id')
                ->on('items')
                ->nullOnDelete();

            $table->foreign('purchase_order_item_id')
                ->references('id')
                ->on('purchase_order_items')
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
        Schema::dropIfExists('monthly_inventory_products');
    }
};
