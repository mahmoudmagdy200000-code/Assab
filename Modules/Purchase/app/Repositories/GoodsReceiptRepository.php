<?php

namespace Modules\Purchase\Repositories;

use Modules\Purchase\Models\GoodsReceipt;
use Illuminate\Pagination\LengthAwarePaginator;

class GoodsReceiptRepository
{
    /**
     * Get receipts with filters
     */
    public function getReceipts(array $filters): LengthAwarePaginator
    {
        $query = GoodsReceipt::with(['purchaseOrder', 'branch', 'receivedBy', 'supplier', 'items']);

        $this->applyFilters($query, $filters);

        return $query->orderBy('created_at', 'desc')->paginate(20);
    }

    /**
     * Create receipt
     */
    public function create(array $data): GoodsReceipt
    {
        return GoodsReceipt::create($data);
    }

    /**
     * Find by ID or fail
     */
    public function findOrFail($id): GoodsReceipt
    {
        return GoodsReceipt::findOrFail($id);
    }

    /**
     * Find with relations
     */
    public function findWithRelations($id, array $relations): GoodsReceipt
    {
        return GoodsReceipt::with($relations)->findOrFail($id);
    }

    /**
     * Apply filters
     */
    private function applyFilters($query, array $filters): void
    {
        if (!empty($filters['status'])) {
            // Map custom statuses
            if ($filters['status'] === 'in_progress') {
                $query->where('status', 'completed')
                      ->whereHas('purchaseOrder', function ($q) {
                          $q->whereIn('status', ['preparing', 'on_the_way', 'partial_confirmation', 'confirmed', 'delivered']);
                      });
            } elseif ($filters['status'] === 'missing') {
                $query->where('status', 'completed')
                      ->whereHas('variances', function ($q) {
                          $q->whereNull('action_taken');
                      });
            } else {
                $query->where('status', $filters['status']);
            }
        }

        if (!empty($filters['type'])) {
            $query->whereHas('purchaseOrder', function ($q) use ($filters) {
                $q->where('order_type', $filters['type']);
            });
        }

        if (!empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }
    }
}
