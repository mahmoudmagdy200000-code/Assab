<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_modifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_item_id')->nullable()->constrained('purchase_order_items')->cascadeOnDelete();
            $table->unsignedBigInteger('modified_by_id');
            $table->string('modified_by_type'); // NEW
            $table->enum('modification_type', [
                'quantity_change',
                'delivery_time_change',
                'alternative_product',
                'price_change'
            ]);
            $table->json('original_value')->nullable();
            $table->json('new_value')->nullable();
            $table->text('note')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->unsignedBigInteger('approved_by_id')->nullable();
            $table->string('approved_by_type')->nullable(); // NEW
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index('purchase_order_id');
            $table->index(['modified_by_id', 'modified_by_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_modifications');
    }
};
