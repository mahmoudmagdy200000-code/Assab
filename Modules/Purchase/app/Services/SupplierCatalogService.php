<?php

namespace Modules\Purchase\Services;

use Modules\Expense\Services\SupplierBrandScopeService;
use Modules\Purchase\Models\SupplierItem;
use Modules\Supplier\Models\SupplierProduct;

/**
 * How many suppliers a branch can actually order a given item from.
 *
 * The item card used to count suppliers out of EXPENSE history (invoices
 * matching the item by name), while the picker it opens lists the brand's
 * orderable suppliers holding a catalog row for that item. The two answers had
 * nothing to do with each other, which is how the app ended up showing
 * «All Suppliers (8)» above an empty list. Both now read the same source.
 */
class SupplierCatalogService
{
    public function __construct(private readonly SupplierBrandScopeService $brandScope) {}

    /**
     * @param  string[]  $itemIds
     * @return array<string, int> item_id => supplier count (missing ids mean 0)
     */
    public function supplierCountsForItems(array $itemIds, ?string $branchId): array
    {
        $itemIds = array_values(array_filter(array_unique($itemIds)));

        if ($itemIds === []) {
            return [];
        }

        // Fail-closed like every other brand-scoped read: an unlinked branch
        // can order from nobody, so it counts nobody.
        $orderableIds = $this->brandScope->orderableSupplierIds($branchId);

        if ($orderableIds === []) {
            return [];
        }

        $pairs = SupplierItem::query()
            ->whereIn('item_id', $itemIds)
            ->whereIn('supplier_id', $orderableIds)
            ->available()
            ->whereHas('supplier', fn ($q) => $q->active())
            ->select('item_id', 'supplier_id')
            ->distinct()
            ->get()
            ->concat(
                SupplierProduct::query()
                    ->whereIn('item_id', $itemIds)
                    ->whereIn('supplier_id', $orderableIds)
                    ->available()
                    ->whereHas('supplier', fn ($q) => $q->active())
                    ->select('item_id', 'supplier_id')
                    ->distinct()
                    ->get()
            );

        return $pairs
            ->groupBy('item_id')
            // A supplier listing the same item in both catalogs is one supplier.
            ->map(fn ($rows) => $rows->pluck('supplier_id')->unique()->count())
            ->all();
    }

    public function supplierCountForItem(?string $itemId, ?string $branchId): int
    {
        if ($itemId === null) {
            return 0;
        }

        return $this->supplierCountsForItems([$itemId], $branchId)[$itemId] ?? 0;
    }
}
