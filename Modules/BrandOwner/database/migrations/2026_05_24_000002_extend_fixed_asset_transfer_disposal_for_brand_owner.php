<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fixed_asset_transfer_disposal_items')) {
            Schema::table('fixed_asset_transfer_disposal_items', function (Blueprint $table) {
                if (! Schema::hasColumn('fixed_asset_transfer_disposal_items', 'status')) {
                    $table->string('status', 32)->default('pending');
                }
                if (! Schema::hasColumn('fixed_asset_transfer_disposal_items', 'dest_decided_by_id')) {
                    $table->uuid('dest_decided_by_id')->nullable();
                }
                if (! Schema::hasColumn('fixed_asset_transfer_disposal_items', 'dest_decided_at')) {
                    $table->timestamp('dest_decided_at')->nullable();
                }
                if (! Schema::hasColumn('fixed_asset_transfer_disposal_items', 'bo_decided_by_id')) {
                    $table->uuid('bo_decided_by_id')->nullable();
                }
                if (! Schema::hasColumn('fixed_asset_transfer_disposal_items', 'bo_decided_at')) {
                    $table->timestamp('bo_decided_at')->nullable();
                }
                if (! Schema::hasColumn('fixed_asset_transfer_disposal_items', 'rejection_reason')) {
                    $table->text('rejection_reason')->nullable();
                }
                if (! Schema::hasColumn('fixed_asset_transfer_disposal_items', 'cancellation')) {
                    $table->json('cancellation')->nullable();
                }
            });
        }

        if (Schema::hasTable('fixed_asset_transfer_disposal_requests')) {
            Schema::table('fixed_asset_transfer_disposal_requests', function (Blueprint $table) {
                if (! Schema::hasColumn('fixed_asset_transfer_disposal_requests', 'supported_by_manager_id')) {
                    $table->uuid('supported_by_manager_id')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fixed_asset_transfer_disposal_items')) {
            Schema::table('fixed_asset_transfer_disposal_items', function (Blueprint $table) {
                foreach (['status', 'dest_decided_by_id', 'dest_decided_at', 'bo_decided_by_id', 'bo_decided_at', 'rejection_reason', 'cancellation'] as $col) {
                    if (Schema::hasColumn('fixed_asset_transfer_disposal_items', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('fixed_asset_transfer_disposal_requests')) {
            Schema::table('fixed_asset_transfer_disposal_requests', function (Blueprint $table) {
                if (Schema::hasColumn('fixed_asset_transfer_disposal_requests', 'supported_by_manager_id')) {
                    $table->dropColumn('supported_by_manager_id');
                }
            });
        }
    }
};
