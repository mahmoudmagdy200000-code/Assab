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
        // IMPORTANT: Using raw SQL to avoid Eloquent soft delete checks on suppliers table
        // The suppliers table does NOT have deleted_at column
        $suppliersCount = 0;
        try {
            $result = DB::selectOne(
                "SELECT COUNT(DISTINCT suppliers.id) as count
                FROM suppliers
                WHERE EXISTS (
                    SELECT 1
                    FROM expenses
                    INNER JOIN expense_items ON expense_items.expense_id = expenses.id
                    WHERE expenses.supplier_id = suppliers.id
                    AND expense_items.name = ?
                    AND expenses.deleted_at IS NULL
                )",
                [$this->item_name]
            );
            $suppliersCount = $result->count ?? 0;
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
