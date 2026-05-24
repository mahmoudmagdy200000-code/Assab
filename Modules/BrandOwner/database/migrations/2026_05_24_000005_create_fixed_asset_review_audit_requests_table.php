<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fixed_asset_review_audit_requests')) {
            return;
        }

        Schema::create('fixed_asset_review_audit_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('branch_id');
            $table->uuid('asset_id')->nullable();
            $table->uuid('zone_id')->nullable();
            $table->uuid('initiated_by_id')->nullable();

            $table->string('status', 32)->default('pending');

            $table->string('manager_name_snapshot')->nullable();
            $table->string('zone_name_snapshot')->nullable();
            $table->string('asset_name_snapshot')->nullable();
            $table->string('asset_code_snapshot')->nullable();
            $table->string('asset_type_snapshot')->nullable();
            $table->string('image_url')->nullable();

            $table->decimal('value', 12, 2)->nullable();
            $table->integer('total_assets')->nullable();
            $table->date('audit_date')->nullable();
            $table->json('conditions')->nullable();
            $table->text('additional_notes')->nullable();

            $table->text('rejection_reason')->nullable();
            $table->json('cancellation')->nullable();

            $table->uuid('dest_decided_by_id')->nullable();
            $table->timestamp('dest_decided_at')->nullable();
            $table->uuid('bo_decided_by_id')->nullable();
            $table->timestamp('bo_decided_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('branch_id', 'fa_ra_branch_idx');
            $table->index('status', 'fa_ra_status_idx');
            $table->index(['branch_id', 'status'], 'fa_ra_branch_status_idx');

            $table->foreign('branch_id', 'fa_ra_branch_fk')
                ->references('id')
                ->on('branches')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_review_audit_requests');
    }
};
