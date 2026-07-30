<?php

namespace Modules\Expense\Services;

use Modules\Expense\Models\Category;
use Modules\Expense\Models\Supplier;

/**
 * Expense Helper Service
 * Common functionality across all expense types
 */
class ExpenseHelperService
{
    public function __construct(
        private SupplierBrandScopeService $supplierScope,
    ) {}

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
     * Items of the brand master catalog under a category — the mobile «Item
     * List» screen (meeting: «اختار معدات → يجيب التلاجة والبوتاجاز»). Items
     * live in the dashboard catalog (asab_inventory_catalog_items) tagged by
     * the sheet's «التصنيف», so a category matches items carrying its own name
     * or any of its children's names. Brand-scoped through the caller's branch
     * link — an unlinked branch gets an empty list (fail-closed, same rule as
     * the supplier picker).
     */
    public function getCategoryItems(string $categoryId, ?string $branchId, ?string $search = null): array
    {
        $category = Category::with('children:id,parent_id,name')->findOrFail($categoryId);

        $payload = [
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'type' => $category->type,
            ],
            'items' => [],
        ];

        $brandId = $branchId
            ? \Modules\Branch\Models\Branch::whereKey($branchId)->value('asab_brand_id')
            : null;

        if (! $brandId) {
            return $payload;
        }

        $names = $category->children->pluck('name')
            ->prepend($category->name)
            ->unique()
            ->values()
            ->all();

        // Bridge mapping: raw-materials → 'purchase' taxonomy, sales-items → 'expense'.
        $itemType = $category->type === 'purchase'
            ? \Modules\Admin\Models\InventoryCatalogItem::TYPE_RAW_MATERIAL
            : \Modules\Admin\Models\InventoryCatalogItem::TYPE_SALES_ITEM;

        $payload['items'] = \Modules\Admin\Models\InventoryCatalogItem::query()
            ->where('brand_id', $brandId)
            ->where('type', $itemType)
            ->where('status', 'active')
            ->whereIn('category', $names)
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'code', 'category', 'unit', 'unit_price'])
            ->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'code' => $item->code,
                'category' => $item->category,
                'unit' => $item->unit,
                'price' => round(((int) $item->unit_price) / 100, 2),
            ])
            ->all();

        return $payload;
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
        $categoryModel = Category::findOrFail($category);

        $categoryModel->name = $data['name'];
        $categoryModel->parent_id = $data['parent_id'] ?? null;
        $categoryModel->type = $data['type'] ?? 'expense';
        $categoryModel->is_active = $data['is_active'] ?? true;

        $categoryModel->save();

        return $categoryModel;
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
     * Suppliers visible to one branch: the caller's own brand only (meeting
     * 2026-07-29 — «تقييد قائمة الموردين بحسب العلامة/الفرع»). Fail-closed: an
     * unlinked branch gets an empty list, not every tenant's suppliers.
     */
    public function getSuppliersForBranch(?string $branchId, ?string $search = null)
    {
        $visibleIds = $this->supplierScope->visibleSupplierIds($branchId);
        if ($visibleIds === []) {
            return collect([]);
        }

        $query = Supplier::whereIn('id', $visibleIds)
            ->where('is_active', true)
            ->orderBy('name');

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        return $query->get()->map(fn ($supplier) => [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'phone' => $supplier->phone,
            'email' => $supplier->email,
        ]);
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
            'invoice_number' => 'INV-'.rand(1000, 9999),
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
