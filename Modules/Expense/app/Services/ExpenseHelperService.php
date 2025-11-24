<?php

namespace Modules\Expense\Services;

use Modules\Expense\Models\{Category, Supplier};

/**
 * Expense Helper Service
 * Common functionality across all expense types
 */
class ExpenseHelperService
{
    /**
     * Get all categories
     */
    public function getCategories(?string $search = null)
    {
        $query = Category::with('parent')
            ->orderBy('name');

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        return $query->get()->map(function ($category) {
            return [
                'id' => $category->id,
                'name' => $category->name,
                'parent' => $category->parent ? [
                    'id' => $category->parent->id,
                    'name' => $category->parent->name,
                ] : null,
                'type' => $category->type, // 'purchase' or 'expense'
                'is_active' => $category->is_active,
            ];
        });
    }

  
    /**
     * Get all parent categories (categories without parent_id)
     */
    public function getParentCategories(?string $search = null, ?string $type = null)
    {
        $query = Category::whereNull('parent_id') // Use direct whereNull instead of scope
            ->active()
            ->withCount('children')
            ->orderBy('name');

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($type) {
            $query->where('type', $type);
        }

        $categories = $query->get();

        // Handle case when no categories found
        if ($categories->isEmpty()) {
            return collect([]);
        }

        return $categories->map(function ($category) {
            return [
                'id' => $category->id,
                'name' => $category->name,
                'type' => $category->type,
                'is_active' => $category->is_active,
                'subcategories_count' => $category->children_count,
            ];
        });
    }

    /**
     * Get subcategories by parent category ID
     */
    public function getSubcategories(string $parentId, ?string $search = null)
    {
        // Verify parent exists
        $parent = Category::findOrFail($parentId);

        $query = Category::where('parent_id', $parentId)
            ->active()
            ->orderBy('name');

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        return [
            'parent' => [
                'id' => $parent->id,
                'name' => $parent->name,
                'type' => $parent->type,
            ],
            'subcategories' => $query->get()->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'type' => $category->type,
                    'is_active' => $category->is_active,
                ];
            }),
        ];
    }

    /**
     * Create category
     */
    public function createCategory(array $data): Category
    {
        return Category::create([
            'name' => $data['name'],
            'parent_id' => $data['parent_id'] ?? null,
            'type' => $data['type'] ?? 'expense',
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * Get category by ID
     */
    public function getCategory(string $category): Category
    {
        return Category::with('parent')->findOrFail($category);
    }

    /**
     * Update category
     */
    public function updateCategory(string $category, array $data): Category
    {
        $category = Category::findOrFail($category);
        $category->update([
            'name' => $data['name'],
            'parent_id' => $data['parent_id'] ?? null,
            'type' => $data['type'] ?? 'expense',
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $category;
    }

    /**
     * Delete category
     */
    public function deleteCategory(string $category): void
    {
        $category = Category::findOrFail($category);

        // Check if category has subcategories
        if ($category->children()->count() > 0) {
            throw new \Exception('Cannot delete category with subcategories');
        }

        // Check if category is used in expenses
        if ($category->items()->count() > 0 || $category->expenseLines()->count() > 0) {
            throw new \Exception('Cannot delete category that is being used');
        }

        $category->delete();
    }

    /**
     * Get all suppliers
     */
    public function getSuppliers(?string $search = null)
    {
        $query = Supplier::where('is_active', true)
            ->orderBy('name');

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        return $query->get()->map(function ($supplier) {
            return [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'phone' => $supplier->phone,
                'email' => $supplier->email,
            ];
        });
    }

    /**
     * Parse QR Code (Saudi ZATCA format)
     */
    public function parseQRCode(string $qrCode): array
    {
        // TODO: Implement actual ZATCA QR code parsing
        // This is a placeholder
        // ZATCA format: TLV (Tag-Length-Value)
        // Tag 1: Seller name
        // Tag 2: VAT registration number
        // Tag 3: Timestamp
        // Tag 4: Total with VAT
        // Tag 5: VAT amount

        return [
            'seller_name' => 'Sample Supplier',
            'tax_id' => '300000000000003',
            'timestamp' => now()->toIso8601String(),
            'total_amount' => 1150.00,
            'vat_amount' => 150.00,
            'net_amount' => 1000.00,
            'invoice_number' => 'INV-' . rand(1000, 9999),
        ];
    }

    /**
     * Scan invoice code (alternative to QR)
     */
    public function scanInvoiceCode(string $code): array
    {
        // Similar to QR code parsing
        return $this->parseQRCode($code);
    }
}
