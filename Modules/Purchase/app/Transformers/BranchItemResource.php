<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BranchItemResource extends JsonResource
{
    public function toArray($request): array
    {
        // Get suppliers count for this item (with error handling)
        $suppliersCount = 0;
        try {
            // Use a direct query to avoid soft delete issues
            // The suppliers table doesn't have deleted_at column
            $suppliersCount = DB::table('suppliers')
                ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('expenses')
                        ->join('expense_items', 'expense_items.expense_id', '=', 'expenses.id')
                        ->whereColumn('expenses.supplier_id', 'suppliers.id')
                        ->where('expense_items.name', $this->item_name)
                        ->whereNull('expenses.deleted_at'); // Only check soft deletes on expenses table
                })
                ->count();
        } catch (\Exception $e) {
            // Log the error but don't fail the entire request
            Log::warning('Error counting suppliers for branch item', [
                'item_id' => $this->id,
                'item_name' => $this->item_name,
                'error' => $e->getMessage(),
            ]);
        }

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
