<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_ledger_transactions', function (Blueprint $table) {
            // The enum ('Total Sales', 'Handover to Brand Owner', 'Transfer to
            // Custody') silently rejected the types written later ('Variance
            // from Cashier', and now 'Expenses Deduction') — a plain string
            // lifts the constraint on both MySQL and the SQLite test schema.
            $table->string('transaction_type', 50)->change();

            // Links an 'Expenses Deduction' row to its expense (idempotency +
            // traceability, mirrors custody_transactions.related_expense_id).
            $table->uuid('related_expense_id')->nullable()->index()->after('related_handover_id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_ledger_transactions', function (Blueprint $table) {
            $table->dropIndex(['related_expense_id']);
            $table->dropColumn('related_expense_id');
        });
    }
};
