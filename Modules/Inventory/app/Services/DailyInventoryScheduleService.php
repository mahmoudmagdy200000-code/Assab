<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\DailyInventorySchedule;
use Modules\Inventory\Models\DailyInventoryScheduleItem;
use Modules\Inventory\Repositories\DailyInventoryScheduleRepository;
use Modules\Purchase\Models\BranchItem;

class DailyInventoryScheduleService
{
    public function __construct(
        private readonly DailyInventoryScheduleRepository $repository
    ) {}

    /**
     * Create or update daily inventory schedule for a branch.
     * Only one active schedule per branch; creating a new one replaces the previous.
     */
    public function createOrUpdate(string $branchId, array $data, ?string $createdBy = null): DailyInventorySchedule
    {
        return DB::transaction(function () use ($branchId, $data, $createdBy) {
            $existing = $this->repository->findByBranch($branchId);
            if ($existing) {
                $existing->scheduleItems()->delete();
            }

            $schedule = $existing ?? $this->repository->create([
                'branch_id' => $branchId,
                'start_date' => $data['start_date'],
                'start_time' => $data['start_time'],
                'is_active' => $data['is_active'] ?? true,
                'created_by' => $createdBy,
            ]);

            if ($existing) {
                $schedule->update([
                    'start_date' => $data['start_date'],
                    'start_time' => $data['start_time'],
                    'is_active' => $data['is_active'] ?? true,
                ]);
            }

            $this->validateItemsBelongToBranch($branchId, $data['item_ids'] ?? []);

            $sortOrder = 0;
            foreach ($data['item_ids'] ?? [] as $itemId) {
                DailyInventoryScheduleItem::create([
                    'daily_inventory_schedule_id' => $schedule->id,
                    'item_id' => $itemId,
                    'sort_order' => $sortOrder++,
                ]);
            }

            return $schedule->fresh(['scheduleItems.item', 'branch']);
        });
    }

    /**
     * Validate that all item_ids exist in branch_item for the given branch.
     *
     * @throws \InvalidArgumentException
     */
    public function validateItemsBelongToBranch(string $branchId, array $itemIds): void
    {
        if (empty($itemIds)) {
            return;
        }

        $validCount = BranchItem::where('branch_id', $branchId)
            ->whereIn('item_id', $itemIds)
            ->count();

        if ($validCount !== count(array_unique($itemIds))) {
            throw new \InvalidArgumentException(
                'One or more items are not assigned to this branch. Daily inventory items must be selected from the branch item list.'
            );
        }
    }

    public function getForBranch(string $branchId): ?DailyInventorySchedule
    {
        return $this->repository->findByBranch($branchId, ['scheduleItems.item', 'branch']);
    }

    /**
     * Update schedule: add/remove items, update start_date, start_time.
     * Changes apply only to future generated tasks.
     */
    public function update(string $scheduleId, array $data): DailyInventorySchedule
    {
        $schedule = $this->repository->find($scheduleId, ['scheduleItems']);
        if (! $schedule) {
            throw new \InvalidArgumentException('Daily inventory schedule not found.');
        }

        return DB::transaction(function () use ($schedule, $data) {
            if (isset($data['start_date'])) {
                $schedule->start_date = $data['start_date'];
            }
            if (isset($data['start_time'])) {
                $schedule->start_time = $data['start_time'];
            }
            if (isset($data['is_active'])) {
                $schedule->is_active = $data['is_active'];
            }
            $schedule->save();

            if (isset($data['item_ids']) && is_array($data['item_ids'])) {
                $this->validateItemsBelongToBranch($schedule->branch_id, $data['item_ids']);
                $schedule->scheduleItems()->delete();
                $sortOrder = 0;
                foreach ($data['item_ids'] as $itemId) {
                    DailyInventoryScheduleItem::create([
                        'daily_inventory_schedule_id' => $schedule->id,
                        'item_id' => $itemId,
                        'sort_order' => $sortOrder++,
                    ]);
                }
            }

            return $schedule->fresh(['scheduleItems.item', 'branch']);
        });
    }

    /**
     * Delete all daily inventory configuration for a branch.
     * Stops generating new tasks; historical records are retained.
     */
    public function deleteForBranch(string $branchId): void
    {
        $schedule = $this->repository->findByBranch($branchId);
        if ($schedule) {
            $schedule->scheduleItems()->delete();
            $schedule->delete();
        }
    }
}
