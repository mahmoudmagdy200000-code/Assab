<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_custody_transactions', function (Blueprint $table) {
            // The enum only allowed ('Handover Received', 'Handover Sent') but
            // CashierCustodyService writes 'Total Sales' and the variance
            // listener writes 'Variance' — those inserts failed on both MySQL
            // strict mode and the SQLite test schema. Plain string lifts it.
            $table->string('transaction_type', 50)->change();
        });
    }

    public function down(): void
    {
        // Intentionally irreversible: narrowing back to the two-value enum
        // would reject the 'Total Sales'/'Variance' rows written since.
    }
};
