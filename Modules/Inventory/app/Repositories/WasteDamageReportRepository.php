<?php

namespace Modules\Inventory\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\Models\WasteDamageReport;

class WasteDamageReportRepository
{
    public function create(array $data): WasteDamageReport
    {
        return WasteDamageReport::create($data);
    }

    public function update(WasteDamageReport $report, array $data): bool
    {
        return $report->update($data);
    }

    /**
     * @param array $relations
     */
    public function find(string $id, array $relations = []): ?WasteDamageReport
    {
        $query = WasteDamageReport::query();

        if (!empty($relations)) {
            $query->with($relations);
        }

        return $query->find($id);
    }

    public function findByBranch(string $id, string $branchId, array $relations = []): ?WasteDamageReport
    {
        $query = WasteDamageReport::where('id', $id)->where('branch_id', $branchId);

        if (!empty($relations)) {
            $query->with($relations);
        }

        return $query->first();
    }

    /**
     * @param array{branch_id?: string, status?: string} $filters
     */
    public function getPaginated(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = WasteDamageReport::query();

        if (!empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $query->orderByDesc('created_at');

        return $query->paginate($perPage);
    }
}
