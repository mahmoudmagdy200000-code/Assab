<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fixed_asset_modification_requests')) {
            return;
        }

        Schema::create('fixed_asset_modification_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('asset_id');
            $table->uuid('branch_id');
            $table->uuid('requested_by_id');

            $table->string('status', 32)->default('pending');
            $table->string('new_status', 32);
            $table->text('reason');

            $table->string('next_action', 32);
            $table->text('approval_request_owner_note');

            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('asset_id');
            $table->index('branch_id');
            $table->index('requested_by_id');
            $table->index('status');
            $table->index(['branch_id', 'status']);

            $table->foreign('asset_id')
                ->references('id')
                ->on('fixed_assets')
                ->cascadeOnDelete();

            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->cascadeOnDelete();

            $table->foreign('requested_by_id')
                ->references('id')
                ->on('branch_managers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_modification_requests');
    }
};
