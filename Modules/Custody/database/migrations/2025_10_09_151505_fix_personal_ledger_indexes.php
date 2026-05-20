<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Check if table exists
        if (Schema::hasTable('personal_ledger_transactions')) {
            // Drop the problematic index if it exists (with old long name)
            try {
                DB::statement('ALTER TABLE `personal_ledger_transactions` DROP INDEX `personal_ledger_transactions_branch_manager_id_transaction_date_index`');
            } catch (\Exception $e) {
                // Index doesn't exist or has different name, continue
            }

            // Add the index with shorter name if it doesn't exist
            if (! $this->indexExists('personal_ledger_transactions', 'idx_plt_bm_id_txn_date')) {
                Schema::table('personal_ledger_transactions', function (Blueprint $table) {
                    $table->index(['branch_manager_id', 'transaction_date'], 'idx_plt_bm_id_txn_date');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('personal_ledger_transactions')) {
            Schema::table('personal_ledger_transactions', function (Blueprint $table) {
                $table->dropIndex('idx_plt_bm_id_txn_date');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: Query sqlite_master table
            $result = DB::select(
                "SELECT name FROM sqlite_master WHERE type='index' AND name=? AND tbl_name=?",
                [$indexName, $table]
            );

            return count($result) > 0;
        } elseif ($driver === 'mysql') {
            // MySQL: Use SHOW INDEXES
            $indexes = DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$indexName]);

            return count($indexes) > 0;
        } else {
            // PostgreSQL and others: Query information_schema
            try {
                $result = DB::select(
                    'SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                    [$table, $indexName]
                );

                return count($result) > 0;
            } catch (\Exception $e) {
                // Fallback: Try to use Laravel's schema inspector if available
                // For other databases, return false and let it attempt to create
                return false;
            }
        }
    }
};
