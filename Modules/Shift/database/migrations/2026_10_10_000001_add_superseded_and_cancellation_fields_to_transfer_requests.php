<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cashier_shift_handovers', 'branch_manager_cash_transfers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->timestamp('superseded_at')->nullable()->index();
                $table->uuid('supersedes_id')->nullable()->index();
                $table->timestamp('cancelled_at')->nullable()->index();
                $table->string('cancelled_by_type', 30)->nullable();
                $table->uuid('cancelled_by_id')->nullable();
                $table->text('cancellation_reason')->nullable();
                $table->uuid('replacement_request_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['cashier_shift_handovers', 'branch_manager_cash_transfers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['superseded_at']);
                $table->dropIndex(['supersedes_id']);
                $table->dropIndex(['cancelled_at']);
                $table->dropIndex(['replacement_request_id']);
                $table->dropColumn([
                    'superseded_at',
                    'supersedes_id',
                    'cancelled_at',
                    'cancelled_by_type',
                    'cancelled_by_id',
                    'cancellation_reason',
                    'replacement_request_id',
                ]);
            });
        }
    }
};
