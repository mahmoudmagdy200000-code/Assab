<?php

namespace Modules\Supplier\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Supplier\Models\Supplier;
use Modules\Supplier\Models\SupplierProduct;
use Modules\Supplier\Models\SupplierInventory;

class InventoryService
{
    /**
     * Get supplier products with pagination
     */
    public function getProducts(Supplier $supplier, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = SupplierProduct::where('supplier_id', $supplier->id)
            ->with('inventory')
            ->orderBy('created_at', 'desc');

        // Search by name
        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        // Filter by availability
        if (isset($filters['is_available'])) {
            $query->where('is_available', $filters['is_available']);
        }

        // Filter by quality level
        if (!empty($filters['quality_level'])) {
            $query->where('quality_level', $filters['quality_level']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Create new product
     */
    public function createProduct(Supplier $supplier, array $data): SupplierProduct
    {
        return DB::transaction(function () use ($supplier, $data) {
            $product = SupplierProduct::create(array_merge($data, [
                'supplier_id' => $supplier->id,
            ]));

            // Create initial inventory record
            SupplierInventory::create([
                'supplier_id' => $supplier->id,
                'product_id' => $product->id,
                'quantity' => $data['stock_quantity'] ?? 0,
            ]);

            return $product->fresh(['inventory']);
        });
    }

    /**
     * Update product
     */
    public function updateProduct(SupplierProduct $product, Supplier $supplier, array $data): SupplierProduct
    {
        if ($product->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this product');
        }

        $product->update($data);

        return $product->fresh(['inventory']);
    }

    /**
     * Update stock levels
     */
    public function updateStock(SupplierProduct $product, Supplier $supplier, array $data): SupplierInventory
    {
        if ($product->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this product');
        }

        $inventory = SupplierInventory::where('supplier_id', $supplier->id)
            ->where('product_id', $product->id)
            ->first();

        if (!$inventory) {
            $inventory = SupplierInventory::create([
                'supplier_id' => $supplier->id,
                'product_id' => $product->id,
                'quantity' => $data['quantity'] ?? 0,
            ]);
        } else {
            $inventory->update([
                'quantity' => $data['quantity'] ?? $inventory->quantity,
                'reserved_quantity' => $data['reserved_quantity'] ?? $inventory->reserved_quantity,
                'reorder_level' => $data['reorder_level'] ?? $inventory->reorder_level,
                'max_stock_level' => $data['max_stock_level'] ?? $inventory->max_stock_level,
                'last_restocked_at' => $data['restocked'] ? now() : $inventory->last_restocked_at,
            ]);
        }

        // Update product stock quantity
        $product->update([
            'stock_quantity' => $inventory->quantity,
        ]);

        return $inventory->fresh();
    }

    /**
     * Get low stock alerts
     */
    public function getLowStockAlerts(Supplier $supplier): array
    {
        return SupplierInventory::where('supplier_id', $supplier->id)
            ->whereRaw('quantity <= reorder_level')
            ->with('product')
            ->get()
            ->map(function ($inventory) {
                return [
                    'product_id' => $inventory->product_id,
                    'product_name' => $inventory->product->name ?? null,
                    'current_quantity' => (float) $inventory->quantity,
                    'reorder_level' => (float) $inventory->reorder_level,
                    'is_low_stock' => $inventory->isLowStock(),
                ];
            })
            ->toArray();
    }
}

