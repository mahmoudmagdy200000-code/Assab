<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_manager_expense_delegations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_manager_id')->index();
            $table->uuid('branch_id')->index();
            $table->uuid('cashier_id')->index();
            $table->uuid('granted_by');
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();

            $table->index(['cashier_id', 'branch_id', 'revoked_at'], 'expense_delegations_active_lookup');

            $table->foreign('branch_manager_id')->references('id')->on('branch_managers')->restrictOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('cashier_id')->references('id')->on('cashiers')->restrictOnDelete();
            $table->foreign('granted_by')->references('id')->on('branch_managers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_manager_expense_delegations');
    }
};
