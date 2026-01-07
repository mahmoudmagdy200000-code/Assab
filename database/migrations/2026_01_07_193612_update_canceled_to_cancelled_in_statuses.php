<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Update 'canceled' to 'cancelled' in all status columns
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        
        // SQLite doesn't support MODIFY COLUMN or ENUM
        if ($driver === 'sqlite') {
            return;
        }

        // Update existing data: 'canceled' -> 'cancelled'
        DB::table('purchase_orders')
            ->where('status', 'canceled')
            ->update(['status' => 'cancelled']);

        DB::table('purchase_order_items')
            ->where('status', 'canceled_modification')
            ->update(['status' => 'cancelled_modification']);

        DB::table('compensatory_orders')
            ->where('status', 'canceled')
            ->update(['status' => 'cancelled']);

        // Update ENUM definitions to use 'cancelled' instead of 'canceled'
        
        // Update purchase_orders.status ENUM
        DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('draft', 'pending', 'pending_confirmation', 'pending_approval', 'partial_confirmation', 'confirmed', 'preparing', 'on_the_way', 'delivered', 'closed', 'cancelled', 'cancelled_by_branch', 'cancelled_by_supplier', 'rejected', 'delayed', 'fully_approved', 'partial_approved', 'partial_confirmed') DEFAULT 'draft'");

        // Update purchase_order_items.status ENUM
        DB::statement("ALTER TABLE purchase_order_items MODIFY COLUMN status ENUM('pending', 'confirmed', 'preparing', 'on_the_way', 'delivered', 'delayed', 'partial', 'rejected', 'received', 'variance', 'needs_approval', 'needs_approval_supplier', 'needs_approval_branch', 'partial_confirmation', 'confirmed_need_time', 'confirmed_alternative_product', 'cancelled', 'cancelled_by_branch', 'cancelled_by_supplier', 'cancelled_modification') DEFAULT 'pending'");

        // Update compensatory_orders.status ENUM
        DB::statement("ALTER TABLE compensatory_orders MODIFY COLUMN status ENUM('pending', 'ordered', 'confirmed', 'delivered', 'completed', 'cancelled') DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     * Revert 'cancelled' back to 'canceled'
     */
    public function down(): void
    {
        $driver = DB::getDriverName();
        
        // SQLite doesn't support MODIFY COLUMN or ENUM
        if ($driver === 'sqlite') {
            return;
        }

        // Revert existing data: 'cancelled' -> 'canceled'
        DB::table('purchase_orders')
            ->where('status', 'cancelled')
            ->update(['status' => 'canceled']);

        DB::table('purchase_order_items')
            ->where('status', 'cancelled_modification')
            ->update(['status' => 'canceled_modification']);

        DB::table('compensatory_orders')
            ->where('status', 'cancelled')
            ->update(['status' => 'canceled']);

        // Revert ENUM definitions to use 'canceled' instead of 'cancelled'
        
        // Revert purchase_orders.status ENUM
        DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('draft', 'pending', 'pending_confirmation', 'pending_approval', 'partial_confirmation', 'confirmed', 'preparing', 'on_the_way', 'delivered', 'closed', 'canceled', 'cancelled_by_branch', 'cancelled_by_supplier', 'rejected', 'delayed', 'fully_approved', 'partial_approved', 'partial_confirmed') DEFAULT 'draft'");

        // Revert purchase_order_items.status ENUM
        DB::statement("ALTER TABLE purchase_order_items MODIFY COLUMN status ENUM('pending', 'confirmed', 'preparing', 'on_the_way', 'delivered', 'delayed', 'partial', 'rejected', 'received', 'variance', 'needs_approval', 'needs_approval_supplier', 'needs_approval_branch', 'partial_confirmation', 'confirmed_need_time', 'confirmed_alternative_product', 'cancelled', 'cancelled_by_branch', 'cancelled_by_supplier', 'canceled_modification') DEFAULT 'pending'");

        // Revert compensatory_orders.status ENUM
        DB::statement("ALTER TABLE compensatory_orders MODIFY COLUMN status ENUM('pending', 'ordered', 'confirmed', 'delivered', 'completed', 'canceled') DEFAULT 'pending'");
    }
};
