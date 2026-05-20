<?php

namespace Modules\Inventory\Repositories;

use Modules\Inventory\Models\DailyInventorySchedule;

class DailyInventoryScheduleRepository
{
    public function create(array $data): DailyInventorySchedule
    {
        return DailyInventorySchedule::create($data);
    }

    public function update(DailyInventorySchedule $schedule, array $data): bool
    {
        return $schedule->update($data);
    }

    public function find(string $id, array $relations = []): ?DailyInventorySchedule
    {
        $query = DailyInventorySchedule::query();
        if (! empty($relations)) {
            $query->with($relations);
        }

        return $query->find($id);
    }

    public function findByBranch(string $branchId, array $relations = []): ?DailyInventorySchedule
    {
        $query = DailyInventorySchedule::byBranch($branchId)->active();
        if (! empty($relations)) {
            $query->with($relations);
        }

        return $query->first();
    }

    public function deleteForBranch(string $branchId): bool
    {
        return DailyInventorySchedule::where('branch_id', $branchId)->delete() >= 0;
    }

    /**
     * Get all active schedules (for generator command).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, DailyInventorySchedule>
     */
    public function getActiveSchedules(): \Illuminate\Database\Eloquent\Collection
    {
        return DailyInventorySchedule::active()
            ->with(['scheduleItems.item'])
            ->get();
    }
}
