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
        if (Schema::hasTable('supplier_inventory')) {
            return;
        }

        Schema::create('supplier_inventory', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('supplier_id');
            $table->uuid('product_id'); // Reference to supplier_products
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('reserved_quantity', 12, 3)->default(0); // Reserved for orders
            $table->decimal('available_quantity', 12, 3)->virtualAs('quantity - reserved_quantity');
            $table->decimal('reorder_level', 12, 3)->nullable(); // Alert when below this
            $table->decimal('max_stock_level', 12, 3)->nullable();
            $table->date('last_restocked_at')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('batch_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('supplier_id')
                ->references('id')
                ->on('suppliers')
                ->cascadeOnDelete();

            $table->foreign('product_id')
                ->references('id')
                ->on('supplier_products')
                ->cascadeOnDelete();

            $table->index('supplier_id');
            $table->index('product_id');
            $table->index('quantity');
            $table->index('expiry_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_inventory');
    }
};
