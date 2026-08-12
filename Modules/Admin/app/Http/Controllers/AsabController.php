<?php

namespace Modules\Admin\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Support\AsabResponse;

/**
 * Base controller for every ASAB endpoint. Thin HTTP layer only — emits the
 * spec-exact envelopes via AsabResponse. Business logic lives in Services.
 */
abstract class AsabController extends Controller
{
    use AsabResponse, AuthorizesRequests;

    /**
     * Run a controller action, translating domain/validation/not-found errors
     * into the spec's error envelope (BACKEND_API_SPEC.md §2).
     */
    protected function run(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (AsabException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->messageAr, $e->details, $e->status);
        } catch (ValidationException $e) {
            return $this->fail('VALIDATION_ERROR', 'Validation failed', 'فشل التحقق من البيانات', $e->errors(), 422);
        } catch (ModelNotFoundException $e) {
            return $this->fail('NOT_FOUND', 'Resource not found', 'العنصر غير موجود', [], 404);
        }
    }

    /**
     * Constrain an Operation (or other branch_id-keyed) query to the branches
     * of the current user's role assignment, below the company_id global scope.
     * Zero-trust: a scoped accountant sees only operations from their assigned
     * brand/restaurant/branch tree; company-wide roles are unaffected.
     */
    protected function scopeToAssignedBranches($query)
    {
        $branchIds = $this->assignedBranchIds();

        if ($branchIds !== null) {
            $query->whereIn('branch_id', $branchIds);
        }

        return $query;
    }

    /**
     * Extra branch constraint for tenant-scoped (company_id global scope)
     * queries. null = no extra constraint (admin or a company-wide assignment).
     *
     * @return string[]|null
     */
    protected function assignedBranchIds(): ?array
    {
        return app(\Modules\Admin\Services\TenantBranchResolver::class)
            ->operationBranchIds(app(\Modules\Admin\Support\TenantContext::class));
    }

    /**
     * Assert a branch id (path/query/body param) is inside the caller's
     * assigned branch scope. Out-of-scope ids read as absent resources —
     * the same 404 envelope a scoped firstOrFail produces. null branch ids
     * fail closed for non-admin users. Uses legacyBranchIds (not
     * operationBranchIds): the guarded tables carry no tenant scope, so
     * scope=all users must still be pinned to their own company's branches;
     * only the platform admin is unrestricted.
     */
    protected function assertBranchAssigned(?string $branchId): void
    {
        $branchIds = app(\Modules\Admin\Services\TenantBranchResolver::class)
            ->legacyBranchIds(app(\Modules\Admin\Support\TenantContext::class));

        if ($branchIds !== null && ! in_array($branchId, $branchIds, true)) {
            throw (new ModelNotFoundException)->setModel(\Modules\Branch\Models\Branch::class);
        }
    }

    /**
     * Company ids the caller may read. null = platform admin (unrestricted).
     *
     * Almost always exactly one — but a brand carries a company of its own, so
     * an accountant assigned brands (or restaurants) beyond their own company
     * covers each of those companies too. Use this instead of
     * `$request->user()->company_id` in any WHERE clause, or that accountant's
     * second brand silently disappears from the screen.
     *
     * @return string[]|null
     */
    protected function tenantCompanyIds(): ?array
    {
        $ctx = app(\Modules\Admin\Support\TenantContext::class);

        return $ctx->isAdmin ? null : $ctx->companyIds();
    }

    /**
     * Company ids to hand a service that filters by company_id. Never null: an
     * admin on a tenant surface keeps their own (absent) company, which is
     * exactly what `$request->user()->company_id` did before.
     *
     * @return array<int, string|null>
     */
    protected function tenantCompanyIdsFor(mixed $user): array
    {
        return $this->tenantCompanyIds() ?? [$user?->company_id];
    }

    /** Constrain a company_id-keyed query to tenantCompanyIds(). */
    protected function scopeToTenantCompanies($query, string $column = 'company_id')
    {
        $companyIds = $this->tenantCompanyIds();

        if ($companyIds !== null) {
            $query->whereIn($column, $companyIds);
        }

        return $query;
    }

    /**
     * Brand ids the caller may touch. null = platform admin (unrestricted);
     * scope=all → every company brand; scoped → brands of the assigned branch
     * tree plus directly assigned brand ids. Fail-closed (empty array) when
     * nothing resolves.
     *
     * @return string[]|null
     */
    protected function assignedBrandIds(): ?array
    {
        $ctx = app(\Modules\Admin\Support\TenantContext::class);
        if ($ctx->isAdmin) {
            return null;
        }
        if (! $ctx->hasTenant()) {
            return [];
        }

        $companyBrandIds = \Modules\Admin\Models\AsabBrand::query()
            ->whereIn('company_id', $ctx->companyIds())->pluck('id')->all();
        if ($ctx->scope === 'all') {
            return $companyBrandIds;
        }

        $branchIds = app(\Modules\Admin\Services\TenantBranchResolver::class)->legacyBranchIds($ctx) ?? [];
        $fromBranches = \Modules\Branch\Models\Branch::query()
            ->whereIn('id', $branchIds)->whereNotNull('asab_brand_id')->pluck('asab_brand_id')->all();

        // Restaurant-scoped accountants whose branches were never brand-linked
        // (asab_brand_id NULL) used to resolve to [] and every scoped read
        // failed closed to empty — derive the brand through the restaurant too.
        $fromRestaurants = $ctx->restaurantIds === []
            ? []
            : \Modules\Admin\Models\AsabRestaurant::withoutGlobalScopes()
                ->whereIn('id', $ctx->restaurantIds)
                ->whereNotNull('brand_id')
                ->pluck('brand_id')
                ->all();

        return array_values(array_unique(array_merge(
            $fromBranches,
            array_intersect($fromRestaurants, $companyBrandIds),
            array_intersect($ctx->brandIds, $companyBrandIds),
        )));
    }

    /** Brand counterpart of assertBranchAssigned — same fail-closed 404 contract. */
    protected function assertBrandAssigned(?string $brandId): void
    {
        $brandIds = $this->assignedBrandIds();

        if ($brandIds !== null && ! in_array($brandId, $brandIds, true)) {
            throw (new ModelNotFoundException)->setModel(\Modules\Admin\Models\AsabBrand::class);
        }
    }
}
