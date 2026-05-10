<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Support\Facades\DB;
use Modules\FixedAssets\Enums\AssetStatus;
use Modules\FixedAssets\Models\FixedAsset;

class OverviewService
{
    public function stats(string $branchId): array
    {
        $rows = FixedAsset::query()
            ->where('branch_id', $branchId)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $excellent = (int) ($rows[AssetStatus::EXCELLENT->value] ?? 0);
        $needAttention = (int) ($rows[AssetStatus::NEED_ATTENTION->value] ?? 0);
        $problem = (int) ($rows[AssetStatus::PROBLEM->value] ?? 0);

        return [
            'total' => $excellent + $needAttention + $problem,
            'excellent' => $excellent,
            'maintenance' => $needAttention,
            'problem' => $problem,
        ];
    }
}
