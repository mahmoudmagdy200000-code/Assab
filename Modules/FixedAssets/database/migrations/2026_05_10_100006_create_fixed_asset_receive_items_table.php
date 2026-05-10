<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fixed_asset_receive_items')) {
            return;
        }

        Schema::create('fixed_asset_receive_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('receive_session_id');
            $table->uuid('pending_receipt_id');
            $table->uuid('fixed_asset_id')->nullable();

            $table->uuid('assigned_zone_id');
            $table->uuid('asset_type_id');

            $table->unsignedInteger('asset_count')->default(0);
            $table->unsignedInteger('excellent_count')->default(0);
            $table->unsignedInteger('need_attention_count')->default(0);
            $table->unsignedInteger('problem_count')->default(0);

            $table->string('image_path')->nullable();

            $table->timestamps();

            $table->index('receive_session_id');
            $table->index('pending_receipt_id');
            $table->index('fixed_asset_id');

            $table->foreign('receive_session_id')
                ->references('id')
                ->on('fixed_asset_receive_sessions')
                ->cascadeOnDelete();

            $table->foreign('pending_receipt_id')
                ->references('id')
                ->on('fixed_asset_pending_receipts')
                ->cascadeOnDelete();

            $table->foreign('fixed_asset_id')
                ->references('id')
                ->on('fixed_assets')
                ->nullOnDelete();

            $table->foreign('assigned_zone_id')
                ->references('id')
                ->on('asset_zones')
                ->cascadeOnDelete();

            $table->foreign('asset_type_id')
                ->references('id')
                ->on('asset_types')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_receive_items');
    }
};
