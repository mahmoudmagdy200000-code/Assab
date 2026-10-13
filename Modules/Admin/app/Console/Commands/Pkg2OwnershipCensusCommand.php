<?php

namespace Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Services\MobileBranchScopeService;
use Modules\BrandOwner\Models\BrandManager;

/**
 * Read-only PKG-2 ownership / authorization census (FU-02 + D-2).
 *
 * Safe to run anywhere (SELECT only — no writes, no migrations). On a
 * production copy it is the pre-deploy preflight: it surfaces the historical
 * remediation items that FU-02 and D-2 deliberately leave fail-closed rather
 * than guess.
 *
 * It NEVER mutates data and NEVER infers a tenant from a manager's current
 * branch. Rows it flags need an explicit, separately-approved remediation.
 */
class Pkg2OwnershipCensusCommand extends Command
{
    protected $signature = 'asab:pkg2-census {--json : emit machine-readable JSON}';

    protected $description = 'Read-only census of expense origin ownership (FU-02) and brand-manager delegation (D-2).';

    public function handle(): int
    {
        $expensesTotal = DB::table('expenses')->whereNull('deleted_at')->count();

        // FU-02: legacy rows with no immutable origin captured. These are NOT
        // attributed to any brand by the readers (fail closed) until an explicit
        // backfill policy is approved — never guessed from the current manager.
        $nullOrigin = DB::table('expenses')->whereNull('deleted_at')->whereNull('origin_branch_id')->count();

        // FU-02: rows stamped with an origin BRANCH but no origin BRAND/company
        // (created at a branch that was unlinked from the dashboard hierarchy at
        // the time). These are brand-INVISIBLE (brand readers key on origin brand),
        // so surface them distinctly from fully unstamped rows.
        $partialOrigin = DB::table('expenses')
            ->whereNull('deleted_at')
            ->whereNotNull('origin_branch_id')
            ->where(fn ($q) => $q->whereNull('origin_asab_brand_id')->orWhereNull('origin_asab_company_id'))
            ->count();

        // Expenses whose manager has since MOVED (origin differs from the
        // manager's current branch). With FU-02 these stay with their origin;
        // the count shows how many would have been mis-attributed pre-fix.
        $movedManager = DB::table('expenses as e')
            ->join('branch_managers as bm', 'e.branch_manager_id', '=', 'bm.id')
            ->whereNull('e.deleted_at')
            ->whereNotNull('e.origin_branch_id')
            ->whereColumn('e.origin_branch_id', '!=', 'bm.branch_id')
            ->count();

        $deletedManager = DB::table('expenses as e')
            ->join('branch_managers as bm', 'e.branch_manager_id', '=', 'bm.id')
            ->whereNull('e.deleted_at')
            ->whereNotNull('bm.deleted_at')
            ->count();

        // D-2: mobile brand managers WITHOUT an explicit brand_manager delegation.
        // Before D-2 these could resolve brands via the same-email owner fallback;
        // that fallback is retired, so they now fail closed until granted.
        $managersTotal = DB::table('brand_managers')->whereNull('deleted_at')->count();
        $explicitIds = AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_BRAND_MANAGER)
            ->pluck('legacy_id')->all();
        $managersWithoutAssignment = DB::table('brand_managers')
            ->whereNull('deleted_at')
            ->when($explicitIds !== [], fn ($q) => $q->whereNotIn('id', $explicitIds))
            ->count();

        // D-2: delegations that EXIST (a live brand_manager map) but resolve to an
        // EMPTY brand set — a disabled/missing dashboard user, no active
        // brand-manager role, an empty or wholly cross-company brand set, or a
        // soft-deleted/orphaned manager. These look "assigned" by map existence
        // yet grant nothing, so flag them through the live resolver (no tenant
        // inference, no mutation).
        $scope = app(MobileBranchScopeService::class);
        $ineffectiveDelegations = 0;
        if ($explicitIds !== []) {
            $present = [];
            foreach (BrandManager::whereIn('id', $explicitIds)->get() as $manager) {
                $present[] = $manager->id;
                if ($scope->visibleBrandIds($manager) === []) {
                    $ineffectiveDelegations++;
                }
            }
            // Live maps whose manager row is missing/soft-deleted are orphaned
            // delegations — equally ineffective.
            $ineffectiveDelegations += count(array_diff($explicitIds, $present));
        }

        // F-02 governance: brand-manager ROLE rows not backed by a live
        // brand_manager delegation (the dashboard user is not the mapped user of
        // any current delegation). On a clean D-2 reassignment the old user's role
        // is removed in-transaction, so these are historical / out-of-band /
        // pre-D-2 residue. They are SURFACED for governance, never auto-deleted
        // here — reconciling them against S1-12 M03 role provenance (a role must
        // survive if an independent legitimate source still holds it) is a separate
        // approved step, not a census mutation.
        $liveDelegationUserIds = AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_BRAND_MANAGER)
            ->whereNotNull('dashboard_id')
            ->pluck('dashboard_id')
            ->all();
        $orphanedRoles = DB::table('asab_user_roles')
            ->where('role_key', 'brand-manager')
            ->when($liveDelegationUserIds !== [], fn ($q) => $q->whereNotIn('user_id', $liveDelegationUserIds))
            ->count();

        $report = [
            'expenses_total' => $expensesTotal,
            'fu02_expenses_without_origin' => $nullOrigin,
            'fu02_expenses_partial_origin' => $partialOrigin,
            'fu02_expenses_manager_moved' => $movedManager,
            'fu02_expenses_deleted_manager' => $deletedManager,
            'd2_brand_managers_total' => $managersTotal,
            'd2_brand_managers_without_explicit_assignment' => $managersWithoutAssignment,
            'd2_brand_managers_ineffective_delegation' => $ineffectiveDelegations,
            'd2_orphaned_brand_manager_roles' => $orphanedRoles,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('PKG-2 ownership / authorization census (read-only)');
        foreach ($report as $k => $v) {
            $this->line(sprintf('  %-48s %d', $k, $v));
        }

        if ($nullOrigin > 0) {
            $this->warn("FU-02: {$nullOrigin} expense(s) have no immutable origin — fail-closed until an approved backfill. Do NOT backfill from the manager's current branch.");
        }
        if ($partialOrigin > 0) {
            $this->warn("FU-02: {$partialOrigin} expense(s) have an origin branch but no origin brand/company — brand-invisible until an approved remediation.");
        }
        if ($managersWithoutAssignment > 0) {
            $this->warn("D-2: {$managersWithoutAssignment} brand manager(s) have no explicit delegation — they now fail closed. Grant via 'asab:brand-manager grant' before relying on them.");
        }
        if ($ineffectiveDelegations > 0) {
            $this->warn("D-2: {$ineffectiveDelegations} brand manager(s) have a delegation that resolves to NO brands (disabled user, no/empty role, cross-company, or orphaned) — review before relying on them.");
        }
        if ($orphanedRoles > 0) {
            $this->warn("D-2: {$orphanedRoles} brand-manager role row(s) are not backed by a live delegation (historical/out-of-band). GOVERN these — reconcile against S1-12 M03 role provenance before any cleanup; do not auto-delete.");
        }

        return self::SUCCESS;
    }
}
