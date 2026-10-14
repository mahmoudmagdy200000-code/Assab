<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B-01 forward-only repair (no data change).
 *
 * 2026_10_13_000002 originally failed on MySQL/MariaDB while adding a 71-character index name. Schema::create()
 * had already created the table and the indexes that precede it, and a retry then returned early on
 * Schema::hasTable() and was recorded as run -- leaving a table that exists but is missing indexes.
 *
 * This migration never assumes the table is complete: it verifies the columns, the primary key and the three
 * secondary indexes (by their columns, not their names) and only ADDS what is missing. A table that lacks
 * required columns or the primary key is not guessed at: the migration aborts and touches nothing. Rows are
 * never read, updated or deleted, so the append-only audit history is preserved. A complete table is a no-op.
 */
return new class extends Migration
{
    private const TABLE = 'asab_brand_manager_assignment_audit';

    private const COLUMNS = ['id', 'company_id', 'brand_manager_id', 'dashboard_user_id', 'brand_ids', 'action', 'granted_by', 'created_at'];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException(self::TABLE.' is missing; run 2026_10_13_000002 first. Nothing was changed.');
        }

        $missingColumns = array_values(array_diff(self::COLUMNS, Schema::getColumnListing(self::TABLE)));
        if ($missingColumns !== []) {
            throw new RuntimeException(self::TABLE.' is incomplete (missing columns: '.implode(', ', $missingColumns)
                .'). Refusing to guess a repair; nothing was changed.');
        }

        $indexes = collect(Schema::getIndexes(self::TABLE));
        if (! $indexes->contains(fn ($index) => ($index['primary'] ?? false) && $index['columns'] === ['id'])) {
            throw new RuntimeException(self::TABLE.' has no primary key on id. Refusing to guess a repair; nothing was changed.');
        }

        $has = fn (array $columns) => $indexes->contains(fn ($index) => ! ($index['primary'] ?? false) && $index['columns'] === $columns);

        Schema::table(self::TABLE, function (Blueprint $table) use ($has) {
            if (! $has(['company_id'])) {
                $table->index('company_id', 'asab_brand_manager_assignment_audit_company_id_index');
            }
            if (! $has(['brand_manager_id'])) {
                $table->index('brand_manager_id', 'asab_brand_manager_assignment_audit_brand_manager_id_index');
            }
            if (! $has(['brand_manager_id', 'created_at'])) {
                $table->index(['brand_manager_id', 'created_at'], 'asab_bm_assign_audit_bm_created_idx');
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty: the indexes belong to 2026_10_13_000002, whose down() owns the append-only
        // rollback guard. Removing them here would weaken that table without protecting any data.
    }
};
