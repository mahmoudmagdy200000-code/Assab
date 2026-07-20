<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\Log;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Employee;
use Modules\BrandOwner\Models\BrandOwner as MobileBrandOwner;

/**
 * Cross-world identity map (WS2): the single writer/reader of asab_identity_map.
 * The provisioning services dual-write through the typed helpers; the backfill
 * command seeds it from the existing legacy_* columns and the brand-owner email
 * match. Links only — credential unification is a separate concern.
 */
class IdentityMapService
{
    /**
     * Upsert a link, keyed on (entity_type, dashboard_id) so re-provisioning the
     * same dashboard entity updates rather than duplicates.
     *
     * @throws AsabException IDENTITY_LINK_CONFLICT when a live row already links
     *                       this legacy record to a different dashboard entity.
     *                       Callers that can present a better message (the
     *                       provisioners) check legacyClaimedByOther first; this
     *                       is the backstop that keeps the unique index from
     *                       escaping as a bare 500.
     */
    public function link(
        string $entityType,
        string $dashboardType,
        string $dashboardId,
        string $legacyType,
        string $legacyId,
        ?string $companyId,
        string $matchMethod = 'email',
        ?string $email = null,
        string $source = 'provisioning',
    ): AsabIdentityMap {
        // Neither unique index carries deleted_at, so a soft-deleted row still
        // owns its slot while updateOrCreate — which cannot see it — inserts
        // straight into the index and surfaces as a 500. Clear the dead claim on
        // (entity_type, legacy_id) first; a LIVE claim by another dashboard
        // entity is a real conflict and becomes a 422 below rather than a 500.
        AsabIdentityMap::onlyTrashed()
            ->where('entity_type', $entityType)
            ->where('legacy_id', $legacyId)
            ->where('dashboard_id', '!=', $dashboardId)
            ->forceDelete();

        if ($this->legacyClaimedByOther($entityType, $legacyId, $dashboardId)) {
            throw new AsabException(
                'IDENTITY_LINK_CONFLICT',
                'This legacy record is already linked to a different dashboard entity',
                'هذا السجل مرتبط بالفعل بحساب آخر في لوحة التحكم',
                422,
            );
        }

        $existing = AsabIdentityMap::withTrashed()
            ->where('entity_type', $entityType)
            ->where('dashboard_id', $dashboardId)
            ->first();

        $attributes = [
            'company_id' => $companyId,
            'dashboard_type' => $dashboardType,
            'dashboard_id' => $dashboardId,
            'legacy_type' => $legacyType,
            'legacy_id' => $legacyId,
            'match_method' => $matchMethod,
            'linked_email' => $email,
            'source' => $source,
            'linked_at' => now(),
        ];

        if ($existing === null) {
            return AsabIdentityMap::create($attributes + ['entity_type' => $entityType]);
        }

        if ($existing->trashed()) {
            $existing->restore();
        }

        $existing->forceFill($attributes)->save();

        return $existing;
    }

    /**
     * Drop every link a dashboard entity holds, so deleting and re-creating the
     * user does not leave an orphan claiming its legacy row — which the
     * provisioners' assertUnclaimed guards would then reject as ambiguous.
     * forceDelete, not delete: the unique indexes ignore deleted_at, so a
     * soft-deleted row still blocks the slot it is meant to release.
     */
    public function releaseDashboard(string $dashboardId): void
    {
        AsabIdentityMap::withTrashed()->where('dashboard_id', $dashboardId)->forceDelete();
    }

    public function linkCashier(string $employeeId, string $cashierId, ?string $companyId, ?string $email, string $source = 'provisioning'): void
    {
        $this->link(AsabIdentityMap::ENTITY_CASHIER, 'asab_employee', $employeeId, 'cashier', $cashierId, $companyId, 'email', $email, $source);
    }

    /**
     * Link the commercial record. Nothing stops two asab_suppliers of one
     * company from carrying the same contact_email (no unique index on either
     * asab_suppliers.contact_email or suppliers.email), and the catalog bridge
     * resolves both to the SAME legacy row by that email — so the second link
     * would hit unique(entity_type, legacy_id) and surface as a 500. The first
     * claim wins; the loser keeps its own legacy_supplier_id, which is what the
     * order flow actually reads, so nothing downstream breaks.
     */
    public function linkSupplier(string $asabSupplierId, string $legacySupplierId, ?string $companyId, ?string $email, string $source = 'provisioning'): void
    {
        if ($this->legacyClaimedByOther(AsabIdentityMap::ENTITY_SUPPLIER, $legacySupplierId, $asabSupplierId)) {
            return;
        }

        $this->link(AsabIdentityMap::ENTITY_SUPPLIER, 'asab_supplier', $asabSupplierId, 'supplier', $legacySupplierId, $companyId, 'email', $email, $source);
    }

