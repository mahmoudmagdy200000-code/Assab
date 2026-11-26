<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_orders')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->string('status', 64)->default('draft')->change();
            });

            return;
        }

        DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM(
            'draft',
            'pending',
            'emergency',
            'pending_confirmation',
            'pending_approval',
            'partial_confirmation',
            'confirmed',
            'sent_awaiting_response',
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
    }

    public function down(): void
    {
        // Non-destructive: retain existing status values.
    }
};
