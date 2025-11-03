<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('item_name');
            $table->decimal('return_quantity', 12, 3)->default(0);
            $table->enum('unit', ['KG', 'PK', 'L']);
            $table->enum('quality_reason', ['excellent', 'normal', 'poor']);
            $table->decimal('return_amount', 12, 2)->default(0);
            $table->json('files')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('purchase_return_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
    }
};
