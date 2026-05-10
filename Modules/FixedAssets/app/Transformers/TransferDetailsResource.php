<?php

namespace Modules\FixedAssets\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Models\TransferDisposalItem;
use Modules\FixedAssets\Models\TransferDisposalRequest;

class TransferDetailsResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var TransferDisposalRequest $r */
        $r = $this->resource;

        $assets = $r->items->map(function (TransferDisposalItem $item) {
            $asset = $item->asset;

            return [
                'name' => (string) ($asset?->name ?? ''),
                'code' => (string) ($asset?->code ?? ''),
                'image_url' => $asset?->image ? asset('storage/'.$asset->image) : '',
                'zone' => [
                    'id' => (string) ($asset?->zone?->id ?? ''),
                    'name' => (string) ($asset?->zone?->name ?? ''),
                ],
                'type' => [
                    'id' => (string) ($asset?->assetType?->id ?? ''),
                    'name' => (string) ($asset?->assetType?->name ?? ''),
                ],
                'status' => $asset?->status?->value ?? '',
                'value' => (string) (float) ($asset?->value ?? 0),
                'custody' => $asset?->custody_started_at
                    ? $asset->custody_started_at->diffForHumans(now(), [
                        'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE,
                        'parts' => 1,
                    ])
                    : '',
                'transfer_reason' => (string) ($item->transfer_reason ?? ''),
            ];
        })->values()->all();

        return [
            'data' => [
                'request_details' => [
                    'from' => (string) ($r->branch?->name ?? ''),
                    'to' => (string) ($r->recipientBranch?->name ?? ''),
                    'request_date' => $r->created_at?->toDateString() ?? '',
                    'arrival_time' => $r->approved_at?->toIso8601String() ?? '',
                    'transfer_id' => (string) $r->id,
                    'status' => $r->status?->value ?? '',
                ],
                'assets' => $assets,
                'skip_confirmation' => (bool) $r->auto_approve,
                'timelines' => TimelineItemResource::collection($r->timelines)->resolve($request),
            ],
        ];
    }
}
