<?php

namespace Modules\Purchase\Repositories;

use Modules\Purchase\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class PurchaseOrderRepository
{
    /**
     * Get purchase history
     */
    public function getHistory(array $filters): LengthAwarePaginator
    {
        $query = PurchaseOrder::with(['branch', 'supplier', 'purchasingOfficer', 'transferFromBranch'])
            ->whereIn('status', ['closed', 'canceled', 'confirmed', 'partial_confirmation']);

        $this->applyFilters($query, $filters);

        return $query->orderBy('created_at', 'desc')->paginate(20);
    }

    /**
     * Get pending orders
     */
    public function getPending(array $filters): LengthAwarePaginator
    {
        $query = PurchaseOrder::with(['branch', 'supplier', 'purchasingOfficer', 'transferFromBranch'])
            ->whereIn('status', [
                'pending',
                'pending_confirmation',
                'pending_approval',
                'partial_confirmation',
                'confirmed',
                'preparing',
                'on_the_way',
                'delay_reported',
                'draft'
            ]);

        $this->applyFilters($query, $filters);

        return $query->orderBy('created_at', 'desc')->paginate(20);
    }

    /**
     * Create order
     */
    public function create(array $data): PurchaseOrder
    {
        return PurchaseOrder::create($data);
    }

    /**
     * Find by ID or fail
     */
    public function findOrFail($id): PurchaseOrder
    {
        return PurchaseOrder::findOrFail($id);
    }

    /**
     * Find with relations
     */
    public function findWithRelations($id, array $relations): PurchaseOrder
    {
        return PurchaseOrder::with($relations)->findOrFail($id);
    }

    /**
     * Apply filters to query
     */
    private function applyFilters($query, array $filters): void
    {
        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('order_number', 'like', '%' . $filters['search'] . '%')
                  ->orWhereHas('items', function ($itemQuery) use ($filters) {
                      $itemQuery->where('item_name', 'like', '%' . $filters['search'] . '%');
                  });
            });
        }

        if (!empty($filters['type'])) {
            if ($filters['type'] !== 'all') {
                $query->where('order_type', $filters['type']);
            }
        }

        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $query->whereIn('status', $filters['status']);
            } else {
                $query->where('status', $filters['status']);
            }
        }

        if (!empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
    }
}
