<?php

namespace Modules\FixedAssets\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Models\TransferDisposalRequest;

class DisposalRequestListItemResource extends JsonResource
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
            'date_and_time' => $r->created_at?->toIso8601String() ?? '',
        ];
    }
}
