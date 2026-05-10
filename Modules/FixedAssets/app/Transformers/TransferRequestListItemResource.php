<?php

namespace Modules\FixedAssets\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Enums\AssetStatus;
use Modules\FixedAssets\Enums\TransferDirection;
use Modules\FixedAssets\Models\TransferDisposalRequest;

class TransferRequestListItemResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var TransferDisposalRequest $r */
        $r = $this->resource;

        $firstAsset = $r->items->first()?->asset;

        return [
            'id' => (string) $r->id,
            'asset_name' => (string) ($firstAsset?->name ?? ''),
            'status' => $r->status?->value ?? '',
            'condition' => $firstAsset?->status?->value ?? AssetStatus::EXCELLENT->value,
            'date_and_time' => $r->created_at?->toIso8601String() ?? '',
            'type' => $r->direction?->value ?? TransferDirection::TO_BRANCH->value,
        ];
    }
}
