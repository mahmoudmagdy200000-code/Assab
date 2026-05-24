<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fixed_asset_modification_requests')) {
            return;
        }

        Schema::table('fixed_asset_modification_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('fixed_asset_modification_requests', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
            if (! Schema::hasColumn('fixed_asset_modification_requests', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable();
            }
            if (! Schema::hasColumn('fixed_asset_modification_requests', 'bo_decided_by_id')) {
                $table->uuid('bo_decided_by_id')->nullable();
            }
            if (! Schema::hasColumn('fixed_asset_modification_requests', 'bo_decided_at')) {
                $table->timestamp('bo_decided_at')->nullable();
            }
            if (! Schema::hasColumn('fixed_asset_modification_requests', 'cancellation')) {
                $table->json('cancellation')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('fixed_asset_modification_requests')) {
            return;
        }

        Schema::table('fixed_asset_modification_requests', function (Blueprint $table) {
            foreach (['rejection_reason', 'rejected_at', 'bo_decided_by_id', 'bo_decided_at', 'cancellation'] as $col) {
                if (Schema::hasColumn('fixed_asset_modification_requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
