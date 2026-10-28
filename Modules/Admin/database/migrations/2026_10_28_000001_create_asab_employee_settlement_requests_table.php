<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BE-09 / STMT-02: Governed Employee Settlement Requests.
 *
 * Schema Proposal for Head Accountant approval workflow, idempotency,
 * and immutable capture of settlement intent (partial vs full balance).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asab_employee_settlement_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('employee_id')->index();
            $table->unsignedBigInteger('amount_halalas');
            $table->boolean('is_full_settlement')->default(false);
            $table->bigInteger('original_balance_halalas');
            $table->enum('direction', ['credit', 'debit']);
            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->index();
            $table->text('rejection_reason')->nullable();
            $table->uuid('created_by_user_id')->index();
            $table->uuid('reviewed_by_user_id')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->uuid('movement_id')->nullable()->index();
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key'], 'asab_emp_settle_comp_idemp_unique');
            $table->index(['employee_id', 'status'], 'asab_emp_settle_emp_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asab_employee_settlement_requests');
    }
};
