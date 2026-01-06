<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
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
        if ($driver !== 'sqlite') {
            // Add 'delivered' and 'delayed' status to the enum
            DB::statement("ALTER TABLE purchase_order_items MODIFY COLUMN status ENUM('pending', 'confirmed', 'preparing', 'delivered', 'delayed', 'partial', 'rejected', 'received', 'variance', 'needs_approval', 'needs_approval_supplier', 'needs_approval_branch', 'partial_confirmation', 'confirmed_need_time', 'confirmed_alternative_product', 'cancelled', 'cancelled_by_branch', 'cancelled_by_supplier', 'canceled_modification') DEFAULT 'pending'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: We can't easily remove enum values, so we'll leave it
        // If needed, items with 'delivered' or 'delayed' status should be migrated first
    }
};

