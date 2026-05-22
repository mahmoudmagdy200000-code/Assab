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
        if (Schema::hasTable('saved_price_comparisons')) {
            return;
        }

        Schema::create('saved_price_comparisons', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Ownership / tenant isolation
            $table->uuid('branch_id');
            $table->uuid('created_by'); // Branch Manager (user) who saved the comparison

            // Item reference (resolved Item.id captured at save time)
            $table->uuid('item_id');
            $table->string('item_name')->nullable();

            // Comparison inputs
            $table->decimal('quantity', 12, 3)->default(1);

            // Full comparison result captured at save time
            $table->json('snapshot');

            // Optional user note
            $table->string('note', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('branch_id');
            $table->index('created_by');
            $table->index('item_id');
            $table->index('created_at');
            $table->index(['branch_id', 'item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saved_price_comparisons');
    }
};
