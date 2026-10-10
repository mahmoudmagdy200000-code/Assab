<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * S1-07: once a manager submits the day, the liability of every report in that day is locked.
     * A reopen releases the lock with actor, time and reason; rows are never deleted.
     */
    public function up(): void
    {
        Schema::create('shift_liability_daily_locks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cashier_shift_id')->constrained('cashier_shifts')->restrictOnDelete();
            $table->foreignUuid('branch_manager_shift_id')->constrained('branch_manager_shifts')->restrictOnDelete();
            $table->string('report_revision', 191);
            $table->unsignedInteger('allocation_version')->nullable();
            $table->uuid('locked_by_id');
            $table->timestamp('locked_at');
            $table->uuid('released_by_id')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->timestamps();
            $table->index(['cashier_shift_id', 'released_at'], 'shift_liability_lock_active');
            $table->index(['branch_manager_shift_id', 'released_at'], 'shift_liability_lock_workday');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_liability_daily_locks');
    }
};