    public function linkBrandOwner(string $asabUserId, string $legacyOwnerId, ?string $companyId, ?string $email, string $source = 'provisioning'): void
    {
        $this->link(AsabIdentityMap::ENTITY_BRAND_OWNER, 'asab_user', $asabUserId, 'brand_owner', $legacyOwnerId, $companyId, 'email', $email, $source);
    }

    /** Link a supplier's dashboard LOGIN to the legacy row it authenticates against. */
    public function linkSupplierUser(string $asabUserId, string $legacySupplierId, ?string $companyId, ?string $email, string $source = 'provisioning'): void
    {
        $this->link(AsabIdentityMap::ENTITY_SUPPLIER_USER, 'asab_user', $asabUserId, 'supplier', $legacySupplierId, $companyId, 'email', $email, $source);
    }

    public function linkBranchManager(string $asabUserId, string $legacyManagerId, ?string $companyId, ?string $email, string $source = 'provisioning'): void
    {
        $this->link(AsabIdentityMap::ENTITY_BRANCH_MANAGER, 'asab_user', $asabUserId, 'branch_manager', $legacyManagerId, $companyId, 'email', $email, $source);
    }

    /** Whether a DIFFERENT dashboard entity already holds this legacy row under $entityType. */
    public function legacyClaimedByOther(string $entityType, string $legacyId, string $dashboardId): bool
    {
        return AsabIdentityMap::where('entity_type', $entityType)
            ->where('legacy_id', $legacyId)
            ->where('dashboard_id', '!=', $dashboardId)
            ->exists();
    }

    /** Resolve the legacy mobile id for a dashboard entity. */
    public function legacyIdFor(string $entityType, string $dashboardId): ?string
    {
        return AsabIdentityMap::where('entity_type', $entityType)->where('dashboard_id', $dashboardId)->value('legacy_id');
    }

    /** Resolve the dashboard id for a legacy mobile row (reverse lookup). */
    public function dashboardIdFor(string $entityType, string $legacyId): ?string
    {
        return AsabIdentityMap::where('entity_type', $entityType)->where('legacy_id', $legacyId)->value('dashboard_id');
    }

    /**
     * Seed the map from existing linkage. Idempotent (updateOrCreate). Cashier
     * and supplier come from their stored legacy_* ids; brand-owner has no stored
     * cross-world id, so it is matched by the shared email (flagged match_method).
     * A legacy_id already claimed by another dashboard row is skipped, not fatal.
     *
     * @return array{cashier:int, supplier:int, brand_owner:int, skipped:int}
     */
    public function backfill(): array
    {
        $counts = ['cashier' => 0, 'supplier' => 0, 'brand_owner' => 0, 'skipped' => 0];

        Employee::withoutGlobalScopes()->whereNotNull('legacy_cashier_id')
            ->select('id', 'legacy_cashier_id', 'company_id')
            ->chunkById(500, function ($rows) use (&$counts) {
                foreach ($rows as $e) {
                    $this->guard(fn () => $this->linkCashier($e->id, $e->legacy_cashier_id, $e->company_id, null, 'backfill'), $counts, 'cashier');
                }
            });

        AsabSupplier::withoutGlobalScopes()->whereNotNull('legacy_supplier_id')
            ->select('id', 'legacy_supplier_id', 'company_id', 'contact_email')
            ->chunkById(500, function ($rows) use (&$counts) {
                foreach ($rows as $s) {
                    $this->guard(fn () => $this->linkSupplier($s->id, $s->legacy_supplier_id, $s->company_id, $s->contact_email, 'backfill'), $counts, 'supplier');
                }
            });

        MobileBrandOwner::withTrashed()->whereNotNull('email')
            ->select('id', 'email')
            ->chunkById(500, function ($rows) use (&$counts) {
                foreach ($rows as $o) {
                    $user = AsabUser::where('email', $o->email)->first(['id', 'company_id']);
                    if ($user === null) {
                        continue;
                    }
                    $this->guard(fn () => $this->linkBrandOwner($user->id, $o->id, $user->company_id, $o->email, 'backfill'), $counts, 'brand_owner');
                }
            });

        return $counts;
    }

    /** Run one link, counting success or skipping a unique-clash without aborting. */
    private function guard(callable $fn, array &$counts, string $key): void
    {
        try {
            $fn();
            $counts[$key]++;
        } catch (\Throwable $e) {
            $counts['skipped']++;
            Log::warning('identity-map backfill skipped a '.$key.' link: '.$e->getMessage());
        }
    }
}
