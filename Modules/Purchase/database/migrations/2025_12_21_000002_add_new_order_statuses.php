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
        if ($driver === 'sqlite') {
            return;
        }

        // For MySQL, we need to modify the enum values
        // First, change the column to string temporarily
        DB::statement("ALTER TABLE purchase_orders MODIFY status VARCHAR(50) NOT NULL DEFAULT 'draft'");

        // Then update existing values if needed (no changes needed for existing values)
        
        // Finally, change back to enum with new values
        DB::statement("ALTER TABLE purchase_orders MODIFY status ENUM(
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
            'rejected',
            'delayed',
            'fully_approved',
            'partial_approved',
            'partial_confirmed'
        ) NOT NULL DEFAULT 'draft'");
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

        // Change to string first
        DB::statement("ALTER TABLE purchase_orders MODIFY status VARCHAR(50) NOT NULL DEFAULT 'draft'");

        // Update any new statuses back to old ones
        DB::statement("UPDATE purchase_orders SET status = 'confirmed' WHERE status = 'fully_approved'");
        DB::statement("UPDATE purchase_orders SET status = 'partial_confirmation' WHERE status = 'partial_approved'");
        DB::statement("UPDATE purchase_orders SET status = 'partial_confirmation' WHERE status = 'partial_confirmed'");

        // Change back to old enum
        DB::statement("ALTER TABLE purchase_orders MODIFY status ENUM(
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
            'rejected',
            'delayed'
        ) NOT NULL DEFAULT 'draft'");
    }
};

