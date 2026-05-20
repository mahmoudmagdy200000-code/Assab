<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        // If branch_item exists (from previous migration), we need to refactor it
        if (Schema::hasTable('branch_item')) {
            // Step 1: Drop any foreign keys that reference branch_item from other tables
            try {
                $dbName = DB::connection()->getDatabaseName();
                $foreignKeys = DB::select("
                    SELECT TABLE_NAME, CONSTRAINT_NAME
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = ?
                    AND REFERENCED_TABLE_NAME = 'branch_item'
                ", [$dbName]);

                foreach ($foreignKeys as $fk) {
                    try {
                        // Drop foreign key by constraint name from the table
                        DB::statement("ALTER TABLE `{$fk->TABLE_NAME}` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                    } catch (\Exception $e) {
                        // Try alternative method using Schema
                        try {
                            Schema::table($fk->TABLE_NAME, function (Blueprint $table) use ($fk) {
                                $table->dropForeign($fk->CONSTRAINT_NAME);
                            });
                        } catch (\Exception $ex) {
                            // Log but continue - foreign key might not exist
                            \Log::warning("Could not drop foreign key: {$fk->CONSTRAINT_NAME} from {$fk->TABLE_NAME}", ['error' => $ex->getMessage()]);
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

            // Step 2: Drop all foreign keys from branch_item itself first
            try {
                $dbName = DB::connection()->getDatabaseName();
                $branchItemForeignKeys = DB::select("
                    SELECT CONSTRAINT_NAME
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = ?
                    AND TABLE_NAME = 'branch_item'
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                ", [$dbName]);

                foreach ($branchItemForeignKeys as $fk) {
                    try {
                        DB::statement("ALTER TABLE `branch_item` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                    } catch (\Exception $e) {
                        // Ignore if doesn't exist
                    }
                }
            } catch (\Exception $e) {
                // Try to drop common foreign key names from branch_item
                $commonBranchItemFkNames = [
                    'branch_item_branch_id_foreign',
                    'branch_item_branch_id_branches_id_foreign',
                ];

                foreach ($commonBranchItemFkNames as $fkName) {
                    try {
                        DB::statement("ALTER TABLE `branch_item` DROP FOREIGN KEY `{$fkName}`");
                    } catch (\Exception $ex) {
                        // Ignore if doesn't exist
                    }
                }
            }

            // Step 3: Now we can safely drop the old branch_item table
            Schema::dropIfExists('branch_item');
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
