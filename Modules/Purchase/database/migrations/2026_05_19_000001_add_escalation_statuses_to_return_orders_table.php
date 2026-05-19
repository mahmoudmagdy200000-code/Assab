<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        try {
            DB::statement("ALTER TABLE return_orders MODIFY COLUMN status ENUM(
                'draft',
                'pending',
                'approved',
                'rejected',
                'escalated',
                'escalated_resolved',
                'escalated_rejected',
                'closed',
                'resolved'
            ) DEFAULT 'draft'");

            DB::statement("ALTER TABLE return_orders MODIFY COLUMN resolution_type ENUM(
                'replacement',
                'refund',
                'escalation_approved',
                'escalation_rejected'
            ) NULL");
        } catch (\Exception $e) {
            Log::warning('Migration add_escalation_statuses_to_return_orders_table: ' . $e->getMessage());
            throw $e;
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        try {
            DB::table('return_orders')
                ->whereIn('status', ['escalated_resolved', 'escalated_rejected'])
                ->update(['status' => 'escalated']);

            DB::table('return_orders')
                ->whereIn('resolution_type', ['escalation_approved', 'escalation_rejected'])
                ->update(['resolution_type' => null]);

            DB::statement("ALTER TABLE return_orders MODIFY COLUMN status ENUM(
                'draft',
                'pending',
                'approved',
                'rejected',
                'escalated',
                'closed',
                'resolved'
            ) DEFAULT 'draft'");

            DB::statement("ALTER TABLE return_orders MODIFY COLUMN resolution_type ENUM(
                'replacement',
                'refund'
            ) NULL");
        } catch (\Exception $e) {
            Log::warning('Migration rollback add_escalation_statuses_to_return_orders_table: ' . $e->getMessage());
            throw $e;
        }
    }
};
