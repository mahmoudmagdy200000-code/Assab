<?php

namespace Modules\BrandOwner\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Enums\FixedAssetsCondition;
use Modules\FixedAssets\Models\TransferDisposalItem;
use Modules\FixedAssets\Transformers\TimelineItemResource;

class BrandOwnerFixedAssetsTransferDetailsResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var TransferDisposalItem $i */
        $i = $this->resource;
        $asset = $i->asset;
        $req = $i->request;

        $approvedOn = $i->bo_decided_at?->toIso8601String();
        $value = $asset?->value !== null ? (string) $asset->value : '-';

        return [
            'id' => (string) $i->id,
            'status' => $i->status?->value ?? 'pending',
            'from_branch_name' => (string) ($req?->branch?->name ?? ''),
            'to_branch_name' => (string) ($req?->recipientBranch?->name ?? ''),
            'approved_on' => $approvedOn,
            'submitted_by_manager_name' => (string) ($req?->requestedBy?->name ?? ''),
            'supported_by_manager_name' => (string) ($req?->recipientBranch?->name ?? ''),
            'submitted_on' => $req?->created_at?->toIso8601String() ?? '',
            'cancellation' => $i->cancellation,
            'asset' => [
                'image_url' => $asset?->image ? asset('storage/'.$asset->image) : '',
                'name' => (string) ($asset?->name ?? ''),
                'code' => (string) ($asset?->code ?? ''),
                'condition' => FixedAssetsCondition::fromAny($asset?->status?->value)->value,
                'is_critical' => false,
                'affected_value' => $value,
                'last_service' => '-',
                'brand_standard' => '-',
            ],
            'timelines' => TimelineItemResource::collection($i->timelines ?? collect())->resolve(),
        ];
    }
}
