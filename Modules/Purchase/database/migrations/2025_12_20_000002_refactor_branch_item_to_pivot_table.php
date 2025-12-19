<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Refactors branch_item from standalone table to pivot table (many-to-many relationship)
     * Note: Data migration is handled in a separate migration (2025_12_20_000004)
     */
    public function up(): void
    {
        // Backup old table structure first (if exists and not already backed up)
        if (Schema::hasTable('branch_item') && !Schema::hasTable('branch_item_old_backup')) {
            Schema::rename('branch_item', 'branch_item_old_backup');
        }

        // Create new pivot table structure
        Schema::create('branch_item', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('item_id'); // References items table
            $table->decimal('price', 12, 2)->default(0); // Branch-specific price
            $table->decimal('quantity', 12, 3)->default(0); // Branch-specific quantity (legacy from BranchItem.item_quantity)
            $table->timestamps();

            // Indexes
            $table->index('branch_id');
            $table->index('item_id');
            $table->unique(['branch_id', 'item_id']);

            // Foreign keys
            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->cascadeOnDelete();

            $table->foreign('item_id')
                ->references('id')
                ->on('items')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('branch_item')) {
            Schema::dropIfExists('branch_item');
        }

        // Restore backup if exists
        if (Schema::hasTable('branch_item_old_backup')) {
            Schema::rename('branch_item_old_backup', 'branch_item');
        }
    }
};
