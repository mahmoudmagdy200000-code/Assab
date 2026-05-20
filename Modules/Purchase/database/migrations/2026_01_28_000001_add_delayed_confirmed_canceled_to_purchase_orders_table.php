<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds delayed_confirmed and delayed_canceled statuses to purchase_orders table.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        try {
            DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM(
                'draft',
                'pending',
                'pending_confirmation',
                'pending_approval',
                'partial_confirmation',
                'confirmed',
                'preparing',
                'on_the_way',
                'delivered',
                'closed',
                'canceled',
                'cancelled_by_branch',
                'cancelled_by_supplier',
                'rejected',
                'delayed',
                'delayed_approved',
                'delayed_confirmed',
                'delayed_canceled',
                'fully_approved',
                'partial_approved',
                'partial_confirmed',
                'variance'
            ) DEFAULT 'draft'");
        } catch (\Exception $e) {
            Log::warning('Migration add_delayed_confirmed_canceled: '.$e->getMessage());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        try {
            DB::table('purchase_orders')
                ->where('status', 'delayed_confirmed')
                ->update(['status' => 'delayed_approved']);

            DB::table('purchase_orders')
                ->where('status', 'delayed_canceled')
                ->update(['status' => 'cancelled_by_branch']);

            DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM(
                'draft',
                'pending',
                'pending_confirmation',
                'pending_approval',
                'partial_confirmation',
                'confirmed',
                'preparing',
                'on_the_way',
                'delivered',
                'closed',
                'canceled',
                'cancelled_by_branch',
                'cancelled_by_supplier',
                'rejected',
                'delayed',
                'delayed_approved',
                'fully_approved',
                'partial_approved',
                'partial_confirmed',
                'variance'
            ) DEFAULT 'draft'");
        } catch (\Exception $e) {
            Log::warning('Migration rollback add_delayed_confirmed_canceled: '.$e->getMessage());
        }
    }
};
