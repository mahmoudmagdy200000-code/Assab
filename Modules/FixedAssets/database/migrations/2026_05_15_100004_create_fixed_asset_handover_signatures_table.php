<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fixed_asset_handover_signatures')) {
            return;
        }

        Schema::create('fixed_asset_handover_signatures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('handover_id');
            $table->string('role', 16);
            $table->string('signed_by_type');
            $table->uuid('signed_by_id');
            $table->string('signed_by_name_snapshot');
            $table->string('signature_image_path')->nullable();
            $table->timestamp('signed_at');
            $table->timestamps();

            $table->unique(['handover_id', 'role'], 'handover_role_unique');
            $table->index('handover_id');
            $table->index(['signed_by_type', 'signed_by_id']);

            $table->foreign('handover_id')
                ->references('id')
                ->on('fixed_asset_handovers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_handover_signatures');
    }
};
