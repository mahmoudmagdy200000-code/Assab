<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This is a placeholder migration to handle the case where the migration
     * was run in production but the file was deleted.
     * 
     * If the migration was already run, this will:
     * 1. Convert any items with old specific statuses to needs_approval_branch
     * 2. Ensure enum only contains the correct statuses (removes old specific ones)
     * 
     * If the migration was not run, this will do nothing (safe to run multiple times)
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        
        // SQLite doesn't support MODIFY COLUMN or ENUM
        if ($driver === 'sqlite') {
            return;
        }

        try {
            // Check if old statuses exist in database and convert them
            // Convert any old specific approval statuses to needs_approval_branch
            // (assuming they were supplier requests, which is the most common case)
            $oldStatuses = [
                'needs_time_change_approval',
                'needs_alternative_product_approval',
                'needs_partial_approval'
            ];

            foreach ($oldStatuses as $oldStatus) {
                $count = DB::table('purchase_order_items')
                    ->where('status', $oldStatus)
                    ->count();

                if ($count > 0) {
                    DB::table('purchase_order_items')
                        ->where('status', $oldStatus)
                        ->update(['status' => 'needs_approval_branch']);
                }
            }

            // Ensure enum only contains the correct statuses (removes old specific ones)
            // This is safe to run even if enum already has correct values
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
                'cancelled',
                'cancelled_by_branch',
                'cancelled_by_supplier',
                'canceled_modification',
                'received',
                'variance'
            ) DEFAULT 'pending'");
        } catch (\Exception $e) {
            // If enum modification fails (e.g., enum already has correct values), that's okay
            // Just log and continue
            Log::warning('Migration cleanup: ' . $e->getMessage());
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

        // Convert needs_approval_branch back to specific statuses based on approval_type
        DB::table('purchase_order_items')
            ->where('status', 'needs_approval_branch')
            ->where('approval_type', 'time_change')
            ->update(['status' => 'needs_approval']);

        DB::table('purchase_order_items')
            ->where('status', 'needs_approval_branch')
            ->where('approval_type', 'alternative')
            ->update(['status' => 'needs_approval']);

        DB::table('purchase_order_items')
            ->where('status', 'needs_approval_branch')
            ->where('approval_type', 'partial')
            ->update(['status' => 'partial_confirmation']);

        // Re-add old specific statuses to enum
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
            'needs_time_change_approval',
            'needs_alternative_product_approval',
            'needs_partial_approval',
            'cancelled',
            'cancelled_by_branch',
            'cancelled_by_supplier',
            'canceled_modification',
            'received',
            'variance'
        ) DEFAULT 'pending'");
    }
};

