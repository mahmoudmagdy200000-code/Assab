<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Rename status 'submitted' -> 'pending' (pending your confirmation). New status 'completed' is already allowed (string column).
     */
    public function up(): void
    {
        DB::table('waste_damage_reports')
            ->where('status', 'submitted')
            ->update(['status' => 'pending']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('waste_damage_reports')
            ->where('status', 'pending')
            ->update(['status' => 'submitted']);
    }
};
