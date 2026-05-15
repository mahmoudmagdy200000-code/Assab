<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fixed_asset_handover_items')) {
            return;
        }

        Schema::create('fixed_asset_handover_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('handover_id');
            $table->uuid('asset_id');

            $table->uuid('zone_id')->nullable();
            $table->string('zone_name_snapshot')->nullable();

            $table->string('asset_name_snapshot');
            $table->string('asset_code_snapshot');
            $table->string('asset_image_snapshot')->nullable();
            $table->string('asset_type_name_snapshot')->nullable();
            $table->decimal('value_snapshot', 12, 2)->default(0);
            $table->timestamp('acquired_at_snapshot')->nullable();

            $table->unsignedInteger('current_qty')->default(1);
            $table->unsignedInteger('new_qty')->nullable();

            $table->string('recipient_inspection', 32)->nullable();
            $table->text('recipient_note')->nullable();
            $table->string('recipient_photo_path')->nullable();
            $table->timestamp('inspected_at')->nullable();

            $table->timestamps();

            $table->index('handover_id');
            $table->index('asset_id');
            $table->index('zone_id');
            $table->index('recipient_inspection');

            $table->foreign('handover_id')
                ->references('id')
                ->on('fixed_asset_handovers')
                ->cascadeOnDelete();

            $table->foreign('asset_id')
                ->references('id')
                ->on('fixed_assets')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_handover_items');
    }
};
