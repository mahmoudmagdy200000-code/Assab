<?php

namespace Modules\Purchase\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Purchase\Models\PurchaseOrder;

/**
 * Repository for Purchase Order data access
 *
 * Encapsulates database queries for Purchase Orders
 */
class PurchaseOrderRepository
{
    /**
     * Find purchase order with relations
     */
    public function findWithRelations(string $id, array $relations = []): ?PurchaseOrder
    {
        if (empty($relations)) {
            return PurchaseOrder::find($id);
        }

        return PurchaseOrder::with($relations)->find($id);
    }

    /**
     * Find purchase order by ID and branch
     */
    public function findByBranch(string $id, string $branchId, array $relations = []): ?PurchaseOrder
    {
        $query = PurchaseOrder::where('id', $id)->where('branch_id', $branchId);

        if (! empty($relations)) {
            $query->with($relations);
        }

        return $query->first();
    }

    /**
     * Create a new purchase order
     */
    public function create(array $data): PurchaseOrder
    {
        return PurchaseOrder::create($data);
    }

    /**
     * Update purchase order
     */
    public function update(PurchaseOrder $order, array $data): bool
    {
        return $order->update($data);
    }

    /**
     * Get orders with filters
     */
    public function getOrders(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = PurchaseOrder::query();

        // Apply filters
        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['order_type'])) {
            $query->where('order_type', $filters['order_type']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }
}
