<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop foreign key and index first (if they exist)
        if (Schema::hasColumn('branches', 'branch_manager_id')) {
            try {
                // Try to drop foreign key by column name
                Schema::table('branches', function (Blueprint $table) {
                    $table->dropForeign(['branch_manager_id']);
                });
            } catch (\Exception $e) {
                // Foreign key might not exist, try to find and drop it
                try {
                    $foreignKeys = DB::select("
                        SELECT CONSTRAINT_NAME 
                        FROM information_schema.KEY_COLUMN_USAGE 
                        WHERE TABLE_SCHEMA = DATABASE() 
                        AND TABLE_NAME = 'branches' 
                        AND COLUMN_NAME = 'branch_manager_id' 
                        AND REFERENCED_TABLE_NAME IS NOT NULL
                    ");
                    
                    if (!empty($foreignKeys)) {
                        $constraintName = $foreignKeys[0]->CONSTRAINT_NAME;
                        Schema::table('branches', function (Blueprint $table) use ($constraintName) {
                            $table->dropForeign([$constraintName]);
                        });
                    }
                } catch (\Exception $e2) {
                    // Ignore if foreign key doesn't exist
                }
            }
            
            // Drop index if exists
            try {
                Schema::table('branches', function (Blueprint $table) {
                    $table->dropIndex(['branch_manager_id']);
                });
            } catch (\Exception $e) {
                // Index might not exist, ignore
            }
        }

        // Drop columns if they exist
        Schema::table('branches', function (Blueprint $table) {
            $columnsToDrop = [];
            
            if (Schema::hasColumn('branches', 'branch_manager_id')) {
                $columnsToDrop[] = 'branch_manager_id';
            }
            
            if (Schema::hasColumn('branches', 'branch_manager_image')) {
                $columnsToDrop[] = 'branch_manager_image';
            }
            
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            // Restore columns
            if (!Schema::hasColumn('branches', 'branch_manager_id')) {
                $table->uuid('branch_manager_id')->nullable()->after('image');
            }
            
            if (!Schema::hasColumn('branches', 'branch_manager_image')) {
                $table->string('branch_manager_image')->nullable()->after('branch_manager_id');
            }
        });
    }
};
