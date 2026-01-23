<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds 'closed' status to purchase_order_items status enum
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        // SQLite doesn't support MODIFY COLUMN or ENUM
        if ($driver === 'sqlite') {
            return;
        }

        try {
            // Add 'closed' status to the enum
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
                'variance',
                'closed'
            ) DEFAULT 'pending'");
        } catch (\Exception $e) {
            // If enum modification fails, log and continue
            Log::warning('Migration add_closed_status: ' . $e->getMessage());
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
            // Convert 'closed' status back to 'received' before removing from enum
            DB::table('purchase_order_items')
                ->where('status', 'closed')
                ->update(['status' => 'received']);

            // Remove 'closed' from enum
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
            Log::warning('Migration rollback add_closed_status: ' . $e->getMessage());
        }
    }
};
