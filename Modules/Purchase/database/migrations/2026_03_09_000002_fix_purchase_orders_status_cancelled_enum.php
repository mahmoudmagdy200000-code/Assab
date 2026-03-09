<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Ensures purchase_orders.status ENUM uses 'cancelled' (matching OrderStatus::CANCELED->value).
     * Later migrations (variance, delayed, emergency) redefined the ENUM with 'canceled', causing
     * "Data truncated for column 'status'" when saving OrderStatus::CANCELED.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        try {
            // Step 1: Add 'cancelled' to ENUM (keep 'canceled' so existing data is valid)
            DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM(
                'draft',
                'pending',
                'emergency',
                'pending_confirmation',
                'pending_approval',
                'partial_confirmation',
                'confirmed',
                'preparing',
                'on_the_way',
                'delivered',
                'closed',
                'canceled',
                'cancelled',
                'cancelled_by_branch',
                'cancelled_by_supplier',
                'rejected',
                'delayed',
                'delayed_approved',
                'delayed_confirmed',
                'delayed_canceled',
                'fully_approved',
                'partial_approved',
                'partial_confirmed',
                'variance'
            ) DEFAULT 'draft'");

            // Step 2: Normalize data: 'canceled' -> 'cancelled'
            DB::table('purchase_orders')->where('status', 'canceled')->update(['status' => 'cancelled']);

            // Step 3: Remove 'canceled' from ENUM so only 'cancelled' remains (matches OrderStatus::CANCELED->value)
            DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM(
                'draft',
                'pending',
                'emergency',
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
                'delayed_confirmed',
                'delayed_canceled',
                'fully_approved',
                'partial_approved',
                'partial_confirmed',
                'variance'
            ) DEFAULT 'draft'");
        } catch (\Exception $e) {
            Log::warning('Migration fix_purchase_orders_status_cancelled_enum: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        try {
            // Revert to ENUM with 'canceled' for backward compatibility with older code
            DB::table('purchase_orders')->where('status', 'cancelled')->update(['status' => 'canceled']);

            DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM(
                'draft',
                'pending',
                'emergency',
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
                'delayed_confirmed',
                'delayed_canceled',
                'fully_approved',
                'partial_approved',
                'partial_confirmed',
                'variance'
            ) DEFAULT 'draft'");
        } catch (\Exception $e) {
            Log::warning('Migration rollback fix_purchase_orders_status_cancelled_enum: ' . $e->getMessage());
            throw $e;
        }
    }
};
