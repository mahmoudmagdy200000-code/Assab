<?php

namespace Modules\Purchase\Repositories;

use Modules\Purchase\Models\PurchaseReturn;
use Illuminate\Pagination\LengthAwarePaginator;

class PurchaseReturnRepository
{
    /**
     * Get returns with filters
     */
    public function getReturns(array $filters): LengthAwarePaginator
    {
        $query = PurchaseReturn::with(['purchaseOrder', 'goodsReceipt', 'branch', 'supplier', 'createdBy', 'items']);

        $this->applyFilters($query, $filters);

        return $query->orderBy('created_at', 'desc')->paginate(20);
    }

    /**
     * Create return
     */
    public function create(array $data): PurchaseReturn
    {
        return PurchaseReturn::create($data);
    }

    /**
     * Find by ID or fail
     */
    public function findOrFail($id): PurchaseReturn
    {
        return PurchaseReturn::findOrFail($id);
    }

    /**
     * Find with relations
     */
    public function findWithRelations($id, array $relations): PurchaseReturn
    {
        return PurchaseReturn::with($relations)->findOrFail($id);
    }

    /**
     * Apply filters
     */
    private function applyFilters($query, array $filters): void
    {
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'in_progress') {
                $query->whereIn('status', ['pending', 'approved', 'rejected']);
            } elseif ($filters['status'] === 'completed') {
                $query->whereIn('status', ['closed', 'resolved']);
            } else {
                $query->where('status', $filters['status']);
            }
        }

        if (!empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }
    }
}
