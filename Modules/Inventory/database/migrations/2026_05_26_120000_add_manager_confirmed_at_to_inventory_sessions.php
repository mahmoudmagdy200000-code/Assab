<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_sessions', function (Blueprint $table) {
            $table->timestamp('manager_confirmed_at')->nullable()->after('approved_by');
        });

        // Existing staff-submitted rows kept in PENDING_YOUR_CONFIRMATION are migrated
        // back to PENDING; submitted_at preserved so isStaffInventored stays true.
        // manager_confirmed_at stays null (Branch Manager has not confirmed yet).
        DB::table('inventory_sessions')
            ->where('status', 'pending_your_confirmation')
            ->update(['status' => 'pending']);

        DB::table('waste_damage_reports')
            ->where('status', 'pending_your_confirmation')
            ->update(['status' => 'pending']);
    }

    public function down(): void
    {
        Schema::table('inventory_sessions', function (Blueprint $table) {
            $table->dropColumn('manager_confirmed_at');
        });
    }
};
