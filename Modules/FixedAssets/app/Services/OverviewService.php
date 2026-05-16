<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Support\Facades\DB;
use Modules\FixedAssets\Enums\AssetStatus;
use Modules\FixedAssets\Enums\RecipientInspectionResult;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\Handover;

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

        $handover = $this->latestHandoverForBranch($branchId);
        $handoverPayload = null;
        $rejectedAssets = [];

        if ($handover) {
            $recipient = $handover->recipient()->first();
            $viewer = auth()->user();
            $viewerId = $viewer ? (string) $viewer->getKey() : '';
            $viewerType = $viewer?->getMorphClass();

            $type = (string) $handover->sender_id === $viewerId
                ? 'sender'
                : (((string) $handover->recipient_id === $viewerId && $handover->recipient_type === $viewerType) ? 'receiver' : 'sender');

            $handoverPayload = [
                'id' => (string) $handover->id,
                'status' => $handover->status?->value,
                'type' => $type,
                'recipientName' => $this->recipientResolver->displayName($recipient),
            ];

            $problemItems = $handover->items()
                ->where('recipient_inspection', RecipientInspectionResult::PROBLEM->value)
                ->orderBy('asset_name_snapshot')
                ->get();

            foreach ($problemItems as $item) {
                $rejectedAssets[] = [
                    'id' => (string) $item->asset_id,
                    'name' => (string) $item->asset_name_snapshot,
                    'code' => (string) $item->asset_code_snapshot,
                    'zoneName' => (string) ($item->zone_name_snapshot ?? ''),
                    'imageUrl' => $item->asset_image_snapshot ? asset('storage/'.$item->asset_image_snapshot) : '',
                    'assetType' => (string) ($item->asset_type_name_snapshot ?? ''),
                    'age' => $this->handoverService->ageLabel($item->acquired_at_snapshot),
                    'handoverDescription' => (string) ($item->recipient_note ?? ''),
                ];
            }
        }

        return [
            'handover' => $handoverPayload,
            'rejectedAssets' => $rejectedAssets,
            'totalAssets' => $total,
            'totalAssetsExcellent' => $excellent,
            'totalAssetsMaintenance' => $needAttention,
            'totalAssetsProblem' => $problem,
        ];
    }

    private function latestHandoverForBranch(string $branchId): ?Handover
    {
        return Handover::query()
            ->where('branch_id', $branchId)
            ->orderByDesc('started_at')
            ->orderByDesc('created_at')
            ->first();
    }
}
