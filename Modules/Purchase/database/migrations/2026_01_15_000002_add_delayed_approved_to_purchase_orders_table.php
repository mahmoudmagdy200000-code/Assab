<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds delayed_approved status to purchase_orders table
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        // SQLite doesn't support MODIFY COLUMN or ENUM
        if ($driver === 'sqlite') {
            return;
        }

        try {
            // Add delayed_approved to purchase_orders.status ENUM
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
                'cancelled',
                'cancelled_by_branch',
                'cancelled_by_supplier',
                'rejected',
                'delayed',
                'delayed_approved',
                'fully_approved',
                'partial_approved',
                'partial_confirmed'
            ) DEFAULT 'draft'");
        } catch (\Exception $e) {
            // If enum modification fails, log and continue
            Log::warning('Migration add_delayed_approved_to_purchase_orders: '.$e->getMessage());
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
            // Convert delayed_approved back to delayed before removing from enum
            DB::table('purchase_orders')
                ->where('status', 'delayed_approved')
                ->update(['status' => 'delayed']);

            // Remove delayed_approved from enum
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
                'cancelled',
                'cancelled_by_branch',
                'cancelled_by_supplier',
                'rejected',
                'delayed',
                'fully_approved',
                'partial_approved',
                'partial_confirmed'
            ) DEFAULT 'draft'");
        } catch (\Exception $e) {
            Log::warning('Migration rollback add_delayed_approved_to_purchase_orders: '.$e->getMessage());
        }
    }
};
