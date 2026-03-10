<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace "Cash Collected" with "Total Sales" in cashier custody transactions.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE cashier_custody_transactions MODIFY transaction_type ENUM('Handover Received', 'Handover Sent', 'Variance', 'Cash Collected', 'Total Sales') NOT NULL");
            DB::table('cashier_custody_transactions')->where('transaction_type', 'Cash Collected')->update(['transaction_type' => 'Total Sales']);
            DB::statement("ALTER TABLE cashier_custody_transactions MODIFY transaction_type ENUM('Handover Received', 'Handover Sent', 'Variance', 'Total Sales') NOT NULL");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE cashier_custody_transactions MODIFY transaction_type ENUM('Handover Received', 'Handover Sent', 'Variance', 'Cash Collected', 'Total Sales') NOT NULL");
            DB::table('cashier_custody_transactions')->where('transaction_type', 'Total Sales')->update(['transaction_type' => 'Cash Collected']);
            DB::statement("ALTER TABLE cashier_custody_transactions MODIFY transaction_type ENUM('Handover Received', 'Handover Sent', 'Variance', 'Cash Collected') NOT NULL");
        }
    }
};
