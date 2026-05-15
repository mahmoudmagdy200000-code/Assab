<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Support\Collection;
use Modules\FixedAssets\Enums\AssetStatus;
use Modules\FixedAssets\Enums\HandoverStatus;
use Modules\FixedAssets\Enums\RecipientInspectionResult;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\Handover;
use Modules\FixedAssets\Models\HandoverItem;

class HandoverIncludedZonesService
{
    public function forBranch(string $branchId, string $statusFilter = 'global'): array
    {
        $statusFilter = $statusFilter === 'rejected' ? 'rejected' : 'global';

        if ($statusFilter === 'rejected') {
            return $this->rejectedFromLastCompleted($branchId);
        }

        return $this->globalForBranch($branchId);
    }

    private function globalForBranch(string $branchId): array
    {
        $assets = FixedAsset::query()
            ->with(['zone'])
            ->where('branch_id', $branchId)
            ->whereNotNull('zone_id')
            ->get();

        return $this->buildResponse($assets->groupBy('zone_id'), $assets->sum(fn ($a) => (float) $a->value));
    }

    private function rejectedFromLastCompleted(string $branchId): array
    {
        $handover = Handover::query()
            ->where('branch_id', $branchId)
            ->where('status', HandoverStatus::COMPLETED->value)
            ->latest('completed_at')
            ->first();

        if (! $handover) {
            return [
                'totalIncludedItems' => 0,
                'totalValueOfIncludedZones' => 0,
                'includedZones' => [],
            ];
        }

        $items = $handover->items()
            ->where('recipient_inspection', RecipientInspectionResult::PROBLEM->value)
            ->whereNotNull('zone_id')
            ->get();

        $assetIds = $items->pluck('asset_id')->unique()->all();
        $assets = FixedAsset::query()
            ->with('zone')
            ->whereIn('id', $assetIds)
            ->get()
            ->keyBy('id');

        $groups = $items->groupBy('zone_id')->map(function ($zoneItems) use ($assets) {
            return $zoneItems
                ->map(fn (HandoverItem $i) => $assets->get($i->asset_id))
                ->filter()
                ->values();
        });

        $totalValue = $items->sum(fn (HandoverItem $i) => (float) ($assets[$i->asset_id]?->value ?? 0));

        return $this->buildResponse($groups, $totalValue);
    }

    private function buildResponse(Collection $groupedByZoneId, float $totalValue): array
    {
        $zones = [];
        $totalItems = 0;

        foreach ($groupedByZoneId as $zoneId => $assets) {
            if (! $assets || count($assets) === 0) {
                continue;
            }

            /** @var FixedAsset $first */
            $first = $assets->first();
            $zoneName = $first->zone?->name ?? '';

            $assetsPayload = [];
            foreach ($assets as $asset) {
                $assetsPayload[] = [
                    'assetId' => (string) $asset->id,
                    'assetName' => (string) $asset->name,
                    'assetImageURL' => $asset->image ? asset('storage/'.$asset->image) : '',
                    'totalQuantityInZone' => 1,
                    'totalExcellentConditionQuantityInZone' => $asset->status === AssetStatus::EXCELLENT ? 1 : 0,
                    'totalNeedAttentionConditionQuantityInZone' => $asset->status === AssetStatus::NEED_ATTENTION ? 1 : 0,
                    'totalProblemConditionQuantityInZone' => $asset->status === AssetStatus::PROBLEM ? 1 : 0,
                ];
                $totalItems++;
            }

            $zones[] = [
                'zoneID' => (string) $zoneId,
                'zoneName' => (string) $zoneName,
                'assetsInZone' => $assetsPayload,
            ];
        }

        return [
            'totalIncludedItems' => $totalItems,
            'totalValueOfIncludedZones' => round($totalValue, 2),
            'includedZones' => $zones,
        ];
    }

    public function activeSessionZones(Handover $handover): array
    {
        $items = $handover->items()->get();
        $zoneIds = $items->pluck('zone_id')->filter()->unique();

        $zones = [];
        $totalItems = 0;
        $totalValue = 0.0;

        foreach ($zoneIds as $zoneId) {
            $zoneItems = $items->where('zone_id', $zoneId)->values();
            if ($zoneItems->isEmpty()) {
                continue;
            }

            $assetsPayload = [];
            foreach ($zoneItems as $item) {
                $excellent = $item->recipient_inspection === RecipientInspectionResult::EXCELLENT ? 1 : 0;
                $needAttention = $item->recipient_inspection === RecipientInspectionResult::NEED_ATTENTION ? 1 : 0;
                $problem = $item->recipient_inspection === RecipientInspectionResult::PROBLEM ? 1 : 0;

                $assetsPayload[] = [
                    'assetId' => (string) $item->asset_id,
                    'assetName' => (string) $item->asset_name_snapshot,
                    'assetImageURL' => $item->asset_image_snapshot ? asset('storage/'.$item->asset_image_snapshot) : '',
                    'totalQuantityInZone' => (int) $item->current_qty,
                    'totalExcellentConditionQuantityInZone' => $excellent,
                    'totalNeedAttentionConditionQuantityInZone' => $needAttention,
                    'totalProblemConditionQuantityInZone' => $problem,
                    'assetCode' => (string) $item->asset_code_snapshot,
                    'currentQty' => (int) $item->current_qty,
                    'newQty' => $item->new_qty !== null ? (int) $item->new_qty : null,
                    'recipientInspection' => $item->recipient_inspection?->value,
                    'recipientNote' => $item->recipient_note,
                    'recipientPhotoUrl' => $item->recipient_photo_path ? asset('storage/'.$item->recipient_photo_path) : null,
                ];

                $totalItems++;
                $totalValue += (float) $item->value_snapshot;
            }

            $zones[] = [
                'zoneID' => (string) $zoneId,
                'zoneName' => (string) ($zoneItems->first()->zone_name_snapshot ?? ''),
                'assetsInZone' => $assetsPayload,
            ];
        }

        return [
            'totalIncludedItems' => $totalItems,
            'totalValueOfIncludedZones' => round($totalValue, 2),
            'includedZones' => $zones,
        ];
    }
}
