<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\FixedAssets\Enums\AssetStatus;
use Modules\FixedAssets\Enums\QuickSearchKey;
use Modules\FixedAssets\Enums\SearchType;
use Modules\FixedAssets\Models\AssetType;
use Modules\FixedAssets\Models\FixedAsset;

class AssetSearchService
{
    public function search(array $payload, string $branchId, ?string $authUserId): array
    {
        $base = FixedAsset::query()
            ->with(['zone', 'assetType', 'assignedTo'])
            ->where('branch_id', $branchId);

        $totalItems = (int) (clone $base)->count();

        $assetTypeName = '';
        $matching = clone $base;

        if (($payload['search_type'] ?? null) === SearchType::GENERAL->value) {
            $matching = $this->applyGeneralFilters($matching, $payload);

            $type = AssetType::find($payload['type_id'] ?? null);
            $assetTypeName = $type?->name ?? '';
        } else {
            $key = $payload['quick_search_key'] ?? null;
            $matching = $this->applyQuickFilter($matching, $key, $authUserId);
        }

        $items = (clone $matching)->orderBy('name')->get();
        $matchingCount = $items->count();

        $statusCounts = (clone $matching)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        return [
            'asset_type_name' => $assetTypeName,
            'summary' => [
                'total_items' => $totalItems,
                'total_items_matching_search' => $matchingCount,
                'total_items_matching_search_excellent_status' => (int) ($statusCounts[AssetStatus::EXCELLENT->value] ?? 0),
                'total_items_matching_search_maintenance_status' => (int) ($statusCounts[AssetStatus::NEED_ATTENTION->value] ?? 0),
                'total_items_matching_search_problem_status' => (int) ($statusCounts[AssetStatus::PROBLEM->value] ?? 0),
            ],
            'items' => $items,
        ];
    }

    public function searchByImage(string $branchId): array
    {
        $base = FixedAsset::query()
            ->with(['zone', 'assetType', 'assignedTo'])
            ->where('branch_id', $branchId);

        return [
            'asset_type_name' => '',
            'summary' => [
                'total_items' => (int) (clone $base)->count(),
                'total_items_matching_search' => 0,
                'total_items_matching_search_excellent_status' => 0,
                'total_items_matching_search_maintenance_status' => 0,
                'total_items_matching_search_problem_status' => 0,
            ],
            'items' => collect(),
        ];
    }

    private function applyGeneralFilters(Builder $q, array $payload): Builder
    {
        if (! empty($payload['zone_id'])) {
            $q->where('zone_id', $payload['zone_id']);
        }

        if (! empty($payload['type_id'])) {
            $q->where('asset_type_id', $payload['type_id']);
        }

        if (! empty($payload['employee_id'])) {
            $q->where('assigned_to_id', $payload['employee_id']);
        }

        if (! empty($payload['date'])) {
            $date = Carbon::parse($payload['date'])->toDateString();
            $q->whereDate('last_updated_at', $date);
        }

        return $q;
    }

    private function applyQuickFilter(Builder $q, ?string $key, ?string $authUserId): Builder
    {
        $today = Carbon::today();

        return match ($key) {
            QuickSearchKey::UPDATE_TODAY->value => $q->whereDate('last_updated_at', $today->toDateString()),
            QuickSearchKey::UPDATE_YESTERDAY->value => $q->whereDate('last_updated_at', $today->copy()->subDay()->toDateString()),
            QuickSearchKey::NOT_UPDATE_1_MONTH->value => $q->where(function ($qq) use ($today) {
                $qq->whereNull('last_updated_at')
                    ->orWhere('last_updated_at', '<', $today->copy()->subMonth());
            }),
            QuickSearchKey::NOT_UPDATE_2_MONTH->value => $q->where(function ($qq) use ($today) {
                $qq->whereNull('last_updated_at')
                    ->orWhere('last_updated_at', '<', $today->copy()->subMonths(2));
            }),
            QuickSearchKey::IN_MY_CUSTODY->value => $authUserId
                ? $q->where('assigned_to_id', $authUserId)
                : $q->whereRaw('1=0'),
            QuickSearchKey::UNDER_MAINTENANCE->value => $q->where('status', AssetStatus::NEED_ATTENTION->value),
            default => $q,
        };
    }
}
