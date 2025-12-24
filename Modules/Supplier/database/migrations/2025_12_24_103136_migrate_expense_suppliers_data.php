<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Migrate data from expense suppliers table to suppliers table
     * 
     * This migration ensures that suppliers from the Expense module (which don't have passwords)
     * are updated with default passwords to work with the new Supplier module structure.
     */
    public function up(): void
    {
            if (!Schema::hasTable('suppliers')) {
                return;
            }

        // Check if suppliers table has password column (new Supplier module table structure)
        $hasPasswordColumn = Schema::hasColumn('suppliers', 'password');
        
        // If the table doesn't have password column, it means it's still the old Expense module structure
        // In this case, we can't migrate properly as the table structure is incompatible
        // The create_suppliers_table migration should have altered the table, but if it didn't,
        // we skip this migration to avoid errors
        if (!$hasPasswordColumn) {
            // Table structure is from old Expense module, cannot migrate
            // This should not happen if migrations run in correct order
            return;
        }

        // Get all suppliers that don't have a password or have empty password
        // These are likely from the Expense module (which didn't require passwords)
        // Purchase module suppliers should already have passwords
        $expenseSuppliers = DB::table('suppliers')
            ->where(function ($query) {
                $query->whereNull('password')
                    ->orWhere('password', '');
            })
            ->get();

        // Update expense suppliers to have a default password
        // This ensures they can be used in the new Supplier module structure
        // Users will need to reset their password on first login
        foreach ($expenseSuppliers as $expenseSupplier) {
            DB::table('suppliers')
                ->where('id', $expenseSupplier->id)
                ->update([
                    'password' => bcrypt('default_password_' . $expenseSupplier->id),
                    'is_first_login' => true,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: This migration should not be reversed as it consolidates data
    }
};
