<?php

namespace Modules\RecurringOrder\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\RecurringOrder\Models\RecurringOrder;

class RecurringOrderRepository
{
    public function findWithRelations(string $id, array $relations = []): ?RecurringOrder
    {
        if (empty($relations)) {
            return RecurringOrder::find($id);
        }
        return RecurringOrder::with($relations)->find($id);
    }

    public function findByBranch(string $id, string $branchId, array $relations = []): ?RecurringOrder
    {
        $query = RecurringOrder::where('id', $id)->where('branch_id', $branchId);
        if (!empty($relations)) {
            $query->with($relations);
        }
        return $query->first();
    }

    public function create(array $data): RecurringOrder
    {
        return RecurringOrder::create($data);
    }

    public function update(RecurringOrder $model, array $data): bool
    {
        return $model->update($data);
    }

    public function getInProgressList(string $branchId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = RecurringOrder::with(['sourceable', 'items.item'])
            ->byBranch($branchId)
            ->inProgressList()
            ->search($filters['search'] ?? null)
            ->orderBy('created_at', 'desc');

        return $query->paginate($perPage);
    }

    public function getNextSchedulingList(string $branchId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = RecurringOrder::with(['sourceable', 'items.item'])
            ->byBranch($branchId)
            ->nextSchedulingList()
            ->search($filters['search'] ?? null)
            ->orderBy('next_run_at', 'asc');

        return $query->paginate($perPage);
    }

    public function getPausedList(string $branchId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = RecurringOrder::with(['sourceable', 'items.item'])
            ->byBranch($branchId)
            ->pausedList()
            ->search($filters['search'] ?? null)
            ->orderBy('paused_at', 'desc');

        return $query->paginate($perPage);
    }
}
