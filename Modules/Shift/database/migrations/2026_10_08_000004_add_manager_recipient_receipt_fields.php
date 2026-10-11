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
                ->constrained('branch_manager_shifts', indexName: 'shift_receipt_receiving_manager_day_fk')->restrictOnDelete();
            $table->foreignUuid('receiving_branch_manager_id')->nullable()->after('receiving_branch_manager_shift_id')
                ->constrained('branch_managers', indexName: 'shift_receipt_receiving_manager_fk')->restrictOnDelete();
            $table->foreignUuid('confirmed_by_branch_manager_id')->nullable()->after('confirmed_by_id')
                ->constrained('branch_managers', indexName: 'shift_receipt_confirming_manager_fk')->restrictOnDelete();
            $table->index(['receiving_branch_manager_shift_id', 'confirmed_at'], 'shift_receipt_manager_destination_time');
        });
    }

    public function down(): void
    {
        if (DB::table('cashier_shift_handover_receipts')->whereNotNull('receiving_branch_manager_shift_id')->exists()) {
            throw new RuntimeException('Manager receipt evidence exists; migration rollback would destroy it.');
        }

        $columns = ['confirmed_by_branch_manager_id', 'receiving_branch_manager_id', 'receiving_branch_manager_shift_id'];
        $foreignNames = [];
        foreach (Schema::getForeignKeys('cashier_shift_handover_receipts') as $key) {
            foreach ($columns as $column) {
                if ($key['columns'] === [$column]) {
                    $foreignNames[$column] = $key['name'];
                }
            }
        }
        Schema::table('cashier_shift_handover_receipts', function (Blueprint $table) use ($columns, $foreignNames) {
            $table->dropIndex('shift_receipt_manager_destination_time');
            foreach ($columns as $column) {
                $table->dropForeign($foreignNames[$column] ?? [$column]);
                $table->dropColumn($column);
            }
            $table->foreignUuid('confirmed_by_id')->nullable(false)->change();
            $table->foreignUuid('receiving_cashier_id')->nullable(false)->change();
            $table->foreignUuid('receiving_cashier_shift_id')->nullable(false)->change();
        });
    }
};
