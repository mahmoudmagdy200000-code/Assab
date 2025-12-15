<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add performance indexes to optimize query performance
     */
    public function up(): void
    {
        // Indexes for branch_manager_shifts table
        Schema::table('branch_manager_shifts', function (Blueprint $table) {
            // Composite index for most common query pattern (branch_manager_id + shift_date)
            if (!$this->indexExists('branch_manager_shifts', 'idx_bms_manager_date')) {
                $table->index(['branch_manager_id', 'shift_date'], 'idx_bms_manager_date');
            }

            // Index for status filtering
            if (!$this->indexExists('branch_manager_shifts', 'idx_bms_status')) {
                $table->index('status', 'idx_bms_status');
            }

            // Composite index for branch queries
            if (!$this->indexExists('branch_manager_shifts', 'idx_bms_branch_date')) {
                $table->index(['branch_id', 'shift_date'], 'idx_bms_branch_date');
            }

            // Composite index for status + date filtering
            if (!$this->indexExists('branch_manager_shifts', 'idx_bms_status_date')) {
                $table->index(['status', 'shift_date'], 'idx_bms_status_date');
            }
        });

        // Indexes for cashier_shift_handovers table
        Schema::table('cashier_shift_handovers', function (Blueprint $table) {
            // Composite index for handover queries (most common pattern)
            if (!$this->indexExists('cashier_shift_handovers', 'idx_csh_to_type_id_status')) {
                $table->index(['handover_to_type', 'handover_to_id', 'status'], 'idx_csh_to_type_id_status');
            }

            // Composite index for cashier shift + handover to queries
            if (!$this->indexExists('cashier_shift_handovers', 'idx_csh_shift_to_id')) {
                $table->index(['cashier_shift_id', 'handover_to_id'], 'idx_csh_shift_to_id');
            }

            // Index for status + date filtering
            if (!$this->indexExists('cashier_shift_handovers', 'idx_csh_status_date')) {
                $table->index(['status', 'handover_date'], 'idx_csh_status_date');
            }

            // Index for handover_date filtering
            if (!$this->indexExists('cashier_shift_handovers', 'idx_csh_date')) {
                $table->index('handover_date', 'idx_csh_date');
            }
        });

        // Indexes for cashier_shifts table
        Schema::table('cashier_shifts', function (Blueprint $table) {
            // Composite index for cashier + date queries
            if (!$this->indexExists('cashier_shifts', 'idx_cs_cashier_date')) {
                $table->index(['cashier_id', 'shift_date'], 'idx_cs_cashier_date');
            }

            // Index for shift_date filtering
            if (!$this->indexExists('cashier_shifts', 'idx_cs_date')) {
                $table->index('shift_date', 'idx_cs_date');
            }
        });

        // Indexes for shift_sales_breakdown table
        Schema::table('shift_sales_breakdown', function (Blueprint $table) {
            // Index for cashier_shift_id (used in aggregate queries)
            if (!$this->indexExists('shift_sales_breakdown', 'idx_ssb_shift_id')) {
                $table->index('cashier_shift_id', 'idx_ssb_shift_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branch_manager_shifts', function (Blueprint $table) {
            $table->dropIndex('idx_bms_manager_date');
            $table->dropIndex('idx_bms_status');
            $table->dropIndex('idx_bms_branch_date');
            $table->dropIndex('idx_bms_status_date');
        });

        Schema::table('cashier_shift_handovers', function (Blueprint $table) {
            $table->dropIndex('idx_csh_to_type_id_status');
            $table->dropIndex('idx_csh_shift_to_id');
            $table->dropIndex('idx_csh_status_date');
            $table->dropIndex('idx_csh_date');
        });

        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->dropIndex('idx_cs_cashier_date');
            $table->dropIndex('idx_cs_date');
        });

        Schema::table('shift_sales_breakdown', function (Blueprint $table) {
            $table->dropIndex('idx_ssb_shift_id');
        });
    }

    /**
     * Check if index exists using raw SQL query
     */
    private function indexExists(string $table, string $index): bool
    {
        $databaseName = DB::getDatabaseName();

        $result = DB::select(
            "SELECT COUNT(*) as count
             FROM information_schema.statistics
             WHERE table_schema = ?
             AND table_name = ?
             AND index_name = ?",
            [$databaseName, $table, $index]
        );

        return $result[0]->count > 0;
    }
};
