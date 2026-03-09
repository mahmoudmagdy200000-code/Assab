<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Stores notification_options and smart_settings from recurring order for downstream use (FR-004, FR-005).
     */
    public function up(): void
    {
        if (Schema::hasColumn('purchase_orders', 'recurring_metadata')) {
            return;
        }

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->json('recurring_metadata')->nullable()->after('recurring_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('recurring_metadata');
        });
    }
};
