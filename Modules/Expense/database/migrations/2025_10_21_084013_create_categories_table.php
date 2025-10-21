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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->enum('type', ['purchase', 'expense'])->default('purchase');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('parent_id');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
