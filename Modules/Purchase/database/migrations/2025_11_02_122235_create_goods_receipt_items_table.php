<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('item_name');
            $table->decimal('quantity_ordered', 12, 3)->default(0);
            $table->decimal('quantity_received', 12, 3)->default(0);
            $table->enum('unit', ['KG', 'PK', 'L']);
            $table->enum('quality', ['normal', 'excellent', 'poor'])->default('normal');
            $table->decimal('temperature', 5, 2)->nullable();
            $table->date('expiration_date')->nullable();
            $table->string('photo')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_gift')->default(false);
            $table->decimal('price_per_unit', 12, 2)->default(0);
            $table->text('reason_for_addition')->nullable();
            $table->timestamps();

            $table->index('goods_receipt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
    }
};
