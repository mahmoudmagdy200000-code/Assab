<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_liability_daily_locks', function (Blueprint $table) {
            $table->timestamp('superseded_at')->nullable()->after('release_reason');
            $table->timestamp('reopened_at')->nullable()->after('superseded_at');
            $table->string('reopened_by_type', 100)->nullable()->after('reopened_at');
            $table->uuid('reopened_by_id')->nullable()->after('reopened_by_type');
            $table->text('reopen_reason')->nullable()->after('reopened_by_id');

            $table->index(['cashier_shift_id', 'superseded_at'], 'shift_liability_lock_superseded');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('shift_liability_daily_locks') && DB::table('shift_liability_daily_locks')->whereNotNull('superseded_at')->count() > 0) {
            throw new \RuntimeException('Cannot rollback migration: shift_liability_daily_locks contains superseded history.');
        }

        Schema::table('shift_liability_daily_locks', function (Blueprint $table) {
            $table->dropIndex('shift_liability_lock_superseded');
            $table->dropColumn([
                'superseded_at',
                'reopened_at',
                'reopened_by_type',
                'reopened_by_id',
                'reopen_reason',
            ]);
        });
    }
};
