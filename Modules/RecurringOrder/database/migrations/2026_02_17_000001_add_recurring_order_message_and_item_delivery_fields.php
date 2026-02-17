<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_orders', function (Blueprint $table) {
            $table->text('message')->nullable()->after('sourceable_id');
            $table->json('notification_channels')->nullable()->after('message');
        });

        Schema::table('recurring_order_items', function (Blueprint $table) {
            $table->date('preferred_delivery_date')->nullable()->after('unit_price');
            $table->date('latest_delivery_date')->nullable()->after('preferred_delivery_date');
            $table->text('special_instructions')->nullable()->after('latest_delivery_date');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_orders', function (Blueprint $table) {
            $table->dropColumn(['message', 'notification_channels']);
        });

        Schema::table('recurring_order_items', function (Blueprint $table) {
            $table->dropColumn(['preferred_delivery_date', 'latest_delivery_date', 'special_instructions']);
        });
    }
};
