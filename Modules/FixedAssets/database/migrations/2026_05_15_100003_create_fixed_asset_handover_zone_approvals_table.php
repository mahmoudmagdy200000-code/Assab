<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fixed_asset_handover_zone_approvals')) {
            return;
        }

        Schema::create('fixed_asset_handover_zone_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('handover_id');
            $table->uuid('zone_id');
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->unique(['handover_id', 'zone_id'], 'handover_zone_unique');
            $table->index('handover_id');

            $table->foreign('handover_id')
                ->references('id')
                ->on('fixed_asset_handovers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_handover_zone_approvals');
    }
};
