<?php

namespace Modules\BranchManagers\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Aggregator\Models\Aggregator;
use Modules\Aggregator\Models\BranchAggregator;
use Modules\BranchManagers\Exceptions\BranchManagerSettingsException;

/**
 * Data access for the aggregators a branch can add, has added, and toggles
 * on the branch manager settings screen.
 *
 * Every returned Aggregator carries an `enabled_for_branch` attribute so the
 * transformer can render the `enabled` flag without re-querying the pivot.
 */
class BranchManagerAggregatorService
{
    /**
     * Active aggregators not yet added to the branch.
     *
     * @return Collection<int, Aggregator>
     */
    public function available(string $branchId): Collection
    {
        $addedIds = BranchAggregator::query()
            ->where('branch_id', $branchId)
            ->pluck('aggregator_id');

        return Aggregator::query()
            ->active()
            ->whereNotIn('id', $addedIds)
            ->orderBy('name')
            ->get()
            ->each(fn (Aggregator $aggregator) => $aggregator->enabled_for_branch = false);
    }

    /**
     * Aggregators already added to the branch.
     *
     * @return Collection<int, Aggregator>
     */
    public function assigned(string $branchId): Collection
    {
        return BranchAggregator::query()
            ->with('aggregator')
            ->where('branch_id', $branchId)
            ->get()
            ->map(function (BranchAggregator $pivot): ?Aggregator {
                $aggregator = $pivot->aggregator;

                // The aggregator may have been soft deleted after assignment.
                if ($aggregator === null) {
                    return null;
                }

                $aggregator->enabled_for_branch = (bool) $pivot->is_enabled;

                return $aggregator;
            })
            ->filter()
            ->sortBy('name')
            ->values();
    }

    /**
     * Add an aggregator to the branch. Newly added aggregators are enabled.
     */
    public function add(string $branchId, string $aggregatorId): Aggregator
    {
        $aggregator = Aggregator::query()->findOrFail($aggregatorId);

        return DB::transaction(function () use ($branchId, $aggregatorId, $aggregator): Aggregator {
            $alreadyAdded = BranchAggregator::query()
                ->where('branch_id', $branchId)
                ->where('aggregator_id', $aggregatorId)
                ->lockForUpdate()
                ->exists();

            if ($alreadyAdded) {
                throw BranchManagerSettingsException::aggregatorAlreadyAdded();
            }

            BranchAggregator::query()->create([
                'branch_id' => $branchId,
                'aggregator_id' => $aggregatorId,
                'is_enabled' => true,
            ]);

            $aggregator->enabled_for_branch = true;

            return $aggregator;
        });
    }

    /**
     * Remove an aggregator from the branch.
     */
    public function remove(string $branchId, string $aggregatorId): Aggregator
    {
        $aggregator = Aggregator::query()->findOrFail($aggregatorId);

        $pivot = $this->pivotOrFail($branchId, $aggregatorId);
        $enabled = (bool) $pivot->is_enabled;

        $pivot->delete();

        $aggregator->enabled_for_branch = $enabled;

        return $aggregator;
    }

    /**
     * Enable or disable an aggregator already added to the branch.
     */
    public function setEnabled(string $branchId, string $aggregatorId, bool $enabled): Aggregator
    {
        $aggregator = Aggregator::query()->findOrFail($aggregatorId);

        $pivot = $this->pivotOrFail($branchId, $aggregatorId);
        $pivot->update(['is_enabled' => $enabled]);

        $aggregator->enabled_for_branch = $enabled;

        return $aggregator;
    }

    /**
     * Count of aggregators added to the branch.
     */
    public function countForBranch(string $branchId): int
    {
        return BranchAggregator::query()
            ->where('branch_id', $branchId)
            ->count();
    }

    private function pivotOrFail(string $branchId, string $aggregatorId): BranchAggregator
    {
        $pivot = BranchAggregator::query()
            ->where('branch_id', $branchId)
            ->where('aggregator_id', $aggregatorId)
            ->first();

        if ($pivot === null) {
            throw BranchManagerSettingsException::aggregatorNotAdded();
        }

        return $pivot;
    }
}
