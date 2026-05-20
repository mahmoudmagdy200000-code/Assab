<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add composite indexes for the branch-manager dashboard and cashier
     * statistics hot paths. Single-column indexes for these columns already
     * exist; these composites cover the combined filters used together.
     */
    public function up(): void
    {
        // Dashboard filters today's cashier shifts by shift_date + status repeatedly.
        Schema::table('cashier_shifts', function (Blueprint $table) {
            if (! $this->indexExists('cashier_shifts', 'idx_cs_date_status')) {
                $table->index(['shift_date', 'status'], 'idx_cs_date_status');
            }
        });

        // Cashier listing and statistics filter by branch_id + status together.
        Schema::table('cashiers', function (Blueprint $table) {
            if (! $this->indexExists('cashiers', 'idx_cashiers_branch_status')) {
                $table->index(['branch_id', 'status'], 'idx_cashiers_branch_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table) {
            if ($this->indexExists('cashier_shifts', 'idx_cs_date_status')) {
                $table->dropIndex('idx_cs_date_status');
            }
        });

        Schema::table('cashiers', function (Blueprint $table) {
            if ($this->indexExists('cashiers', 'idx_cashiers_branch_status')) {
                $table->dropIndex('idx_cashiers_branch_status');
            }
        });
    }

    /**
     * Check if an index exists (driver-aware).
     */
    private function indexExists(string $table, string $index): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $result = DB::select(
                "SELECT name FROM sqlite_master WHERE type='index' AND name=? AND tbl_name=?",
                [$index, $table]
            );

            return count($result) > 0;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $result = DB::select(
                'SELECT COUNT(*) as count
                 FROM information_schema.statistics
                 WHERE table_schema = ?
                 AND table_name = ?
                 AND index_name = ?',
                [DB::getDatabaseName(), $table, $index]
            );

            return ! empty($result) && ($result[0]->count ?? 0) > 0;
        }

        try {
            $result = DB::select(
                'SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $index]
            );

            return count($result) > 0;
        } catch (\Exception) {
            return false;
        }
    }
};
