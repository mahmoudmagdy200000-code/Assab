<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meeting 2026-08-03 «تحويل من صاحب المطعم للمدير — لا يوجد استلام»: an owner
 * transfer landed as a Pending custody request and stopped there. Nothing
 * recorded the manager taking delivery, so no CustodyTransaction was ever
 * written and the branch custody balance stayed 0.00. These columns carry the
 * receipt step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custody_requests', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable()->after('viewed_at');
            $table->uuid('received_by')->nullable()->after('received_at');
        });

        Schema::table('custody_request_timeline', function (Blueprint $table) {
            // stage/status shipped as four-value enums, so the receipt step
            // («Receive Case» / «Received») was rejected by the CHECK
            // constraint. Same call as 2026_07_30_000003 on the cashier custody
            // types: a lifecycle that keeps growing does not belong in an enum.
            $table->string('stage', 50)->change();
            $table->string('status', 50)->change();
        });
    }

    public function down(): void
    {
        Schema::table('custody_requests', function (Blueprint $table) {
            $table->dropColumn(['received_at', 'received_by']);
        });
        // stage/status stay strings: narrowing back would reject every
        // «Receive Case» row written since.
    }
};
