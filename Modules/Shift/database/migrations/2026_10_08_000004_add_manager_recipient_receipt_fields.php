<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_shift_handover_receipts', function (Blueprint $table) {
            $table->foreignUuid('receiving_cashier_shift_id')->nullable()->change();
            $table->foreignUuid('receiving_cashier_id')->nullable()->change();
            $table->foreignUuid('confirmed_by_id')->nullable()->change();
            $table->foreignUuid('receiving_branch_manager_shift_id')->nullable()->after('receiving_cashier_id')
                ->constrained('branch_manager_shifts')->restrictOnDelete();
            $table->foreignUuid('receiving_branch_manager_id')->nullable()->after('receiving_branch_manager_shift_id')
                ->constrained('branch_managers')->restrictOnDelete();
            $table->foreignUuid('confirmed_by_branch_manager_id')->nullable()->after('confirmed_by_id')
                ->constrained('branch_managers')->restrictOnDelete();
            $table->index(['receiving_branch_manager_shift_id', 'confirmed_at'], 'shift_receipt_manager_destination_time');
        });
    }

    public function down(): void
    {
        if (DB::table('cashier_shift_handover_receipts')->whereNotNull('receiving_branch_manager_shift_id')->exists()) {
            throw new RuntimeException('Manager receipt evidence exists; migration rollback would destroy it.');
        }

        Schema::table('cashier_shift_handover_receipts', function (Blueprint $table) {
            $table->dropIndex('shift_receipt_manager_destination_time');
            $table->dropConstrainedForeignId('confirmed_by_branch_manager_id');
            $table->dropConstrainedForeignId('receiving_branch_manager_id');
            $table->dropConstrainedForeignId('receiving_branch_manager_shift_id');
            $table->foreignUuid('confirmed_by_id')->nullable(false)->change();
            $table->foreignUuid('receiving_cashier_id')->nullable(false)->change();
            $table->foreignUuid('receiving_cashier_shift_id')->nullable(false)->change();
        });
    }
};
