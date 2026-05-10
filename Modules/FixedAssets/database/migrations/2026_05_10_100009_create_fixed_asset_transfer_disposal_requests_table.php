<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fixed_asset_transfer_disposal_requests')) {
            return;
        }

        Schema::create('fixed_asset_transfer_disposal_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('kind', 32);
            $table->uuid('branch_id');
            $table->uuid('requested_by_id');

            $table->uuid('recipient_branch_id')->nullable();
            $table->boolean('auto_approve')->default(false);

            $table->date('disposal_date')->nullable();
            $table->string('disposal_time', 32)->nullable();
            $table->string('disposal_method', 32)->nullable();

            $table->string('direction', 32)->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('kind', 'fa_td_req_kind_idx');
            $table->index('branch_id', 'fa_td_req_branch_idx');
            $table->index('requested_by_id', 'fa_td_req_requested_by_idx');
            $table->index('recipient_branch_id', 'fa_td_req_recipient_branch_idx');
            $table->index('status', 'fa_td_req_status_idx');
            $table->index(['kind', 'status'], 'fa_td_req_kind_status_idx');
            $table->index(['branch_id', 'kind'], 'fa_td_req_branch_kind_idx');

            $table->foreign('branch_id', 'fa_td_req_branch_fk')
                ->references('id')
                ->on('branches')
                ->cascadeOnDelete();

            $table->foreign('recipient_branch_id', 'fa_td_req_recipient_branch_fk')
                ->references('id')
                ->on('branches')
                ->nullOnDelete();

            $table->foreign('requested_by_id', 'fa_td_req_requested_by_fk')
                ->references('id')
                ->on('branch_managers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_transfer_disposal_requests');
    }
};
