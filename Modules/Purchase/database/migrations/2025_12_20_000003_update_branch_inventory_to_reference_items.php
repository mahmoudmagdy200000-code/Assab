<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Updates branch_inventory to reference items table instead of branch_item
     */
    public function up(): void
    {
        if (!Schema::hasTable('branch_inventory')) {
            return;
        }

        Schema::table('branch_inventory', function (Blueprint $table) {
            // Drop old foreign key if exists (it references branch_item)
            $table->dropForeign(['item_id']);

            // The item_id column already exists, we just need to update the foreign key
            // to reference items table instead of branch_item
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
        if (Schema::hasTable('branch_inventory')) {
            Schema::table('branch_inventory', function (Blueprint $table) {
                $table->dropForeign(['item_id']);

                // Note: We can't restore the old foreign key without knowing the old structure
                // This migration assumes branch_item will be restored separately
            });
        }
    }
};
