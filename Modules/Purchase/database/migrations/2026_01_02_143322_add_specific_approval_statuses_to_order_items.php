<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        
        // SQLite doesn't support MODIFY COLUMN or ENUM
        if ($driver === 'sqlite') {
            return;
        }

        // Add new specific approval statuses to purchase_order_items status enum
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

        // Convert new specific approval statuses back to generic needs_approval
        DB::table('purchase_order_items')
            ->where('status', 'needs_time_change_approval')
            ->update(['status' => 'needs_approval']);

        DB::table('purchase_order_items')
            ->where('status', 'needs_alternative_product_approval')
            ->update(['status' => 'needs_approval']);

        DB::table('purchase_order_items')
            ->where('status', 'needs_partial_approval')
            ->update(['status' => 'partial_confirmation']);

        // Revert to previous enum values (without the new specific statuses)
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
    }
};

