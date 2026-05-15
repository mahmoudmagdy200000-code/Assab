<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Support\Facades\DB;
use Modules\FixedAssets\Enums\AssetStatus;
use Modules\FixedAssets\Models\FixedAsset;

class OverviewService
{
    public function __construct(
        private readonly HandoverService $handoverService,
        private readonly HandoverRecipientResolver $recipientResolver,
    ) {}

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
        $total = $excellent + $needAttention + $problem;

        $handover = $this->handoverService->activeHandoverForBranch($branchId);
        $handoverPayload = null;
        if ($handover) {
            $recipient = $handover->recipient()->first();
            $handoverPayload = [
                'id' => (string) $handover->id,
                'status' => $handover->status?->value,
                'recipientName' => $this->recipientResolver->displayName($recipient),
            ];
        }

        return [
            'handover' => $handoverPayload,
            'totalAssets' => $total,
            'totalAssetsExcellent' => $excellent,
            'totalAssetsMaintenance' => $needAttention,
            'totalAssetsProblem' => $problem,
        ];
    }
}
