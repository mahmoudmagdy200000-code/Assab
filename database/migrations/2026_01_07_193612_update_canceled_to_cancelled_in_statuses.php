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

        try {
            // Step 1: First, update ENUM definitions to include both 'canceled' and 'cancelled' (temporarily)
            // This allows us to update the data without errors

            // Update purchase_orders.status ENUM - add 'cancelled' while keeping 'canceled'
            DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('draft', 'pending', 'pending_confirmation', 'pending_approval', 'partial_confirmation', 'confirmed', 'preparing', 'on_the_way', 'delivered', 'closed', 'canceled', 'cancelled', 'cancelled_by_branch', 'cancelled_by_supplier', 'rejected', 'delayed', 'fully_approved', 'partial_approved', 'partial_confirmed') DEFAULT 'draft'");

            // Update purchase_order_items.status ENUM - add 'cancelled' while keeping 'canceled_modification'
            DB::statement("ALTER TABLE purchase_order_items MODIFY COLUMN status ENUM('pending', 'confirmed', 'preparing', 'on_the_way', 'delivered', 'delayed', 'partial', 'rejected', 'received', 'variance', 'needs_approval', 'needs_approval_supplier', 'needs_approval_branch', 'partial_confirmation', 'confirmed_need_time', 'confirmed_alternative_product', 'canceled', 'cancelled', 'cancelled_by_branch', 'cancelled_by_supplier', 'canceled_modification', 'cancelled_modification') DEFAULT 'pending'");

            // Update compensatory_orders.status ENUM - add 'cancelled' while keeping 'canceled'
            DB::statement("ALTER TABLE compensatory_orders MODIFY COLUMN status ENUM('pending', 'ordered', 'confirmed', 'delivered', 'completed', 'canceled', 'cancelled') DEFAULT 'pending'");

            // Step 2: Now update existing data: 'canceled' -> 'cancelled'
            // Only update if there are records with 'canceled' status
            $purchaseOrdersCount = DB::table('purchase_orders')
                ->where('status', 'canceled')
                ->count();

            if ($purchaseOrdersCount > 0) {
                DB::table('purchase_orders')
                    ->where('status', 'canceled')
                    ->update(['status' => 'cancelled']);
            }

            $purchaseOrderItemsCount = DB::table('purchase_order_items')
                ->where('status', 'canceled_modification')
                ->count();

            if ($purchaseOrderItemsCount > 0) {
                DB::table('purchase_order_items')
                    ->where('status', 'canceled_modification')
                    ->update(['status' => 'cancelled_modification']);
            }

            $compensatoryOrdersCount = DB::table('compensatory_orders')
                ->where('status', 'canceled')
                ->count();

            if ($compensatoryOrdersCount > 0) {
                DB::table('compensatory_orders')
                    ->where('status', 'canceled')
                    ->update(['status' => 'cancelled']);
            }

            // Step 3: Finally, remove 'canceled' from ENUM (keep only 'cancelled')
            // Update purchase_orders.status ENUM - remove 'canceled'
            DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('draft', 'pending', 'pending_confirmation', 'pending_approval', 'partial_confirmation', 'confirmed', 'preparing', 'on_the_way', 'delivered', 'closed', 'cancelled', 'cancelled_by_branch', 'cancelled_by_supplier', 'rejected', 'delayed', 'fully_approved', 'partial_approved', 'partial_confirmed') DEFAULT 'draft'");

            // Update purchase_order_items.status ENUM - remove 'canceled' and 'canceled_modification'
            DB::statement("ALTER TABLE purchase_order_items MODIFY COLUMN status ENUM('pending', 'confirmed', 'preparing', 'on_the_way', 'delivered', 'delayed', 'partial', 'rejected', 'received', 'variance', 'needs_approval', 'needs_approval_supplier', 'needs_approval_branch', 'partial_confirmation', 'confirmed_need_time', 'confirmed_alternative_product', 'cancelled', 'cancelled_by_branch', 'cancelled_by_supplier', 'cancelled_modification') DEFAULT 'pending'");

            // Update compensatory_orders.status ENUM - remove 'canceled'
            DB::statement("ALTER TABLE compensatory_orders MODIFY COLUMN status ENUM('pending', 'ordered', 'confirmed', 'delivered', 'completed', 'cancelled') DEFAULT 'pending'");
        } catch (\Exception $e) {
            // If migration fails, log the error but don't stop
            \Log::warning('Migration update_canceled_to_cancelled failed: '.$e->getMessage());
            throw $e;
        }
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
