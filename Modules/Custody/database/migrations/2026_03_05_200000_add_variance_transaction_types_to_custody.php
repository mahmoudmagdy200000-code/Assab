<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add 'Variance' to cashier_custody_transactions and
     * 'Variance from Cashier' to personal_ledger_transactions.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE cashier_custody_transactions MODIFY transaction_type ENUM('Handover Received', 'Handover Sent', 'Variance') NOT NULL");
            DB::statement("ALTER TABLE personal_ledger_transactions MODIFY transaction_type ENUM('Total Sales', 'Handover to Brand Owner', 'Transfer to Custody', 'Variance from Cashier') NOT NULL");
        } else {
            // SQLite / PostgreSQL: no enum, column is string; no change needed for new values
            // If you use enum in PostgreSQL, add a new migration to extend the type
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            // Remove variance entries before reverting enum (optional: delete where type = Variance)
            DB::table('cashier_custody_transactions')->where('transaction_type', 'Variance')->delete();
            DB::table('personal_ledger_transactions')->where('transaction_type', 'Variance from Cashier')->delete();

            DB::statement("ALTER TABLE cashier_custody_transactions MODIFY transaction_type ENUM('Handover Received', 'Handover Sent') NOT NULL");
            DB::statement("ALTER TABLE personal_ledger_transactions MODIFY transaction_type ENUM('Total Sales', 'Handover to Brand Owner', 'Transfer to Custody') NOT NULL");
        }
    }
};
