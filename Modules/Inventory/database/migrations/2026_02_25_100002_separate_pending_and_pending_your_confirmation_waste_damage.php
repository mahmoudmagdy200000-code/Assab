<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Split: existing 'pending' with submitted_at set = waiting approval → pending_your_confirmation.
     */
    public function up(): void
    {
        DB::table('waste_damage_reports')
            ->where('status', 'pending')
            ->whereNotNull('submitted_at')
            ->update(['status' => 'pending_your_confirmation']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('waste_damage_reports')
            ->where('status', 'pending_your_confirmation')
            ->update(['status' => 'pending']);
    }
};
