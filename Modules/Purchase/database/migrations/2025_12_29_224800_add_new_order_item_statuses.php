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

        // Add new statuses to purchase_order_items status enum
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

        // Convert new statuses back to existing ones
        DB::table('purchase_order_items')
            ->whereIn('status', ['confirmed_need_time', 'confirmed_alternative_product'])
            ->update(['status' => 'confirmed']);

        DB::table('purchase_order_items')
            ->whereIn('status', ['needs_approval_supplier', 'needs_approval_branch'])
            ->update(['status' => 'needs_approval']);

        DB::table('purchase_order_items')
            ->where('status', 'canceled_modification')
            ->update(['status' => 'cancelled']);

        // Revert to previous enum values
        DB::statement("ALTER TABLE purchase_order_items MODIFY COLUMN status ENUM(
            'pending',
            'confirmed',
            'partial',
            'partial_confirmation',
            'rejected',
            'cancelled',
            'cancelled_by_branch',
            'cancelled_by_supplier',
            'received',
            'variance',
            'needs_approval'
        ) DEFAULT 'pending'");
    }
};
