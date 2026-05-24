<?php

namespace Modules\FixedAssets\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Models\TransferDisposalItem;

class DisposalRequestListItemResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var TransferDisposalItem $i */
        $i = $this->resource;
        $asset = $i->asset;
        $req = $i->request;

        return [
            'id' => (string) $i->id,
            'asset_name' => (string) ($asset?->name ?? ''),
            'branch_name' => (string) ($req?->branch?->name ?? ''),
            'status' => $i->status?->value ?? '',
            'date_and_time' => $i->created_at?->toIso8601String() ?? '',
        ];
    }
}
