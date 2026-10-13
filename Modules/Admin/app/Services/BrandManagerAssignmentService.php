<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;

/**
 * D-2 provisioning: create / change / revoke an EXPLICIT brand-manager delegation.
 *
 * The live authorization source read by MobileBranchScopeService is the pair
 * (brand_manager identity map, brand-manager role). This service is the only
 * sanctioned writer of that pair, and it keeps an append-only audit trail.
 *
 * It does NOT decide WHO may grant — that authority (which actor, which approval)
 * is a business decision and is enforced by the caller (an admin artisan command
 * today; a future owner-facing endpoint once the grantor rule is approved). The
 * service fails closed on a cross-company brand and never silently widens scope.
 */
class BrandManagerAssignmentService
{
    /**
     * Grant or change a manager's delegated brand set. Idempotent per manager
     * (one identity map + one brand-manager role), and every call appends audit.
     *
     * @param  string[]  $brandIds  brands to delegate; all must belong to the dashboard user's company
     */
    public function grant(string $brandManagerId, AsabUser $dashboardUser, array $brandIds, ?string $grantedBy = null): void
    {
        // A disabled dashboard account may not carry a delegation (fail closed).
        if ($dashboardUser->status !== 'active') {
            throw new \RuntimeException('BRAND_MANAGER_INACTIVE_DASHBOARD_USER');
        }

        $requested = array_values(array_unique(array_filter($brandIds)));

        DB::transaction(function () use ($brandManagerId, $dashboardUser, $requested, $grantedBy): void {
            // Company consistency, fail closed: refuse any brand outside the
            // dashboard user's company rather than silently dropping it.
            $valid = AsabBrand::whereIn('id', $requested)
                ->where('company_id', $dashboardUser->company_id)
                ->pluck('id')
                ->all();

            if (count($valid) !== count($requested)) {
                throw new \RuntimeException('BRAND_MANAGER_CROSS_COMPANY_BRAND');
            }

            // Was there an ACTIVE (non-trashed) delegation? Drives grant vs change
            // in the audit. A previously revoked (soft-deleted) map is restored,
            // not re-inserted (R-02) — the unique (entity_type, legacy_id) /
            // (entity_type, dashboard_id) indexes include soft-deleted rows, so a
            // plain create would hit a duplicate-key violation on re-grant.
            $activeExisted = AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_BRAND_MANAGER)
                ->where('legacy_id', $brandManagerId)
                ->exists();

            $map = AsabIdentityMap::withTrashed()->firstOrNew([
                'entity_type' => AsabIdentityMap::ENTITY_BRAND_MANAGER,
                'legacy_id' => $brandManagerId,
            ]);
            // F-02: the dashboard user this delegation pointed at BEFORE this call.
            // If we are repointing the manager to a different dashboard user, the
            // old user's brand-manager role is now orphaned and must be removed in
            // the same transaction — otherwise a stale grant lingers on the old user
            // (invisible to the mobile resolver, but still a live dashboard role).
            $previousDashboardId = $map->dashboard_id;
            $map->fill([
                'company_id' => $dashboardUser->company_id,
                'dashboard_type' => 'asab_user',
                'dashboard_id' => $dashboardUser->id,
                'legacy_type' => 'brand_manager',
                'match_method' => 'explicit',
                'source' => 'provisioning',
                'linked_at' => now(),
            ]);
            if ($map->trashed()) {
                $map->deleted_at = null; // reactivate the revoked delegation in place
            }
            $map->save();

            // F-02: drop the orphaned brand-manager role on the previously-mapped
            // user when the delegation is repointed to a different one.
            //
            // Safe for the current D-2 model: this service is the SOLE writer of a
            // `brand-manager` AsabUserRole (BrandManagerAuthService only mints a
            // token; no other path creates one), so U1's role here has no
            // independent source to preserve. S1-12 M03 INTEGRATION DEPENDENCY:
            // once S1-12 can grant a brand-manager role from an independent source,
            // this blanket delete must become provenance-aware (keep a role that a
            // legitimate independent source still holds). Pre-existing / out-of-band
            // role rows are governed read-only by `asab:pkg2-census`
            // (d2_orphaned_brand_manager_roles), never auto-deleted.
            if ($previousDashboardId !== null && $previousDashboardId !== $dashboardUser->id) {
                AsabUserRole::where('user_id', $previousDashboardId)
                    ->where('role_key', 'brand-manager')
                    ->delete();
            }

            $role = AsabUserRole::firstOrNew([
                'user_id' => $dashboardUser->id,
                'role_key' => 'brand-manager',
            ]);
            $role->scope = 'brand';
            $role->brand_ids = $valid;
            $role->save();

            $this->audit($brandManagerId, $dashboardUser->company_id, $dashboardUser->id, $valid, $activeExisted ? 'change' : 'grant', $grantedBy);
        });
    }

    /**
     * Revoke a manager's delegation entirely — deletes the identity map and the
     * brand-manager role so the next request resolves no brands (immediate,
     * no token/cache dependency). Appends a revoke audit row.
     */
    public function revoke(string $brandManagerId, ?string $revokedBy = null): void
    {
        DB::transaction(function () use ($brandManagerId, $revokedBy): void {
            $map = AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_BRAND_MANAGER)
                ->where('legacy_id', $brandManagerId)
                ->first();

            $dashboardUserId = $map?->dashboard_id;
            $companyId = $map?->company_id;

            if ($dashboardUserId) {
                AsabUserRole::where('user_id', $dashboardUserId)->where('role_key', 'brand-manager')->delete();
            }
            if ($map) {
                $map->delete();
            }

            $this->audit($brandManagerId, $companyId, $dashboardUserId, [], 'revoke', $revokedBy);
        });
    }

    private function audit(string $brandManagerId, ?string $companyId, ?string $dashboardUserId, array $brandIds, string $action, ?string $grantedBy): void
    {
        DB::table('asab_brand_manager_assignment_audit')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'brand_manager_id' => $brandManagerId,
            'dashboard_user_id' => $dashboardUserId,
            'brand_ids' => json_encode(array_values($brandIds)),
            'action' => $action,
            'granted_by' => $grantedBy,
            'created_at' => now(),
        ]);
    }
}
