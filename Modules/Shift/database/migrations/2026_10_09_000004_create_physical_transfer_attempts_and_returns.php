<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_transfer_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('request_type', 30);
            $table->uuid('request_id');
            $table->unsignedInteger('sequence');
            $table->uuid('report_revision_id');
            $table->string('sender_type', 30);
            $table->uuid('sender_id');
            $table->string('recipient_type', 30);
            $table->uuid('recipient_id');
            $table->uuid('source_branch_id');
            $table->uuid('source_company_id')->nullable();
            $table->uuid('receiving_cashier_shift_id')->nullable();
            $table->unsignedBigInteger('presented_halalas');
            $table->timestamp('presented_at');
            $table->string('idempotency_key', 100);
            $table->string('payload_hash', 64);
            $table->timestamps();
            $table->unique(['request_type', 'request_id', 'sequence'], 'transfer_attempt_sequence_unique');
            $table->unique(['request_type', 'request_id', 'idempotency_key'], 'transfer_attempt_idempotency_unique');
        });
        Schema::create('shift_transfer_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transfer_attempt_id')->constrained('shift_transfer_attempts')->restrictOnDelete();
            $table->uuid('rejection_evidence_id');
            $table->unsignedBigInteger('returned_halalas');
            $table->string('initiated_by_type', 30);
            $table->uuid('initiated_by_id');
            $table->timestamp('initiated_at');
            $table->string('sender_confirmed_by_type', 30)->nullable();
            $table->uuid('sender_confirmed_by_id')->nullable();
            $table->timestamp('sender_confirmed_at')->nullable();
            $table->text('reason');
            $table->text('evidence_reference')->nullable();
            $table->string('idempotency_key', 100);
            $table->string('payload_hash', 64);
            $table->timestamps();
            $table->unique(['transfer_attempt_id', 'idempotency_key'], 'transfer_return_idempotency_unique');
        });
        foreach (['cashier_shift_handovers', 'branch_manager_cash_transfers'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->uuid('current_transfer_attempt_id')->nullable()->index());
        }
        foreach (['shift_transfer_rejection_evidence', 'cashier_shift_handover_receipts'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->uuid('transfer_attempt_id')->nullable()->index());
        }
        Schema::table('shift_report_aggregates', fn (Blueprint $table) => $table->boolean('fresh_count_required')->default(false));
    }

    public function down(): void
    {
        Schema::table('shift_report_aggregates', fn (Blueprint $table) => $table->dropColumn('fresh_count_required'));
        foreach (['shift_transfer_rejection_evidence', 'cashier_shift_handover_receipts'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['transfer_attempt_id']);
                $table->dropColumn('transfer_attempt_id');
            });
        }
        foreach (['cashier_shift_handovers', 'branch_manager_cash_transfers'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['current_transfer_attempt_id']);
                $table->dropColumn('current_transfer_attempt_id');
            });
        }
        Schema::dropIfExists('shift_transfer_returns');
        Schema::dropIfExists('shift_transfer_attempts');
    }
};
