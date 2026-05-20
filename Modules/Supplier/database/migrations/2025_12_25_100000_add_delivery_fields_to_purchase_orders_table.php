<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_orders', 'driver_photo')) {
                $table->string('driver_photo')->nullable()->after('vehicle_number');
            }
            if (! Schema::hasColumn('purchase_orders', 'gps_tracking_url')) {
                $table->string('gps_tracking_url')->nullable()->after('driver_photo');
            }
            if (! Schema::hasColumn('purchase_orders', 'delivery_route')) {
                $table->json('delivery_route')->nullable()->after('gps_tracking_url');
            }
            if (! Schema::hasColumn('purchase_orders', 'recipient_name')) {
                $table->string('recipient_name')->nullable()->after('delivery_route');
            }
            if (! Schema::hasColumn('purchase_orders', 'recipient_signature')) {
                $table->string('recipient_signature')->nullable()->after('recipient_name');
            }
            if (! Schema::hasColumn('purchase_orders', 'delivery_photos')) {
                $table->json('delivery_photos')->nullable()->after('recipient_signature');
            }
            if (! Schema::hasColumn('purchase_orders', 'condition_confirmation')) {
                $table->text('condition_confirmation')->nullable()->after('delivery_photos');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn([
                'driver_photo',
                'gps_tracking_url',
                'delivery_route',
                'recipient_name',
                'recipient_signature',
                'delivery_photos',
                'condition_confirmation',
            ]);
        });
    }
};
