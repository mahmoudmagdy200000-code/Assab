<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add supersedes_allocation_id to shift_liability_allocations
        if (Schema::hasTable('shift_liability_allocations') && ! Schema::hasColumn('shift_liability_allocations', 'supersedes_allocation_id')) {
            Schema::table('shift_liability_allocations', function (Blueprint $table) {
                $table->foreignUuid('supersedes_allocation_id')
                    ->nullable()
                    ->after('superseded_at')
                    ->constrained('shift_liability_allocations')
                    ->nullOnDelete();
            });
        }

        // 2. Add lineage_key to shift_liability_shares
        if (Schema::hasTable('shift_liability_shares') && ! Schema::hasColumn('shift_liability_shares', 'lineage_key')) {
            Schema::table('shift_liability_shares', function (Blueprint $table) {
                $table->string('lineage_key', 191)->nullable()->after('responsible_id')->index('sls_lineage_idx');
            });
        }

        // 3. Create shift_liability_posting_states
        if (! Schema::hasTable('shift_liability_posting_states')) {
            Schema::create('shift_liability_posting_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id')->index('slps_company_idx');
                $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
                $table->foreignUuid('cashier_shift_id')->constrained('cashier_shifts')->restrictOnDelete();
                $table->string('responsible_type', 32);
                $table->uuid('responsible_id');
                $table->string('lineage_key', 191)->unique('slps_lineage_unique');
                $table->uuid('last_evidence_id')->nullable();
                $table->bigInteger('last_approved_target_halalas')->default(0);
                $table->timestamps();

                $table->index(['cashier_shift_id', 'responsible_type', 'responsible_id'], 'slps_shift_responsible_idx');
            });
        }

        // 4. Create shift_liability_postings journal
        if (! Schema::hasTable('shift_liability_postings')) {
            Schema::create('shift_liability_postings', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id')->index('slp_company_idx');
                $table->uuid('branch_id')->index('slp_branch_idx');
                $table->foreignUuid('cashier_shift_id')->constrained('cashier_shifts')->restrictOnDelete();
                $table->string('lineage_key', 191)->index('slp_lineage_idx');
                $table->string('responsible_type', 32);
                $table->uuid('responsible_id');
                $table->bigInteger('delta_halalas'); // signed: positive = debit/additional liability, negative = credit/reversal
                $table->bigInteger('target_halalas');
                $table->bigInteger('effective_before_halalas');
                $table->foreignUuid('allocation_id')->nullable()->constrained('shift_liability_allocations')->nullOnDelete();
                $table->uuid('review_id')->nullable();
                $table->uuid('evidence_id')->nullable();
                $table->string('operation_id', 191)->index('slp_op_id_idx');
                $table->string('actor_type', 64);
                $table->uuid('actor_id');
                $table->string('ledger_table', 64);
                $table->string('ledger_id', 64);
                $table->string('status', 32)->default('posted');
                $table->timestamps();

                $table->index(['lineage_key', 'created_at'], 'slp_lineage_time_idx');
            });
        }

        // 5. Create shift_liability_legacy_posting_links
        if (! Schema::hasTable('shift_liability_legacy_posting_links')) {
            Schema::create('shift_liability_legacy_posting_links', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id')->index('sllpl_company_idx');
                $table->foreignUuid('cashier_shift_id')->constrained('cashier_shifts')->restrictOnDelete();
                $table->string('responsible_type', 32);
                $table->uuid('responsible_id');
                $table->string('lineage_key', 191)->index('sllpl_lineage_idx');
                $table->string('ledger_table', 64);
                $table->string('ledger_id', 64);
                $table->bigInteger('signed_halalas');
                $table->text('provenance')->nullable();
                $table->timestamps();

                $table->unique(['ledger_table', 'ledger_id'], 'sllpl_ledger_unique');
            });
        }

        // 6. Metadata columns on ledger tables
        if (Schema::hasTable('asab_employee_movements')) {
            Schema::table('asab_employee_movements', function (Blueprint $table) {
                if (! Schema::hasColumn('asab_employee_movements', 'lineage_key')) {
                    $table->string('lineage_key', 191)->nullable()->index('aem_lineage_idx');
                }
                if (! Schema::hasColumn('asab_employee_movements', 'posting_id')) {
                    $table->uuid('posting_id')->nullable()->unique('aem_posting_unique');
                }
            });
        }

        if (Schema::hasTable('cashier_custody_transactions')) {
            Schema::table('cashier_custody_transactions', function (Blueprint $table) {
                if (! Schema::hasColumn('cashier_custody_transactions', 'category')) {
                    $table->string('category', 64)->nullable()->index('cct_category_idx');
                }
                if (! Schema::hasColumn('cashier_custody_transactions', 'lineage_key')) {
                    $table->string('lineage_key', 191)->nullable()->index('cct_lineage_idx');
                }
                if (! Schema::hasColumn('cashier_custody_transactions', 'posting_id')) {
                    $table->uuid('posting_id')->nullable()->unique('cct_posting_unique');
                }
            });
        }

        if (Schema::hasTable('personal_ledger_transactions')) {
            Schema::table('personal_ledger_transactions', function (Blueprint $table) {
                if (! Schema::hasColumn('personal_ledger_transactions', 'category')) {
                    $table->string('category', 64)->nullable()->index('plt_category_idx');
                }
                if (! Schema::hasColumn('personal_ledger_transactions', 'lineage_key')) {
                    $table->string('lineage_key', 191)->nullable()->index('plt_lineage_idx');
                }
                if (! Schema::hasColumn('personal_ledger_transactions', 'posting_id')) {
                    $table->uuid('posting_id')->nullable()->unique('plt_posting_unique');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('shift_liability_postings') && DB::table('shift_liability_postings')->exists()) {
            throw new LogicException('CANNOT_ROLLBACK_POSTED_LIABILITIES_WITH_FINANCIAL_HISTORY');
        }

        if (Schema::hasTable('shift_liability_legacy_posting_links') && DB::table('shift_liability_legacy_posting_links')->exists()) {
            throw new LogicException('CANNOT_ROLLBACK_LEGACY_LIABILITY_LINKS_WITH_FINANCIAL_HISTORY');
        }

        Schema::dropIfExists('shift_liability_legacy_posting_links');
        Schema::dropIfExists('shift_liability_postings');
        Schema::dropIfExists('shift_liability_posting_states');

        if (Schema::hasTable('shift_liability_shares') && Schema::hasColumn('shift_liability_shares', 'lineage_key')) {
            Schema::table('shift_liability_shares', function (Blueprint $table) {
                $table->dropColumn('lineage_key');
            });
        }

        if (Schema::hasTable('shift_liability_allocations') && Schema::hasColumn('shift_liability_allocations', 'supersedes_allocation_id')) {
            Schema::table('shift_liability_allocations', function (Blueprint $table) {
                $table->dropForeign(['supersedes_allocation_id']);
                $table->dropColumn('supersedes_allocation_id');
            });
        }

        if (Schema::hasTable('asab_employee_movements')) {
            Schema::table('asab_employee_movements', function (Blueprint $table) {
                if (Schema::hasColumn('asab_employee_movements', 'posting_id')) {
                    $table->dropColumn('posting_id');
                }
                if (Schema::hasColumn('asab_employee_movements', 'lineage_key')) {
                    $table->dropColumn('lineage_key');
                }
            });
        }

        if (Schema::hasTable('cashier_custody_transactions')) {
            Schema::table('cashier_custody_transactions', function (Blueprint $table) {
                if (Schema::hasColumn('cashier_custody_transactions', 'posting_id')) {
                    $table->dropColumn('posting_id');
                }
                if (Schema::hasColumn('cashier_custody_transactions', 'lineage_key')) {
                    $table->dropColumn('lineage_key');
                }
                if (Schema::hasColumn('cashier_custody_transactions', 'category')) {
                    $table->dropColumn('category');
                }
            });
        }

        if (Schema::hasTable('personal_ledger_transactions')) {
            Schema::table('personal_ledger_transactions', function (Blueprint $table) {
                if (Schema::hasColumn('personal_ledger_transactions', 'posting_id')) {
                    $table->dropColumn('posting_id');
                }
                if (Schema::hasColumn('personal_ledger_transactions', 'lineage_key')) {
                    $table->dropColumn('lineage_key');
                }
                if (Schema::hasColumn('personal_ledger_transactions', 'category')) {
                    $table->dropColumn('category');
                }
            });
        }
    }
};
