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

        return $items->map(function (PurchaseOrderItem $item) use (
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

            return [
                'id' => $item->id,
                'purchase_order_item_id' => $item->id,
                'purchase_order_id' => $item->purchase_order_id,
                'order_number' => $item->purchaseOrder->order_number ?? null,
                'item_id' => $item->item_id,
                'item_name' => $item->item_name,
                'item_code' => $item->item?->code ?? null,
                'item_logo' => $item->item_logo ?? $item->item?->logo,
                'item_unit' => $unit,
                'category' => $item->category,
                'subcategory' => $item->subcategory,
                'quantity_ordered' => $item->quantity_ordered,
                'quantity_received' => $item->quantity_received,
                'unit_price' => $item->unit_price,
                'closed_at' => $item->purchaseOrder->closed_at ?? null,
                'available_in_stock' => $availableInStock,
                'price_per_unit' => $pricePerUnit,
                'expiration_date' => $expirationDate,
                'storage_location' => $storageLocation,
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
