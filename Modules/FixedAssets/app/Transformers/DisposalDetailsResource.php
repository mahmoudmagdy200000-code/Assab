<?php

namespace Modules\FixedAssets\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Models\TransferDisposalItem;
use Modules\FixedAssets\Models\TransferDisposalRequest;

class DisposalDetailsResource extends JsonResource
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
                'transfer_reason' => (string) ($item->disposal_reason ?? ''),
            ];
        })->values()->all();

        return [
            'data' => [
                'request_details' => [
                    'proposed_date' => $r->disposal_date?->toDateString() ?? '',
                    'proposed_time' => (string) ($r->disposal_time ?? ''),
                    'disposal_method' => $r->disposal_method?->value ?? '',
                    'selected_assets' => $r->items->count(),
                    'status' => $r->status?->value ?? '',
                    'approved_on' => $r->approved_at?->toIso8601String(),
                ],
                'assets' => $assets,
                'timelines' => TimelineItemResource::collection($r->timelines)->resolve($request),
            ],
        ];
    }
}
