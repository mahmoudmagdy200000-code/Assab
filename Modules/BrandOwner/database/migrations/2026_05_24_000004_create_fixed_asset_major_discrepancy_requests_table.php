<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fixed_asset_major_discrepancy_requests')) {
            return;
        }

        Schema::create('fixed_asset_major_discrepancy_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('handover_id');
            $table->uuid('handover_item_id');
            $table->uuid('asset_id')->nullable();
            $table->uuid('branch_id');

            $table->string('status', 32)->default('pending');

            $table->string('employee_responsible')->nullable();
            $table->text('warning_note')->nullable();
            $table->decimal('salary_deduction_amount', 12, 2)->nullable();
            $table->text('salary_deduction_reason')->nullable();
            $table->text('salary_deduction_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('cancellation')->nullable();

            $table->uuid('bo_decided_by_id')->nullable();
            $table->timestamp('bo_decided_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('handover_id', 'fa_md_handover_idx');
            $table->index('handover_item_id', 'fa_md_handover_item_idx');
            $table->index('branch_id', 'fa_md_branch_idx');
            $table->index('status', 'fa_md_status_idx');

            $table->foreign('handover_id', 'fa_md_handover_fk')
                ->references('id')
                ->on('fixed_asset_handovers')
                ->cascadeOnDelete();

            $table->foreign('handover_item_id', 'fa_md_handover_item_fk')
                ->references('id')
                ->on('fixed_asset_handover_items')
                ->cascadeOnDelete();

            $table->foreign('branch_id', 'fa_md_branch_fk')
                ->references('id')
                ->on('branches')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_major_discrepancy_requests');
    }
};
