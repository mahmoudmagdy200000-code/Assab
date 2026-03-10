<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE cashier_custody_transactions MODIFY transaction_type ENUM('Handover Received', 'Handover Sent', 'Variance', 'Cash Collected') NOT NULL");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::table('cashier_custody_transactions')->where('transaction_type', 'Cash Collected')->delete();
            DB::statement("ALTER TABLE cashier_custody_transactions MODIFY transaction_type ENUM('Handover Received', 'Handover Sent', 'Variance') NOT NULL");
        }
    }
};
