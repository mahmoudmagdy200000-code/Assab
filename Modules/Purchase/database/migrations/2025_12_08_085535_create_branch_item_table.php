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
        Schema::create('branch_item', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('branches');
            $table->string('item_name');
            $table->text('item_logo')->nullable();
            $table->string('item_code')->nullable();
            $table->string('item_unit')->nullable();
            $table->decimal('item_price', 12, 2)->default(0);
            $table->decimal('item_quantity', 12, 3)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_item');
    }
};
