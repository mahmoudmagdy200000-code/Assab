<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

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

        // First, check if there's a foreign key on item_id and drop it if exists
        try {
            $dbName = DB::connection()->getDatabaseName();
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = ?
                AND TABLE_NAME = 'branch_inventory'
                AND COLUMN_NAME = 'item_id'
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ", [$dbName]);

            foreach ($foreignKeys as $fk) {
                try {
                    DB::statement("ALTER TABLE `branch_inventory` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                } catch (\Exception $e) {
                    // Try alternative method using Schema
                    try {
                        Schema::table('branch_inventory', function (Blueprint $table) use ($fk) {
                            $table->dropForeign($fk->CONSTRAINT_NAME);
                        });
                    } catch (\Exception $ex) {
                        // Ignore if doesn't exist - might have been dropped already
                    }
                }
            }
        } catch (\Exception $e) {
            // If query fails, try to drop common foreign key names
            $commonNames = [
                'branch_inventory_item_id_foreign',
                'branch_inventory_item_id_branch_item_id_foreign',
            ];

            foreach ($commonNames as $fkName) {
                try {
                    DB::statement("ALTER TABLE `branch_inventory` DROP FOREIGN KEY `{$fkName}`");
                } catch (\Exception $ex) {
                    // Ignore if doesn't exist
                }
            }
        }

        // Now add the new foreign key to items table
        Schema::table('branch_inventory', function (Blueprint $table) {
            // The item_id column already exists, we just need to add the foreign key
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
