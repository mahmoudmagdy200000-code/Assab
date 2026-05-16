<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Support\Carbon;
use Modules\FixedAssets\Enums\AssetStatus;
use Modules\FixedAssets\Enums\RequestStatus;
use Modules\FixedAssets\Enums\TransferDisposalKind;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\ModificationRequest;
use Modules\FixedAssets\Models\TransferDisposalItem;

class AssetHistoryService
{
    public function findForBranch(string $assetId, string $branchId): ?FixedAsset
    {
        return FixedAsset::query()
            ->with(['zone', 'assetType', 'branch'])
            ->where('id', $assetId)
            ->where('branch_id', $branchId)
            ->first();
    }

    public function buildPayload(FixedAsset $asset): array
    {
        $payload = [
            'assetId' => (string) $asset->id,
            'assetName' => (string) $asset->name,
            'assetCode' => (string) $asset->code,
            'location' => (string) ($asset->zone?->name ?? ''),
            'assetImage' => $asset->image ? asset('storage/'.$asset->image) : '',
        ];

        $photos = $this->buildPhotos($asset);
        if ($photos !== null) {
            $payload['photos'] = $photos;
        }

        $statusHistory = $this->buildStatusHistory($asset);
        if ($statusHistory !== null) {
            $payload['statusHistory'] = $statusHistory;
        }

        $transfers = $this->buildTransfers($asset);
        if ($transfers !== null) {
            $payload['transfers'] = $transfers;
        }

        $payload['custody'] = null;

        $financial = $this->buildFinancial($asset);
        if ($financial !== null) {
            $payload['financial'] = $financial;
        }

        return $payload;
    }

    private function buildPhotos(FixedAsset $asset): ?array
    {
        $history = $asset->photoHistory()
            ->orderByDesc('uploaded_at')
            ->orderByDesc('created_at')
            ->get();

        $photos = [];

        if ($asset->image) {
            $photos[] = [
                'id' => 'current',
                'imageUrl' => asset('storage/'.$asset->image),
                'updatedBy' => '',
                'date' => optional($asset->last_updated_at ?? $asset->updated_at)->toIso8601String() ?? '',
                'isCurrent' => true,
            ];
        }

        foreach ($history as $row) {
            $photos[] = [
                'id' => (string) $row->id,
                'imageUrl' => $row->path ? asset('storage/'.$row->path) : '',
                'updatedBy' => '',
                'date' => optional($row->uploaded_at ?? $row->created_at)->toIso8601String() ?? '',
                'isCurrent' => false,
            ];
        }

        if (empty($photos)) {
            return null;
        }

        return [
            'photos' => $photos,
            'totalCount' => count($photos),
        ];
    }

    private function buildStatusHistory(FixedAsset $asset): ?array
    {
        $rows = ModificationRequest::query()
            ->where('asset_id', $asset->id)
            ->where('status', RequestStatus::APPROVED->value)
            ->orderByDesc('approved_at')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $statuses = $rows->map(function (ModificationRequest $r) {
            $status = $r->new_status instanceof AssetStatus ? $r->new_status : null;

            return [
                'id' => (string) $r->id,
                'date' => optional($r->approved_at ?? $r->updated_at)->toIso8601String() ?? '',
                'note' => (string) ($r->reason ?? ''),
                'status' => $status?->value ?? '',
                'statusColor' => $this->statusColor($status),
            ];
        })->all();

        return [
            'statuses' => $statuses,
            'totalCount' => count($statuses),
        ];
    }

    private function buildTransfers(FixedAsset $asset): ?array
    {
        $items = TransferDisposalItem::query()
            ->with(['request.branch', 'request.recipientBranch'])
            ->where('asset_id', $asset->id)
            ->whereHas('request', function ($q) {
                $q->whereIn('kind', [
                    TransferDisposalKind::TRANSFER_TO_BRANCH->value,
                    TransferDisposalKind::EXTERNAL_TRANSFER->value,
                ])->where('status', RequestStatus::APPROVED->value);
            })
            ->get();

        if ($items->isEmpty()) {
            return null;
        }

        $transfers = $items
            ->sortByDesc(fn ($i) => $i->request?->approved_at)
            ->values()
            ->map(function (TransferDisposalItem $item) {
                $req = $item->request;
                $approvedAt = $req?->approved_at;

                return [
                    'id' => (string) $item->id,
                    'date' => optional($approvedAt)->toIso8601String() ?? '',
                    'label' => 'Transfer',
                    'fromLocation' => (string) ($req?->branch?->name ?? ''),
                    'toLocation' => (string) ($req?->recipientBranch?->name ?? ''),
                ];
            })
            ->all();

        return [
            'transfers' => $transfers,
            'totalCount' => count($transfers),
        ];
    }

    private function buildFinancial(FixedAsset $asset): ?array
    {
        $items = [];

        $items[] = [
            'label' => 'Purchase Value',
            'value' => $this->money($asset->value),
        ];

        if ($asset->acquired_at) {
            $items[] = [
                'label' => 'Acquisition Date',
                'value' => $asset->acquired_at->toDateString(),
            ];
            $items[] = [
                'label' => 'Age',
                'value' => $asset->acquired_at->diffForHumans(now(), [
                    'syntax' => Carbon::DIFF_ABSOLUTE,
                    'parts' => 2,
                ]),
            ];
        }

        if ($asset->last_updated_at) {
            $items[] = [
                'label' => 'Last Updated',
                'value' => $asset->last_updated_at->toIso8601String(),
            ];
        }

        if (empty($items)) {
            return null;
        }

        return ['items' => $items];
    }

    private function statusColor(?AssetStatus $status): string
    {
        return match ($status) {
            AssetStatus::EXCELLENT => '#22C55E',
            AssetStatus::NEED_ATTENTION => '#F59E0B',
            AssetStatus::PROBLEM => '#EF4444',
            default => '',
        };
    }

    private function money(mixed $value): string
    {
        $amount = number_format((float) $value, 2, '.', ',');

        return $amount.' SAR';
    }
}
