<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_variance_review_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_revision_id')->constrained('shift_report_revisions')->restrictOnDelete();
            $table->foreignUuid('cashier_shift_id')->constrained('cashier_shifts')->restrictOnDelete();
            $table->uuid('shift_variance_detail_id')->nullable();
            $table->string('responsibility_status', 30)->default('pending');
            $table->string('reviewed_by_id', 64)->nullable();
            $table->string('reviewed_by_type', 100)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['report_revision_id', 'responsibility_status']);
            $table->index('cashier_shift_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_variance_review_evidence');
    }
};
