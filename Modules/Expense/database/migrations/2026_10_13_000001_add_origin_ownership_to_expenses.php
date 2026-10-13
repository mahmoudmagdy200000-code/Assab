<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FU-02: immutable originating ownership for an expense.
 *
 * The tenant of an expense used to be resolved live through
 * expenses.branch_manager_id -> branch_managers.branch_id, which
 * ManagerBranchSyncService can repoint — so a historic expense could migrate to
 * another branch/brand/company when its manager was reassigned. These additive,
 * nullable columns capture the branch/brand/company that OWNED the expense when
 * it was created; readers scope by them instead of the manager's current branch.
 *
 * No foreign keys: the snapshot must survive even if the originating branch is
 * later removed or re-tagged. Additive and reversible (down drops only these
 * columns). Legacy rows are intentionally left NULL — there is no durable
 * creation-time branch evidence to backfill from, and blindly using the
 * manager's CURRENT branch would reintroduce the very defect. Legacy NULL-origin
 * rows are handled fail-closed by the readers and reported as a historical
 * remediation gate (see the census command); they are NOT silently converted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->uuid('origin_branch_id')->nullable()->after('branch_manager_id');
            $table->uuid('origin_asab_brand_id')->nullable()->after('origin_branch_id');
            $table->uuid('origin_asab_company_id')->nullable()->after('origin_asab_brand_id');

            $table->index('origin_branch_id');
            $table->index('origin_asab_brand_id');
            $table->index('origin_asab_company_id');
        });
    }

    public function down(): void
    {
        // R-04: these columns hold IMMUTABLE financial ownership. Dropping them
        // once any row is stamped is a destructive, irreversible loss of that
        // ownership, so refuse the rollback when origin data exists — remediate
        // forward (a new migration) instead. A fresh/empty install rolls back
        // cleanly.
        if (Schema::hasColumn('expenses', 'origin_branch_id')) {
            $populated = DB::table('expenses')
                ->whereNotNull('origin_branch_id')
                ->orWhereNotNull('origin_asab_brand_id')
                ->orWhereNotNull('origin_asab_company_id')
                ->exists();

            if ($populated) {
                throw new \RuntimeException(
                    'Refusing to roll back add_origin_ownership_to_expenses: expenses carry '
                    .'immutable origin ownership that would be lost. Remediate with a forward migration instead.'
                );
            }
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['origin_branch_id']);
            $table->dropIndex(['origin_asab_brand_id']);
            $table->dropIndex(['origin_asab_company_id']);
            $table->dropColumn(['origin_branch_id', 'origin_asab_brand_id', 'origin_asab_company_id']);
        });
    }
};
