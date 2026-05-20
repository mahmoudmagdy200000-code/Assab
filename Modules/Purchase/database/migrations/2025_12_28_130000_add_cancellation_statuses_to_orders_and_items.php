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

        // Add cancellation statuses to purchase_order_items
        DB::statement("ALTER TABLE purchase_order_items MODIFY COLUMN status ENUM('pending', 'confirmed', 'partial', 'partial_confirmation', 'rejected', 'cancelled', 'cancelled_by_branch', 'cancelled_by_supplier', 'received', 'variance', 'needs_approval') DEFAULT 'pending'");

        // Add cancellation statuses to purchase_orders
        DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('draft', 'pending', 'pending_confirmation', 'pending_approval', 'partial_confirmation', 'confirmed', 'preparing', 'on_the_way', 'delivered', 'closed', 'canceled', 'cancelled_by_branch', 'cancelled_by_supplier', 'rejected', 'delayed', 'fully_approved', 'partial_approved', 'partial_confirmed') DEFAULT 'draft'");
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

        // Convert cancelled_by_branch and cancelled_by_supplier back to cancelled
        DB::table('purchase_order_items')
            ->whereIn('status', ['cancelled_by_branch', 'cancelled_by_supplier'])
            ->update(['status' => 'cancelled']);

        DB::table('purchase_orders')
            ->whereIn('status', ['cancelled_by_branch', 'cancelled_by_supplier'])
            ->update(['status' => 'canceled']);

        // Remove cancellation statuses from purchase_order_items
        DB::statement("ALTER TABLE purchase_order_items MODIFY COLUMN status ENUM('pending', 'confirmed', 'partial', 'partial_confirmation', 'rejected', 'received', 'variance', 'needs_approval') DEFAULT 'pending'");

        // Remove cancellation statuses from purchase_orders
        DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('draft', 'pending', 'pending_confirmation', 'pending_approval', 'partial_confirmation', 'confirmed', 'preparing', 'on_the_way', 'delivered', 'closed', 'canceled', 'rejected', 'delayed', 'fully_approved', 'partial_approved', 'partial_confirmed') DEFAULT 'draft'");
    }
};
