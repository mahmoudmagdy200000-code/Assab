<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T11.8/T11.9 — snapshot the consolidation savings computed at send time and
 * carry an expected-delivery date (ETA) on the batch so the sent-orders screen
 * can show «وفورات» and «موعد التسليم المتوقع» without recomputing. All nullable
 * and non-FK, so sqlite-safe (guarded by hasColumn for repeat runs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_groups', function (Blueprint $t) {
            if (! Schema::hasColumn('purchase_order_groups', 'savings_amount')) {
                $t->decimal('savings_amount', 14, 2)->nullable()->after('sent_at');
            }
            if (! Schema::hasColumn('purchase_order_groups', 'savings_pct')) {
                $t->decimal('savings_pct', 6, 2)->nullable()->after('savings_amount');
            }
            if (! Schema::hasColumn('purchase_order_groups', 'expected_delivery_date')) {
                $t->date('expected_delivery_date')->nullable()->after('savings_pct');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_groups', function (Blueprint $t) {
            foreach (['savings_amount', 'savings_pct', 'expected_delivery_date'] as $col) {
                if (Schema::hasColumn('purchase_order_groups', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
