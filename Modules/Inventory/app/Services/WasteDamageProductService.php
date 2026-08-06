<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Collection;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\PurchaseOrderItem;

class WasteDamageProductService
{
    /**
     * Get products from closed orders for the branch with Product Information.
     *
     * @param  array{search?: string, category?: string, subcategory?: string, per_page?: int}  $filters
     * @return Collection<int, array>
     */
    public function getProductsFromClosedOrdersForBranch(string $branchId, array $filters = []): Collection
    {
        $query = PurchaseOrderItem::with([
            'purchaseOrder:id,order_number,status,closed_at,branch_id',
            'item:id,name,code,logo,unit,category,subcategory',
        ])
            ->whereHas('purchaseOrder', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                    ->where('status', OrderStatus::CLOSED);
            });

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('item_name', 'like', "%{$search}%")
                    ->orWhereHas('item', function ($iq) use ($search) {
                        $iq->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    });
            });
        }

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['subcategory'])) {
            $query->where('subcategory', $filters['subcategory']);
        }

        $items = $query->get();

        $itemIds = $items->pluck('item_id')->filter()->unique()->values()->all();

        // Meeting 2026-08-05 «منتجات المشتريات لا تظهر في الهدر والتالف»: a
        // branch that has not yet CLOSED a purchase order had nothing to report
        // waste on, even with a full purchase catalog assigned to it. The
        // branch's own item list is the honest source; closed-order rows stay
        // first (they carry the real received qty / price / expiry), and the
        // rest are appended with a null purchase_order_item_id — which the
        // report writer already accepts and resolves later if an order exists.
        $catalogRows = $this->branchCatalogProducts($branchId, $itemIds, $filters);

        $branchInventoryByItem = [];
        $branchItemByItem = [];
        if (! empty($itemIds)) {
            BranchInventory::query()
                ->where('branch_id', $branchId)
                ->whereIn('item_id', $itemIds)
                ->get()
                ->each(function (BranchInventory $bi) use (&$branchInventoryByItem) {
                    $branchInventoryByItem[$bi->item_id] = $bi;
                });

            BranchItem::query()
                ->where('branch_id', $branchId)
                ->whereIn('item_id', $itemIds)
                ->get()
                ->each(function (BranchItem $bi) use (&$branchItemByItem) {
                    $branchItemByItem[$bi->item_id] = $bi;
                });
        }

        $branch = Branch::find($branchId);
        $storageLocation = $branch ? ($branch->name ?? $branch->location) : null;

        $fromOrders = $items->map(function (PurchaseOrderItem $item) use (
            $branchInventoryByItem,
            $branchItemByItem,
            $storageLocation
        ) {
            $inv = $branchInventoryByItem[$item->item_id ?? ''] ?? null;
            $bi = $branchItemByItem[$item->item_id ?? ''] ?? null;

            $availableInStock = $inv ? (float) $inv->available_quantity - (float) $inv->reserved_quantity : 0;
            $pricePerUnit = $bi ? (float) $bi->price : (float) $item->unit_price;
            $expirationDate = $inv?->earliest_expiry_date?->format('F j, Y')
                ?? $item->expiry_date?->format('F j, Y');

            $unit = $item->item?->unit ?? $item->unit_of_measurement ?? 'unit';

            // Every string field is coalesced: the app casts them with
            // `as String` and a single null takes the whole screen down with
            // «type 'Null' is not a subtype of type 'String'» (2026-08-05).
            return [
                'id' => (string) $item->id,
                'purchase_order_item_id' => (string) $item->id,
                'purchase_order_id' => (string) ($item->purchase_order_id ?? ''),
                'order_number' => (string) ($item->purchaseOrder->order_number ?? ''),
                'item_id' => (string) ($item->item_id ?? ''),
                'item_name' => (string) ($item->item_name ?? $item->item?->name ?? ''),
                'item_code' => (string) ($item->item?->code ?? ''),
                'item_logo' => (string) ($item->item?->logo_url ?? ''),
                'item_unit' => (string) $unit,
                'category' => (string) ($item->category ?? ''),
                'subcategory' => (string) ($item->subcategory ?? ''),
                'quantity_ordered' => (float) $item->quantity_ordered,
                'quantity_received' => (float) $item->quantity_received,
                'unit_price' => (float) $item->unit_price,
                'closed_at' => (string) ($item->purchaseOrder->closed_at ?? ''),
                'available_in_stock' => $availableInStock,
                'price_per_unit' => $pricePerUnit,
                'expiration_date' => (string) ($expirationDate ?? ''),
                'storage_location' => (string) ($storageLocation ?? ''),
            ];
        })->values();

        return $fromOrders
            ->concat($this->presentCatalogProducts($catalogRows, $branchId, $storageLocation))
            ->values();
    }

    /**
     * The branch's assigned purchase items that no closed order has delivered
     * yet. Same filters as the closed-order query so a search behaves the same
     * on both halves of the list.
     *
     * @param  string[]  $excludeItemIds
     * @param  array{search?: string, category?: string, subcategory?: string}  $filters
     * @return Collection<int, BranchItem>
     */
    private function branchCatalogProducts(string $branchId, array $excludeItemIds, array $filters): Collection
    {
        return BranchItem::query()
            ->where('branch_id', $branchId)
            ->whereNotIn('item_id', $excludeItemIds)
            ->whereHas('item')
            ->with('item:id,name,code,logo,unit,category,subcategory')
            ->when(! empty($filters['search']), fn ($q) => $q->search($filters['search']))
            ->when(! empty($filters['category']), fn ($q) => $q->byCategory($filters['category']))
            ->when(! empty($filters['subcategory']), fn ($q) => $q->bySubcategory($filters['subcategory']))
            ->limit(500)
            ->get()
            ->sortBy(fn (BranchItem $bi) => $bi->item?->name ?? '')
            ->values();
    }

    /**
     * @param  Collection<int, BranchItem>  $rows
     * @return Collection<int, array>
     */
    private function presentCatalogProducts(Collection $rows, string $branchId, ?string $storageLocation): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $inventory = BranchInventory::query()
            ->where('branch_id', $branchId)
            ->whereIn('item_id', $rows->pluck('item_id')->all())
            ->get()
            ->keyBy('item_id');

        return $rows->map(function (BranchItem $bi) use ($inventory, $storageLocation) {
            $inv = $inventory->get($bi->item_id);

            return [
                // The row identity is the item itself: there is no order line
                // behind it, and the report writer keys on item_id. The order
                // ids are EMPTY STRINGS rather than nulls — the app casts them
                // with `as String`, and Laravel's ConvertEmptyStringsToNull
                // turns them back into the null the report writer expects when
                // the client sends the row back.
                'id' => (string) $bi->item_id,
                'purchase_order_item_id' => '',
                'purchase_order_id' => '',
                'order_number' => '',
                'item_id' => (string) $bi->item_id,
                'item_name' => (string) ($bi->item?->name ?? ''),
                'item_code' => (string) ($bi->item?->code ?? ''),
                'item_logo' => (string) ($bi->item?->logo_url ?? ''),
                'item_unit' => (string) ($bi->item?->unit ?: 'unit'),
                'category' => (string) ($bi->item?->category ?? ''),
                'subcategory' => (string) ($bi->item?->subcategory ?? ''),
                'quantity_ordered' => 0.0,
                'quantity_received' => 0.0,
                'unit_price' => (float) $bi->price,
                'closed_at' => '',
                'available_in_stock' => $inv
                    ? (float) $inv->available_quantity - (float) $inv->reserved_quantity
                    : 0,
                'price_per_unit' => (float) $bi->price,
                'expiration_date' => (string) ($inv?->earliest_expiry_date?->format('F j, Y') ?? ''),
                'storage_location' => (string) ($storageLocation ?? ''),
            ];
        })->values();
    }

    /**
     * Get assignment context for the current branch (for collapsible Assignment Information).
     *
     * @return array{branch_id: string, branch_name: string, branch_location: string|null, creator_name: string|null}
     */
    public function getAssignmentInfo(string $branchId, ?string $creatorName = null): array
    {
        $branch = Branch::find($branchId);

        return [
            'branch_id' => $branchId,
            'branch_name' => $branch?->name ?? '',
            'branch_location' => $branch?->location ?? null,
            'creator_name' => $creatorName,
        ];
    }
}
