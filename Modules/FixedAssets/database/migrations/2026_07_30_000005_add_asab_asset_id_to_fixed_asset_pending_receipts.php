<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_asset_pending_receipts', function (Blueprint $table) {
            // Bridge key back to the dashboard row (asab_assets) so the mobile
            // «تم الاستلام» confirmation can stamp it.
            if (! Schema::hasColumn('fixed_asset_pending_receipts', 'asab_asset_id')) {
                $table->uuid('asab_asset_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('fixed_asset_pending_receipts', function (Blueprint $table) {
            $table->dropIndex(['asab_asset_id']);
            $table->dropColumn('asab_asset_id');
        });
    }
};
