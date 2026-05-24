<?php

namespace Modules\FixedAssets\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Models\TransferDisposalItem;

class DisposalDetailsResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var TransferDisposalItem $i */
        $i = $this->resource;
        $asset = $i->asset;
        $req = $i->request;

        return [
            'data' => [
                'request_details' => [
                    'disposal_id' => (string) $i->id,
                    'proposed_date' => $req?->disposal_date?->toDateString() ?? '',
                    'proposed_time' => (string) ($req?->disposal_time ?? ''),
                    'disposal_method' => $req?->disposal_method?->value ?? '',
                    'status' => $i->status?->value ?? '',
                    'approved_on' => $req?->approved_at?->toIso8601String(),
                    'branch_name' => (string) ($req?->branch?->name ?? ''),
                ],
                'asset' => [
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
                    'disposal_reason' => (string) ($i->disposal_reason ?? ''),
                    'condition_description' => (string) ($i->condition_description ?? ''),
                    'evidence_image_url' => $i->visualEvidence?->path
                        ? asset('storage/'.$i->visualEvidence->path)
                        : '',
                ],
                'timelines' => TimelineItemResource::collection($i->timelines ?? collect())->resolve($request),
            ],
        ];
    }
}
