<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fixed_asset_modification_done_actions')) {
            return;
        }

        Schema::create('fixed_asset_modification_done_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('modification_request_id');
            $table->string('action', 32);

            $table->timestamps();

            $table->index('modification_request_id', 'fa_mod_done_request_idx');
            $table->unique(['modification_request_id', 'action'], 'fa_mod_done_request_action_uq');

            $table->foreign('modification_request_id', 'fa_mod_done_request_fk')
                ->references('id')
                ->on('fixed_asset_modification_requests')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_modification_done_actions');
    }
};
