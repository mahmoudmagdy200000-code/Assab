<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
             $table->uuid('id');
            $table->foreignUuid('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained()->cascadeOnDelete();
            $table->string('item_name');
            $table->decimal('quantity', 12, 3)->default(0);
            $table->enum('unit', ['KG', 'PK', 'L']);
            $table->enum('quality', ['economy', 'standard', 'premium'])->default('standard');
            $table->decimal('rate', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->enum('status', ['pending', 'confirmed', 'rejected', 'modified'])->default('pending');
            $table->decimal('requested_quantity', 12, 3)->nullable();
            $table->decimal('confirmed_quantity', 12, 3)->nullable();
            $table->decimal('received_quantity', 12, 3)->nullable();
            $table->decimal('variance_quantity', 12, 3)->nullable();
            $table->enum('variance_type', ['short', 'damage', 'over'])->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('modification_note')->nullable();
            $table->foreignUuid('alternative_item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->timestamps();

            $table->index('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
