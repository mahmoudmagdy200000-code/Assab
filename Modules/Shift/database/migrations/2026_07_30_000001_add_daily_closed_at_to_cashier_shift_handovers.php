<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_shift_handovers', function (Blueprint $table) {
            // Stamped when a branch-manager daily close (daily report submit)
            // sweeps this handover in; NULL = approved cash not yet reviewed in
            // any daily close, so it carries over to the next close (7-day window).
            $table->timestamp('daily_closed_at')->nullable()->index()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('cashier_shift_handovers', function (Blueprint $table) {
            $table->dropIndex(['daily_closed_at']);
            $table->dropColumn('daily_closed_at');
        });
    }
};
