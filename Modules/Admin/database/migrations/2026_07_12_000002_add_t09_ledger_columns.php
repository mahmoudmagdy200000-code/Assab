<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T09 — Employee ledger & cash custody columns (SRS §7 ACC-7/ACC-8 + HEAD-4).
 *
 * - asab_employee_movements: human `ref` (SAL-/ADV-/DED-/SET-) + a compound
 *   index for the per-employee-per-month statement query. `category` already
 *   landed in 2026_07_10_000002.
 * - asab_cash_custody: `min_alert` threshold (default 500,000 halalas = 5,000 SAR,
 *   SRS §4.5) that drives the normal/low/critical status derivation.
 * - asab_cash_transactions: `reason` (persisted on reject) + `source`
 *   (treasury|manual, marks a تعزيز عهدة top-up) + a compound ledger index.
 *
 * Additive only — safe on MySQL and SQLite. Guarded so a partial re-run is inert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_employee_movements', function (Blueprint $t) {
            if (! Schema::hasColumn('asab_employee_movements', 'ref')) {
                $t->string('ref', 32)->nullable()->after('category');
            }
        });
        $this->addIndex('asab_employee_movements', ['employee_id', 'movement_date'], 'asab_emp_mov_emp_date_idx');

        Schema::table('asab_cash_custody', function (Blueprint $t) {
            if (! Schema::hasColumn('asab_cash_custody', 'min_alert')) {
                $t->unsignedBigInteger('min_alert')->default(500000)->after('used');
            }
        });

        Schema::table('asab_cash_transactions', function (Blueprint $t) {
            if (! Schema::hasColumn('asab_cash_transactions', 'reason')) {
                $t->string('reason', 255)->nullable()->after('status');
            }
            if (! Schema::hasColumn('asab_cash_transactions', 'source')) {
                $t->string('source', 16)->nullable()->after('reason'); // treasury|manual
            }
        });
        $this->addIndex('asab_cash_transactions', ['custody_id', 'txn_date'], 'asab_cash_txn_custody_date_idx');
    }

    public function down(): void
    {
        $this->dropIndex('asab_cash_transactions', 'asab_cash_txn_custody_date_idx');
        Schema::table('asab_cash_transactions', function (Blueprint $t) {
            foreach (['reason', 'source'] as $col) {
                if (Schema::hasColumn('asab_cash_transactions', $col)) {
                    $t->dropColumn($col);
                }
            }
        });

        Schema::table('asab_cash_custody', function (Blueprint $t) {
            if (Schema::hasColumn('asab_cash_custody', 'min_alert')) {
                $t->dropColumn('min_alert');
            }
        });

        $this->dropIndex('asab_employee_movements', 'asab_emp_mov_emp_date_idx');
        Schema::table('asab_employee_movements', function (Blueprint $t) {
            if (Schema::hasColumn('asab_employee_movements', 'ref')) {
                $t->dropColumn('ref');
            }
        });
    }

    /** Add a named compound index only if the table lacks it (driver-agnostic). */
    private function addIndex(string $table, array $columns, string $name): void
    {
        if ($this->hasIndex($table, $name)) {
            return;
        }
        Schema::table($table, function (Blueprint $t) use ($columns, $name) {
            $t->index($columns, $name);
        });
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! $this->hasIndex($table, $name)) {
            return;
        }
        Schema::table($table, function (Blueprint $t) use ($name) {
            $t->dropIndex($name);
        });
    }

    private function hasIndex(string $table, string $name): bool
    {
        try {
            // Laravel 11.15+ native introspection — no Doctrine dependency.
            return Schema::hasIndex($table, $name);
        } catch (\Throwable) {
            // Driver can't introspect: assume absent. The add is idempotent-guarded
            // upstream (try/catch on create is unnecessary because a duplicate name
            // only ever surfaces here, where we already checked).
            return false;
        }
    }
};
