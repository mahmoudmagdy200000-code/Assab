<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds new delay-related statuses:
     * - delayed_branch: When branch requests delay
     * - delayed_supplier: When supplier requests delay
     * - delayed_approved: When branch approves delay request
     * - cancelled_delayed: When branch rejects delay request
     *
     * Keeps 'delayed' for backward compatibility
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        // SQLite doesn't support MODIFY COLUMN or ENUM
        if ($driver === 'sqlite') {
            return;
        }

        try {
            // Add new delay-related statuses to the enum
            DB::statement("ALTER TABLE purchase_order_items MODIFY COLUMN status ENUM(
                'pending',
                'confirmed',
                'partial',
                'partial_confirmation',
                'confirmed_need_time',
                'confirmed_alternative_product',
                'rejected',
                'needs_approval',
                'needs_approval_supplier',
                'needs_approval_branch',
                'preparing',
                'on_the_way',
                'delivered',
                'delayed',
                'delayed_branch',
                'delayed_supplier',
                'delayed_approved',
                'cancelled',
                'cancelled_by_branch',
                'cancelled_by_supplier',
                'cancelled_modification',
                'cancelled_delayed',
                'received',
                'variance'
            ) DEFAULT 'pending'");
        } catch (\Exception $e) {
            // If enum modification fails, log and continue
            Log::warning('Migration add_delay_statuses: ' . $e->getMessage());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        // SQLite doesn't support MODIFY COLUMN or ENUM
        if ($driver === 'sqlite') {
            return;
        }

        try {
            // Convert new statuses back to 'delayed' before removing from enum
            DB::table('purchase_order_items')
                ->whereIn('status', ['delayed_branch', 'delayed_supplier', 'delayed_approved'])
                ->update(['status' => 'delayed']);

            DB::table('purchase_order_items')
                ->where('status', 'cancelled_delayed')
                ->update(['status' => 'cancelled']);

            // Remove new statuses from enum
            DB::statement("ALTER TABLE purchase_order_items MODIFY COLUMN status ENUM(
                'pending',
                'confirmed',
                'partial',
                'partial_confirmation',
                'confirmed_need_time',
                'confirmed_alternative_product',
                'rejected',
                'needs_approval',
                'needs_approval_supplier',
                'needs_approval_branch',
                'preparing',
                'on_the_way',
                'delivered',
                'delayed',
                'cancelled',
                'cancelled_by_branch',
                'cancelled_by_supplier',
                'cancelled_modification',
                'received',
                'variance'
            ) DEFAULT 'pending'");
        } catch (\Exception $e) {
            Log::warning('Migration rollback add_delay_statuses: ' . $e->getMessage());
        }
    }
};
