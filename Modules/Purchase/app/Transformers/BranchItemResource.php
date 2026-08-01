<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchase\Services\SupplierCatalogService;

class BranchItemResource extends JsonResource
{
    public function toArray($request): array
    {
        // `suppliers_count` is the number of suppliers this branch can actually
        // ORDER the item from — the same set the supplier picker lists. It used
        // to be counted from expense invoices matching the item by name, which
        // is a different population entirely: the card promised «8 suppliers»
        // and the picker it opened came back empty.
        //
        // PurchaseOrderService::getBranchItems resolves it for the whole page;
        // the per-row fallback keeps any other caller correct.
        $suppliersCount = $this->resource->suppliers_count
            ?? app(SupplierCatalogService::class)->supplierCountForItem(
                $this->item_id,
                $request->user()?->branch_id ?? $this->branch_id,
            );

        return [
            'id' => $this->id,
            'item_name' => $this->item_name,
            'item_id' => $this->item_id,
            'item_title' => $this->item_name, // Alias for item_name
            'item_logo' => $this->item_logo_url,
            // Nullable item columns the app casts to non-null String — a
            // bridge-seeded item leaves code/subcategory unset. Coalesce to ''.
            'item_code' => $this->item_code ?? '',
            'item_unit' => $this->item_unit ?? 'kg',
            'rate' => (float) $this->item_price, // Cost per unit
            'item_price' => (float) $this->item_price,
            'item_quantity' => (float) $this->item_quantity,
            'category' => $this->category ?? '',
            'subcategory' => $this->subcategory ?? '',

            // Suppliers info
            'suppliers_count' => (int) $suppliersCount,

            // For display
            'unit' => $this->item_unit ?? 'kg',
        ];
    }
}
