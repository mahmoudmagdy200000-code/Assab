<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds 'draft' status to purchase_order_items for draft orders.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        try {
            DB::statement("ALTER TABLE purchase_order_items MODIFY COLUMN status ENUM(
                'draft',
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
                'preparing',
                'on_the_way',
                'delivered',
                'delayed',
                'delayed_branch',
                'delayed_supplier',
                'delayed_approved',
                'delayed_confirmed',
                'delayed_canceled',
                'cancelled',
                'cancelled_by_branch',
                'cancelled_by_supplier',
                'cancelled_modification',
                'cancelled_delayed',
                'received',
                'variance',
                'closed'
            ) DEFAULT 'pending'");
        } catch (\Exception $e) {
            Log::warning('Migration add_draft_status_to_order_items: '.$e->getMessage());
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
            DB::table('purchase_order_items')
                ->where('status', 'draft')
                ->update(['status' => 'pending']);

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
                'preparing',
                'on_the_way',
                'delivered',
                'delayed',
                'delayed_branch',
                'delayed_supplier',
                'delayed_approved',
                'delayed_confirmed',
                'delayed_canceled',
                'cancelled',
                'cancelled_by_branch',
                'cancelled_by_supplier',
                'cancelled_modification',
                'cancelled_delayed',
                'received',
                'variance',
                'closed'
            ) DEFAULT 'pending'");
        } catch (\Exception $e) {
            Log::warning('Migration rollback add_draft_status_to_order_items: '.$e->getMessage());
        }
    }
};
