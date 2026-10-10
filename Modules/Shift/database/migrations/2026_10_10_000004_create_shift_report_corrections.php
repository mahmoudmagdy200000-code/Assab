<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_report_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_aggregate_id')->constrained('shift_report_aggregates')->restrictOnDelete();
            $table->foreignUuid('previous_revision_id')->nullable()->constrained('shift_report_revisions')->restrictOnDelete();
            $table->foreignUuid('new_revision_id')->constrained('shift_report_revisions')->restrictOnDelete();
            $table->unsignedInteger('previous_revision_number')->nullable();
            $table->unsignedInteger('new_revision_number');
            $table->string('field_name', 50);
            $table->string('field_type', 20); // monetary, string, integer, json
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->bigInteger('old_halalas')->nullable();
            $table->bigInteger('new_halalas')->nullable();
            $table->text('reason');
            $table->string('actor_type', 100);
            $table->uuid('actor_id');
            $table->uuid('company_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->string('operation_id', 100);
            $table->timestamp('created_at')->nullable();

            $table->unique(['report_aggregate_id', 'operation_id', 'field_name'], 'shift_report_corrections_agg_op_field_unique');
            $table->index(['report_aggregate_id', 'new_revision_number'], 'shift_report_corrections_agg_rev_idx');
            $table->index('operation_id', 'shift_report_corrections_op_idx');
        });

        if (Schema::hasTable('shift_report_cash_counts') && ! Schema::hasColumn('shift_report_cash_counts', 'evidence_revision_id')) {
            Schema::table('shift_report_cash_counts', function (Blueprint $table) {
                $table->foreignUuid('evidence_revision_id')->nullable()->constrained('shift_report_revisions')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('shift_report_corrections') && DB::table('shift_report_corrections')->count() > 0) {
            throw new \RuntimeException('Cannot rollback migration: shift_report_corrections table contains immutable historical evidence.');
        }

        if (Schema::hasTable('shift_report_cash_counts') && Schema::hasColumn('shift_report_cash_counts', 'evidence_revision_id')) {
            Schema::table('shift_report_cash_counts', function (Blueprint $table) {
                $table->dropForeign(['evidence_revision_id']);
                $table->dropColumn('evidence_revision_id');
            });
        }

        Schema::dropIfExists('shift_report_corrections');
    }
};
