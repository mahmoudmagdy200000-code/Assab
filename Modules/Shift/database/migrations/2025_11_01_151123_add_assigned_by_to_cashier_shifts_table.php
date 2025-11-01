<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_by')->nullable()->after('opening_balance');

            // لو عندك جدول users أو branch_managers:
            $table->foreign('assigned_by')->references('id')->on('branch_managers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->dropForeign(['assigned_by']);
            $table->dropColumn('assigned_by');
        });
    }
};
