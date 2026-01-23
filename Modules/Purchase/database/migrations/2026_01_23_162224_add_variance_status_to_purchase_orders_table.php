<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds 'variance' status to purchase_orders status enum
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        // SQLite doesn't support MODIFY COLUMN or ENUM
        if ($driver === 'sqlite') {
            return;
        }

        try {
            // Add 'variance' status to the enum
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
            // If enum modification fails, log and continue
            Log::warning('Migration add_variance_status: ' . $e->getMessage());
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
            // Convert 'variance' status back to 'pending' before removing from enum
            DB::table('purchase_orders')
                ->where('status', 'variance')
                ->update(['status' => 'pending']);

            // Remove 'variance' from enum
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
                'partial_confirmed'
            ) DEFAULT 'draft'");
        } catch (\Exception $e) {
            Log::warning('Migration rollback add_variance_status: ' . $e->getMessage());
        }
    }
};
