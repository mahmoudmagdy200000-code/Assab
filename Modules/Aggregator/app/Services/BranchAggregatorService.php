<?php

namespace Modules\Aggregator\Services;

use Modules\Aggregator\Models\BranchAggregator;
use Illuminate\Support\Facades\DB;

class BranchAggregatorService
{
    /**
     * Get all aggregators for a branch
     */
    public function getBranchAggregators(int $branchId): array
    {
        $branchAggregators = BranchAggregator::with('aggregator')
            ->where('branch_id', $branchId)
            ->get();

        return $branchAggregators->map(function ($ba) {
            return [
                'id' => $ba->aggregator->id,
                'name' => $ba->aggregator->name,
                'code' => $ba->aggregator->code,
                'logo' => $ba->aggregator->logo_url,
                'is_enabled' => $ba->is_enabled,
                'is_active' => $ba->aggregator->is_active,
                'commission_rate' => $ba->aggregator->commission_rate,
            ];
        })->toArray();
    }

    /**
     * Enable aggregator for branch
     */
    public function enableAggregator(int $branchId, int $aggregatorId): void
    {
        BranchAggregator::updateOrCreate(
            [
                'branch_id' => $branchId,
                'aggregator_id' => $aggregatorId,
            ],
            [
                'is_enabled' => true,
            ]
        );
    }

    /**
     * Disable aggregator for branch
     */
    public function disableAggregator(int $branchId, int $aggregatorId): void
    {
        BranchAggregator::where('branch_id', $branchId)
            ->where('aggregator_id', $aggregatorId)
            ->update(['is_enabled' => false]);
    }

    /**
     * Sync aggregators for branch
     */
    public function syncBranchAggregators(int $branchId, array $aggregatorIds): array
    {
        DB::beginTransaction();
        try {
            // Get current aggregators
            $currentAggregators = BranchAggregator::where('branch_id', $branchId)
                ->pluck('aggregator_id')
                ->toArray();

            // Add new aggregators
            $newAggregators = array_diff($aggregatorIds, $currentAggregators);
            foreach ($newAggregators as $aggregatorId) {
                BranchAggregator::create([
                    'branch_id' => $branchId,
                    'aggregator_id' => $aggregatorId,
                    'is_enabled' => true,
                ]);
            }

            // Remove old aggregators
            $removeAggregators = array_diff($currentAggregators, $aggregatorIds);
            BranchAggregator::where('branch_id', $branchId)
                ->whereIn('aggregator_id', $removeAggregators)
                ->delete();

            DB::commit();

            return [
                'added' => count($newAggregators),
                'removed' => count($removeAggregators),
                'current' => count($aggregatorIds),
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get enabled aggregators for branch
     */
    public function getEnabledAggregators(int $branchId): array
    {
        $branchAggregators = BranchAggregator::with('aggregator')
            ->where('branch_id', $branchId)
            ->where('is_enabled', true)
            ->whereHas('aggregator', function ($q) {
                $q->where('is_active', true);
            })
            ->get();

        return $branchAggregators->map(function ($ba) {
            return [
                'id' => $ba->aggregator->id,
                'name' => $ba->aggregator->name,
                'code' => $ba->aggregator->code,
                'logo' => $ba->aggregator->logo_url,
                'commission_rate' => $ba->aggregator->commission_rate,
            ];
        })->toArray();
    }

    /**
     * Toggle aggregator status for branch
     */
    public function toggleAggregator(int $branchId, int $aggregatorId): bool
    {
        $branchAggregator = BranchAggregator::where('branch_id', $branchId)
            ->where('aggregator_id', $aggregatorId)
            ->firstOrFail();

        $newStatus = !$branchAggregator->is_enabled;
        $branchAggregator->update(['is_enabled' => $newStatus]);

        return $newStatus;
    }
}
