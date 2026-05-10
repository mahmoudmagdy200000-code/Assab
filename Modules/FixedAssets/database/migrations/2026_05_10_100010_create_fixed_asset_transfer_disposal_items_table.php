<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fixed_asset_transfer_disposal_items')) {
            return;
        }

        Schema::create('fixed_asset_transfer_disposal_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('request_id');
            $table->uuid('asset_id');

            $table->text('transfer_reason')->nullable();
            $table->text('disposal_reason')->nullable();
            $table->text('condition_description')->nullable();

            $table->timestamps();

            $table->index('request_id');
            $table->index('asset_id');
            $table->unique(['request_id', 'asset_id'], 'unique_request_asset');

            $table->foreign('request_id')
                ->references('id')
                ->on('fixed_asset_transfer_disposal_requests')
                ->cascadeOnDelete();

            $table->foreign('asset_id')
                ->references('id')
                ->on('fixed_assets')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_transfer_disposal_items');
    }
};
