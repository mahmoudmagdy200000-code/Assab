<?php

namespace Modules\Inventory\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\Enums\MonthlyInventoryStatus;
use Modules\Inventory\Models\MonthlyInventory;

class MonthlyInventoryRepository
{
    public function create(array $data): MonthlyInventory
    {
        return MonthlyInventory::create($data);
    }

    public function update(MonthlyInventory $inventory, array $data): bool
    {
        return $inventory->update($data);
    }

    public function find(string $id, array $relations = []): ?MonthlyInventory
    {
        $query = MonthlyInventory::query();

        if (!empty($relations)) {
            $query->with($relations);
        }

        return $query->find($id);
    }

    public function findByBranch(string $id, string $branchId, array $relations = []): ?MonthlyInventory
    {
        $query = MonthlyInventory::where('id', $id)->where('branch_id', $branchId);

        if (!empty($relations)) {
            $query->with($relations);
        }

        return $query->first();
    }

    public function findByBranchAndCreator(string $id, string $branchId, string $createdBy, array $relations = []): ?MonthlyInventory
    {
        $query = MonthlyInventory::where('id', $id)
            ->where('branch_id', $branchId)
            ->where('created_by', $createdBy);

        if (!empty($relations)) {
            $query->with($relations);
        }

        return $query->first();
    }

    /**
     * @param array{branch_id?: string, created_by?: string, status?: string|MonthlyInventoryStatus, date_from?: string, date_to?: string} $filters
     */
    public function getPaginated(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = MonthlyInventory::query();

        if (!empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        if (!empty($filters['created_by'])) {
            $query->where('created_by', $filters['created_by']);
        }

        if (isset($filters['status'])) {
            $status = $filters['status'];
            if ($status instanceof MonthlyInventoryStatus) {
                $query->where('status', $status);
            } else {
                $query->where('status', $status);
            }
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('inventory_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('inventory_date', '<=', $filters['date_to']);
        }

        return $query->with(['branch', 'createdBy', 'staff'])
            ->withCount('products')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get last completed/submitted monthly inventory for a branch (for "Use Same Team").
     */
    public function getLastCompletedForBranch(string $branchId): ?MonthlyInventory
    {
        return MonthlyInventory::where('branch_id', $branchId)
            ->whereIn('status', [
                MonthlyInventoryStatus::COMPLETED,
                MonthlyInventoryStatus::SUBMITTED,
                MonthlyInventoryStatus::APPROVED,
            ])
            ->with('staff.user')
            ->orderBy('created_at', 'desc')
            ->first();
    }
}
