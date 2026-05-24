<?php

namespace Modules\BrandOwner\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Enums\FixedAssetsCondition;
use Modules\FixedAssets\Enums\TransferDisposalKind;
use Modules\FixedAssets\Models\TransferDisposalItem;
use Modules\FixedAssets\Transformers\TimelineItemResource;

class BrandOwnerFixedAssetsDisposalDetailsResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var TransferDisposalItem $i */
        $i = $this->resource;
        $asset = $i->asset;
        $req = $i->request;

        $kind = $req?->kind?->value;
        $type = $kind === TransferDisposalKind::DISPOSAL->value ? 'disposal' : 'external_transfer';

        return [
            'id' => (string) $i->id,
            'status' => $i->status?->value ?? 'pending',
            'branch_name' => (string) ($req?->branch?->name ?? ''),
            'type' => $type,
            'approved_on' => $i->bo_decided_at?->toIso8601String(),
            'submitted_by_branch_name' => (string) ($req?->branch?->name ?? ''),
            'submitted_by_manager_name' => (string) ($req?->requestedBy?->name ?? ''),
            'submitted_on' => $req?->created_at?->toIso8601String() ?? '',
            'cancellation' => $i->cancellation,
            'asset' => [
                'image_url' => $asset?->image ? asset('storage/'.$asset->image) : '',
                'name' => (string) ($asset?->name ?? ''),
                'code' => (string) ($asset?->code ?? ''),
                'zone_name' => (string) ($asset?->zone?->name ?? '-'),
                'condition' => FixedAssetsCondition::fromAny($asset?->status?->value)->value,
                'reason_for_disposal' => (string) ($i->disposal_reason ?? $i->transfer_reason ?? '-'),
                'affected_value' => $asset?->value !== null ? (string) $asset->value : '-',
                'evidence_image_url' => $i->visualEvidence?->path
                    ? asset('storage/'.$i->visualEvidence->path)
                    : '',
                'additional_notes' => (string) ($i->condition_description ?? '-'),
            ],
            'timelines' => TimelineItemResource::collection($i->timelines ?? collect())->resolve(),
        ];
    }
}
