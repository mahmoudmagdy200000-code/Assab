<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_comparisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->enum('order_type', ['direct_supplier', 'purchasing_officer', 'internal_transfer']);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transfer_from_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->decimal('price', 12, 2)->default(0);
            $table->integer('delivery_days')->default(0);
            $table->decimal('rating', 3, 1)->default(0);
            $table->date('recorded_date');
            $table->timestamps();

            $table->index(['item_id', 'branch_id', 'order_type']);
            $table->index('recorded_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_comparisons');
    }
};
