<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T10.5 — ERP batch conformance (SRS §14.3 ERP-1). Batches are now grouped per
 * (day × module_key) and move through ready → exported | failed. Adds:
 *   - module_key   the batch's module (one batch per module per day)
 *   - batch_date   the operation day the batch groups (for EXP-YYYY-MM-DD-nnn)
 *   - ready_at     when the batch became ready (a final-approve seeded it)
 *   - approved_by_id  the head who final-approved into the ready batch
 * plus a lookup index for the ready-batch upsert. Additive, SQLite+MySQL safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asab_erp_batches', function (Blueprint $t) {
            if (! Schema::hasColumn('asab_erp_batches', 'module_key')) {
                $t->string('module_key', 32)->nullable()->after('company_id');
            }
            if (! Schema::hasColumn('asab_erp_batches', 'batch_date')) {
                $t->date('batch_date')->nullable()->after('module_key');
            }
            if (! Schema::hasColumn('asab_erp_batches', 'ready_at')) {
                $t->timestamp('ready_at')->nullable()->after('started_at');
            }
            if (! Schema::hasColumn('asab_erp_batches', 'approved_by_id')) {
                $t->uuid('approved_by_id')->nullable()->after('initiated_by_id');
            }
        });

        if (! $this->hasIndex('asab_erp_batches', 'asab_erp_batches_ready_lookup_idx')) {
            Schema::table('asab_erp_batches', function (Blueprint $t) {
                $t->index(['company_id', 'module_key', 'batch_date', 'status'], 'asab_erp_batches_ready_lookup_idx');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('asab_erp_batches', 'asab_erp_batches_ready_lookup_idx')) {
            Schema::table('asab_erp_batches', function (Blueprint $t) {
                $t->dropIndex('asab_erp_batches_ready_lookup_idx');
            });
        }
        Schema::table('asab_erp_batches', function (Blueprint $t) {
            foreach (['module_key', 'batch_date', 'ready_at', 'approved_by_id'] as $col) {
                if (Schema::hasColumn('asab_erp_batches', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }

    private function hasIndex(string $table, string $name): bool
    {
        try {
            return Schema::hasIndex($table, $name);
        } catch (\Throwable) {
            return false;
        }
    }
};
