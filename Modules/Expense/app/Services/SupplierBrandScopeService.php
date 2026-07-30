<?php

namespace Modules\Expense\Services;

use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabSupplier;
use Modules\Branch\Models\Branch;

/**
 * Brand-scoped supplier visibility for the mobile world (meeting 2026-07-29):
 * the expense supplier picker must list ONLY the suppliers of the caller's own
 * brand — never the global `suppliers` table — and a submitted expense must not
 * reference a supplier outside that set.
 *
 * The link already exists on the dashboard side: every supplier the admin
 * uploads/creates carries `asab_suppliers.brand_id` / `company_id` and a
 * `legacy_supplier_id` pointing at the mobile `suppliers` row
 * (UploadController::importSupplierRow → ProcurementCatalogBridgeService).
 * This service only READS that link; it never writes.
 *
 * Resolution is fail-closed: a branch that cannot be resolved to an ASAB
 * brand/company sees an EMPTY supplier list (same convention as the branch
 * pickers, FE-cashier-mobile-only-2026-07-29 §3) — not every tenant's rows.
 * Brand tags are derived read-only the same way BranchHierarchyLinker does
 * (branch → restaurant → brand → company), without the heal-write, because
 * this runs on hot GET paths.
 */
class SupplierBrandScopeService
{
    /**
     * Legacy `suppliers.id` values visible to a branch: the brand's own
     * suppliers plus company-wide rows not pinned to any brand.
     *
     * @return string[] empty when the branch resolves to no ASAB brand/company
     */
    public function visibleSupplierIds(?string $branchId): array
    {
        [$brandId, $companyId] = $this->resolveScope($branchId);

        if ($brandId === null && $companyId === null) {
            return [];
        }

        // Mobile callers carry no ASAB tenant context, so the tenant global
        // scope must be dropped and the company pinned explicitly (same as
        // ExpenseBridgeService / ProcurementCatalogBridgeService).
        return AsabSupplier::withoutGlobalScope('tenant')
            ->whereNotNull('legacy_supplier_id')
            ->where(function ($q) use ($brandId, $companyId) {
                if ($brandId !== null) {
                    $q->where('brand_id', $brandId);
                    if ($companyId !== null) {
                        $q->orWhere(fn ($w) => $w->whereNull('brand_id')->where('company_id', $companyId));
                    }
                } else {
                    $q->where('company_id', $companyId);
                }
            })
            ->pluck('legacy_supplier_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Suppliers the ORDER flow may offer (meeting 2026-07-30): the brand's
     * visible suppliers narrowed to those with a real supplier ACCOUNT — a
     * mobile login that can receive and act on purchase orders. Excel-uploaded
     * expense-only suppliers are provisioned without an email/login and must
     * not appear in the order picker.
     *
     * @return string[]
     */
    public function orderableSupplierIds(?string $branchId): array
    {
        $visible = $this->visibleSupplierIds($branchId);
        if ($visible === []) {
            return [];
        }

        return \Modules\Supplier\Models\Supplier::query()
            ->whereIn('id', $visible)
            ->whereNotNull('email')
            ->pluck('id')
            ->all();
    }

    /**
     * Zero-trust guard for expense writes: every supplier id in the payload
     * (top-level and per-invoice rows) must be visible to the caller's branch.
     *
     * @throws ValidationException 422 with an Arabic message the app can show inline
     */
    public function assertPayloadVisible(?string $branchId, array $data): void
    {
        $ids = collect([
            $data['supplier_id'] ?? null,
            $data['payment_supplier_id'] ?? null,
            $data['default_supplier_id'] ?? null,
        ])
            ->merge(collect($data['invoices'] ?? [])->flatMap(fn ($row) => [
                $row['supplier_id'] ?? null,
                $row['payment_supplier_id'] ?? null,
            ]))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $visible = $this->visibleSupplierIds($branchId);
        $outside = $ids->diff($visible);

        if ($outside->isNotEmpty()) {
            throw ValidationException::withMessages([
                'supplier_id' => __('المورد المحدد غير متاح لعلامتك التجارية — اختر مورداً من قائمة موردي العلامة.'),
            ]);
        }
    }

    /**
     * Branch → (brand, company), read-only. Mirrors BranchHierarchyLinker's
     * derivation chain without its heal-write.
     *
     * @return array{0: string|null, 1: string|null} [brandId, companyId]
     */
    private function resolveScope(?string $branchId): array
    {
        if ($branchId === null) {
            return [null, null];
        }

        $branch = Branch::whereKey($branchId)
            ->first(['id', 'asab_brand_id', 'asab_company_id', 'asab_restaurant_id']);
        if ($branch === null) {
            return [null, null];
        }

        $brandId = $branch->asab_brand_id;
        $companyId = $branch->asab_company_id;

        if ($brandId === null && $branch->asab_restaurant_id !== null) {
            $restaurant = AsabRestaurant::withoutGlobalScopes()
                ->whereKey($branch->asab_restaurant_id)
                ->first(['id', 'brand_id', 'company_id']);
            $brandId = $restaurant?->brand_id;
            $companyId ??= $restaurant?->company_id;
        }

        if ($companyId === null && $brandId !== null) {
            $companyId = AsabBrand::withoutGlobalScopes()->whereKey($brandId)->value('company_id');
        }

        return [$brandId, $companyId];
    }
}
