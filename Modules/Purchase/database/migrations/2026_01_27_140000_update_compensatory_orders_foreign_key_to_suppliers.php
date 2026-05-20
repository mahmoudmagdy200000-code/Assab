<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Update compensatory_orders foreign key to reference new suppliers table
     */
    public function up(): void
    {
        // Update compensatory_orders table foreign key
        if (Schema::hasTable('compensatory_orders') && Schema::hasColumn('compensatory_orders', 'reorder_supplier_id')) {
            // Drop old foreign key if exists
            // Try to get the actual foreign key name from database
            $foreignKeyName = null;
            if (DB::getDriverName() === 'mysql') {
                $foreignKeys = DB::select("
                    SELECT CONSTRAINT_NAME 
                    FROM information_schema.KEY_COLUMN_USAGE 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 'compensatory_orders' 
                    AND COLUMN_NAME = 'reorder_supplier_id' 
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                ");

                if (! empty($foreignKeys)) {
                    $foreignKeyName = $foreignKeys[0]->CONSTRAINT_NAME;
                }
            }

            if ($foreignKeyName) {
                DB::statement("ALTER TABLE compensatory_orders DROP FOREIGN KEY {$foreignKeyName}");
            } else {
                // Fallback: try standard Laravel approach
                try {
                    Schema::table('compensatory_orders', function (Blueprint $table) {
                        $table->dropForeign(['reorder_supplier_id']);
                    });
                } catch (\Exception $e) {
                    // Foreign key might not exist, continue anyway
                }
            }

            // Add new foreign key to suppliers table
            Schema::table('compensatory_orders', function (Blueprint $table) {
                $table->foreign('reorder_supplier_id')
                    ->references('id')
                    ->on('suppliers')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert foreign key back to purchase_suppliers table
        if (Schema::hasTable('purchase_suppliers')) {
            if (Schema::hasTable('compensatory_orders') && Schema::hasColumn('compensatory_orders', 'reorder_supplier_id')) {
                // Try to get the actual foreign key name from database
                $foreignKeyName = null;
                if (DB::getDriverName() === 'mysql') {
                    $foreignKeys = DB::select("
                        SELECT CONSTRAINT_NAME 
                        FROM information_schema.KEY_COLUMN_USAGE 
                        WHERE TABLE_SCHEMA = DATABASE() 
                        AND TABLE_NAME = 'compensatory_orders' 
                        AND COLUMN_NAME = 'reorder_supplier_id' 
                        AND REFERENCED_TABLE_NAME IS NOT NULL
                    ");

                    if (! empty($foreignKeys)) {
                        $foreignKeyName = $foreignKeys[0]->CONSTRAINT_NAME;
                    }
                }

                if ($foreignKeyName) {
                    DB::statement("ALTER TABLE compensatory_orders DROP FOREIGN KEY {$foreignKeyName}");
                } else {
                    // Fallback: try standard Laravel approach
                    try {
                        Schema::table('compensatory_orders', function (Blueprint $table) {
                            $table->dropForeign(['reorder_supplier_id']);
                        });
                    } catch (\Exception $e) {
                        // Foreign key might not exist, continue anyway
                    }
                }

                Schema::table('compensatory_orders', function (Blueprint $table) {
                    $table->foreign('reorder_supplier_id')
                        ->references('id')
                        ->on('purchase_suppliers')
                        ->nullOnDelete();
                });
            }
        }
    }
};
