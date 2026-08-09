<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the two queries added on 2026-08-09.
 *
 *  - Purchases board + reminder coverage scan:
 *    `company_id = ? AND module_key = ? AND operation_date …` ordered by
 *    `operation_date DESC`. The existing `(company_id, module_key)` index
 *    serves the filter but leaves the sort to a filesort over the whole module.
 *  - Reminders list + the auto-rule dispatcher:
 *    `company_id = ? AND module_key = ? AND reminder_status IN (…)` and the
 *    per-branch read. `asab_reminders` only had `company_id`.
 *
 * ⚠️ `asab_operations` is a LARGE table on production. Adding a secondary index
 * blocks writes under the default ALGORITHM=COPY, so on MySQL it is added with
 * ALGORITHM=INPLACE, LOCK=NONE (online DDL, MySQL 5.6+). `asab_reminders` has
 * never been written to (nothing produced rows before this release), so it is
 * effectively empty and safe either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('asab_operations', ['company_id', 'module_key', 'operation_date'], 'asab_ops_company_module_date_idx');
        $this->addIndex('asab_reminders', ['company_id', 'module_key', 'reminder_status'], 'asab_reminders_company_module_status_idx');
        $this->addIndex('asab_reminders', ['company_id', 'branch_id'], 'asab_reminders_company_branch_idx');
    }

    public function down(): void
    {
        $this->dropIndex('asab_operations', 'asab_ops_company_module_date_idx');
        $this->dropIndex('asab_reminders', 'asab_reminders_company_module_status_idx');
        $this->dropIndex('asab_reminders', 'asab_reminders_company_branch_idx');
    }

    /** @param  string[]  $columns */
    private function addIndex(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $name)) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            // Online DDL: the table keeps taking writes while the index builds.
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD INDEX `%s` (%s), ALGORITHM=INPLACE, LOCK=NONE',
                $table,
                $name,
                implode(', ', array_map(fn ($c) => "`{$c}`", $columns)),
            ));

            return;
        }

        Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
    }

    private function indexExists(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
