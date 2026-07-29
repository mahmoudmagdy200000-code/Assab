<?php

namespace Modules\Aggregator\Services;

use Illuminate\Support\Facades\DB;
use Modules\Aggregator\Models\BranchAggregator;

class BranchAggregatorService
{
    /**
     * Get all aggregators for a branch
     */
    public function getBranchAggregators(string $branchId): array
    {
        $branchAggregators = BranchAggregator::with('aggregator')
            ->where('branch_id', $branchId)
            ->get();

        return $branchAggregators->map(function ($ba) {
            return [
                'id' => $ba->aggregator->id,
                // Strings stay strings even when unset — the mobile client casts
                // them and a null logo crashed the end-shift aggregator sheet.
                'name' => (string) $ba->aggregator->name,
                'code' => (string) $ba->aggregator->code,
                'logo' => (string) ($ba->aggregator->logo_url ?? ''),
                'is_enabled' => (bool) $ba->is_enabled,
                'is_active' => (bool) $ba->aggregator->is_active,
                'commission_rate' => (float) $ba->aggregator->commission_rate,
            ];
        })->toArray();
    }

    /**
     * Enable aggregator for branch
     */
    public function enableAggregator(string $branchId, string $aggregatorId): void
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
    public function disableAggregator(string $branchId, string $aggregatorId): void
    {
        BranchAggregator::where('branch_id', $branchId)
            ->where('aggregator_id', $aggregatorId)
            ->update(['is_enabled' => false]);
    }

    /**
     * Sync aggregators for branch
     */
    public function syncBranchAggregators(string $branchId, array $aggregatorIds): array
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
    public function getEnabledAggregators(string $branchId): array
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
    public function toggleAggregator(string $branchId, string $aggregatorId): bool
    {
        $branchAggregator = BranchAggregator::where('branch_id', $branchId)
            ->where('aggregator_id', $aggregatorId)
            ->firstOrFail();

        $newStatus = ! $branchAggregator->is_enabled;
        $branchAggregator->update(['is_enabled' => $newStatus]);

        return $newStatus;
    }
}
