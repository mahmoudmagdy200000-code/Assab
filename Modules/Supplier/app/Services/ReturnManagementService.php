<?php

namespace Modules\Supplier\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Enums\ReturnStatus;
use Modules\Purchase\Models\ReturnOrder;
use Modules\Supplier\Models\Supplier;

class ReturnManagementService
{
    /**
     * Get return requests for supplier
     */
    public function getReturnRequests(Supplier $supplier, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = ReturnOrder::with([
            'purchaseOrder:id,order_number',
            'supplier',
            'branch:id,name',
            'respondedBy',
            'items',
        ])
            ->where('supplier_id', $supplier->id)
            ->orderBy('created_at', 'desc');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $query->where('return_number', 'like', '%' . $filters['search'] . '%');
        }

        return $query->paginate($perPage);
    }

    /**
     * Get return details
     */
    public function getReturnDetails(string $returnId, Supplier $supplier): ?ReturnOrder
    {
        return ReturnOrder::with([
            'purchaseOrder',
            'supplier',
            'branch',
            'respondedBy',
            'items',
            'timelines',
        ])
            ->where('id', $returnId)
            ->where('supplier_id', $supplier->id)
            ->first();
    }

    /**
     * Approve return request
     */
    public function approveReturn(ReturnOrder $returnOrder, Supplier $supplier, array $data): ReturnOrder
    {
        if ($returnOrder->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this return');
        }

        if ($returnOrder->status !== ReturnStatus::PENDING) {
            throw new \Exception('Return cannot be approved in current status');
        }

        return DB::transaction(function () use ($returnOrder, $data) {
            $returnOrder->update([
                'status' => ReturnStatus::APPROVED,
                'responded_by' => auth()->id(),
                'responded_at' => now(),
                'response_notes' => $data['notes'] ?? null,
                'refund_method' => $data['refund_method'] ?? null,
                'resolution_type' => $data['resolution_type'] ?? 'refund',
            ]);

            return $returnOrder->fresh();
        });
    }

    /**
     * Reject return request
     */
    public function rejectReturn(ReturnOrder $returnOrder, Supplier $supplier, array $data): ReturnOrder
    {
        if ($returnOrder->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this return');
        }

        if ($returnOrder->status !== ReturnStatus::PENDING) {
            throw new \Exception('Return cannot be rejected in current status');
        }

        return DB::transaction(function () use ($returnOrder, $data) {
            $returnOrder->update([
                'status' => ReturnStatus::REJECTED,
                'responded_by' => auth()->id(),
                'responded_at' => now(),
                'rejected_at' => now(),
                'rejection_reason' => $data['reason'] ?? null,
                'response_notes' => $data['explanation'] ?? null,
            ]);

            return $returnOrder->fresh();
        });
    }

    /**
     * Process return (complete resolution)
     */
    public function processReturn(ReturnOrder $returnOrder, Supplier $supplier, array $data): ReturnOrder
    {
        if ($returnOrder->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this return');
        }

        if ($returnOrder->status !== ReturnStatus::APPROVED) {
            throw new \Exception('Return must be approved before processing');
        }

        return DB::transaction(function () use ($returnOrder, $data) {
            $returnOrder->update([
                'status' => ReturnStatus::RESOLVED,
                'resolved_by' => auth()->id(),
                'resolved_at' => now(),
                'resolution_notes' => $data['resolution_notes'] ?? null,
                'refund_amount' => $data['refund_amount'] ?? $returnOrder->total_return_amount,
            ]);

            return $returnOrder->fresh();
        });
    }
}

