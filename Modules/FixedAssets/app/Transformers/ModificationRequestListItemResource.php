<?php

namespace Modules\FixedAssets\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Models\ModificationRequest;

class ModificationRequestListItemResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var ModificationRequest $r */
        $r = $this->resource;

        return [
            'id' => (string) $r->id,
            'asset_name' => (string) ($r->asset?->name ?? ''),
            'status' => $r->status?->value ?? '',
        ];
    }
}
