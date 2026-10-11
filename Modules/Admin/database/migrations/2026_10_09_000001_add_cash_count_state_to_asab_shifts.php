<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S1-10: lets the Dashboard tell a real physical count from the legacy mobile projection.
 *
 * Additive and nullable (no backfill): NULL = not set by the mobile bridge (for example a
 * dashboard-native close). `cash_count_state` is 'counted' | 'unknown'; `pending_incoming_counted`
 * is integer halalas, set only with a stored count.
 *
 * MySQL note: nullable column additions are INSTANT on 8.0.29+; older servers copy `asab_shifts`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_shifts', function (Blueprint $t) {
            $t->string('cash_count_state', 16)->nullable();
            $t->unsignedBigInteger('pending_incoming_counted')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('asab_shifts', function (Blueprint $t) {
            $t->dropColumn(['cash_count_state', 'pending_incoming_counted']);
        });
    }
};
