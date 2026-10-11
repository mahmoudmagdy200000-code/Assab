<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_report_manager_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable()->index();
            $table->uuid('branch_id')->nullable()->index();
            $table->uuid('cashier_shift_id')->index();
            $table->uuid('report_revision_id')->unique('srmr_rev_id_unique');
            $table->unsignedInteger('report_revision_number')->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->bigInteger('variance_halalas')->default(0);
            $table->boolean('has_shortage')->default(false);
            $table->string('reviewer_type', 64)->nullable();
            $table->string('reviewer_id', 64)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['cashier_shift_id', 'status'], 'srmr_shift_status_idx');
        });

        Schema::table('shift_report_aggregates', function (Blueprint $table) {
            $table->uuid('required_manager_review_evidence_revision_id')->nullable()->index('sra_req_mgr_rev_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('shift_report_manager_reviews')) {
            $hasApproved = DB::table('shift_report_manager_reviews')->where('status', 'approved')->exists();
            if ($hasApproved) {
                throw new LogicException('CANNOT_ROLLBACK_APPROVED_MANAGER_REVIEWS');
            }
            Schema::dropIfExists('shift_report_manager_reviews');
        }

        if (Schema::hasTable('shift_report_aggregates') && Schema::hasColumn('shift_report_aggregates', 'required_manager_review_evidence_revision_id')) {
            Schema::table('shift_report_aggregates', function (Blueprint $table) {
                $table->dropColumn('required_manager_review_evidence_revision_id');
            });
        }
    }
};
