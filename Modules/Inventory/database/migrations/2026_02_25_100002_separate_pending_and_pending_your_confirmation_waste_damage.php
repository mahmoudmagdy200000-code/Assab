<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Widen status column (pending_your_confirmation = 24 chars), then split pending → pending_your_confirmation where submitted.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        $table = 'waste_damage_reports';

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `{$table}` MODIFY COLUMN `status` VARCHAR(32) NOT NULL DEFAULT 'draft'");
        } else {
            DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN status TYPE VARCHAR(32), ALTER COLUMN status SET DEFAULT 'draft'");
        }

        DB::table($table)
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

        $driver = DB::getDriverName();
        $table = 'waste_damage_reports';
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `{$table}` MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'draft'");
        } else {
            DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN status TYPE VARCHAR(20), ALTER COLUMN status SET DEFAULT 'draft'");
        }
    }
};
