<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Isolated from legacy detail deletion/status writers and VarianceRecorded listeners.
        Schema::create('shift_liability_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cashier_shift_id')->constrained('cashier_shifts')->restrictOnDelete();
            $table->uuid('company_id');
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('report_revision', 191);
            $table->bigInteger('variance_halalas');
            $table->string('created_by_type', 32);
            $table->uuid('created_by_id');
            $table->text('reason')->nullable();
            $table->timestamp('cashier_confirmed_at')->nullable();
            $table->string('manager_approval_status', 20)->default('pending');
            $table->uuid('manager_approved_by')->nullable();
            $table->timestamp('manager_approved_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->unique(['cashier_shift_id', 'version'], 'shift_liability_version_unique');
        });
        Schema::create('shift_liability_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('allocation_id')->constrained('shift_liability_allocations')->restrictOnDelete();
            $table->string('responsible_type', 32);
            $table->uuid('responsible_id');
            $table->unsignedBigInteger('amount_halalas');
            $table->string('employee_response_status', 20)->default('pending');
            $table->text('employee_response_reason')->nullable();
            $table->timestamp('employee_responded_at')->nullable();
            $table->timestamps();
            $table->unique(['allocation_id', 'responsible_type', 'responsible_id'], 'shift_liability_actor_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_liability_shares');
        Schema::dropIfExists('shift_liability_allocations');
    }
};
