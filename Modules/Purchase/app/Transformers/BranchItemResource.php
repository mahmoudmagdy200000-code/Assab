<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class BranchItemResource extends JsonResource
{
    public function toArray($request): array
    {
        // Get suppliers count for this item
        $suppliersCount = \Modules\Expense\Models\Supplier::whereHas('expenses.items', function ($query) {
            $query->where('name', $this->item_name);
        })->count();

        return [
            'id' => $this->id,
            'item_name' => $this->item_name,
            'item_title' => $this->item_name, // Alias for item_name
            'item_logo' => $this->item_logo_url,
            'item_code' => $this->item_code,
            'item_unit' => $this->item_unit,
            'rate' => (float) $this->item_price, // Cost per unit
            'item_price' => (float) $this->item_price,
            'item_quantity' => (float) $this->item_quantity,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            
            // Suppliers info
            'suppliers_count' => $suppliersCount,
            
            // For display
            'unit' => $this->item_unit ?? 'kg',
        ];
    }
}

