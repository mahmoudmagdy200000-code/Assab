<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fixed_asset_handover_items')) {
            return;
        }

        Schema::table('fixed_asset_handover_items', function (Blueprint $table) {
            if (! Schema::hasColumn('fixed_asset_handover_items', 'is_deducted')) {
                $table->boolean('is_deducted')->default(false);
            }
            if (! Schema::hasColumn('fixed_asset_handover_items', 'deduction')) {
                $table->json('deduction')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('fixed_asset_handover_items')) {
            return;
        }

        Schema::table('fixed_asset_handover_items', function (Blueprint $table) {
            foreach (['is_deducted', 'deduction'] as $col) {
                if (Schema::hasColumn('fixed_asset_handover_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
