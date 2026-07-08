<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            // Dashboard (ASAB) decision audit: which asab user decided this order
            // and through which surface. Nullable — mobile-side decisions leave it empty.
            $table->uuid('decided_by_asab_user_id')->nullable()->after('rejection_reason');
            $table->timestamp('decided_at')->nullable()->after('decided_by_asab_user_id');
            $table->string('decision_source', 32)->nullable()->after('decided_at');

            $table->index(['decided_by_asab_user_id', 'status'], 'po_decided_by_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex('po_decided_by_status_idx');
            $table->dropColumn(['decided_by_asab_user_id', 'decided_at', 'decision_source']);
        });
    }
};
