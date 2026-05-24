<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brand_owner_setting_approvals')) {
            return;
        }

        Schema::create('brand_owner_setting_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('brand_owner_id')->unique();

            $table->unsignedInteger('response_time_hours')->default(24);
            $table->unsignedInteger('initial_audit_minimum_assets')->default(50);
            $table->unsignedInteger('initial_audit_excellent_ratio')->default(85);
            $table->boolean('personal_approval_for_all_new_branches')->default(false);
            $table->unsignedInteger('auto_approve_transfers_within')->default(20000);
            $table->unsignedInteger('auto_approve_modifications_within')->default(15000);
            $table->unsignedInteger('auto_approve_disposals_within')->default(10000);
            $table->boolean('critical_assets_always_require_approval')->default(false);

            $table->timestamps();

            $table->foreign('brand_owner_id', 'bo_setting_approval_fk')
                ->references('id')
                ->on('brand_owners')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_owner_setting_approvals');
    }
};
