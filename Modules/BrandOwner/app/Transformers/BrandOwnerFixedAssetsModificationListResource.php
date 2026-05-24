<?php

namespace Modules\BrandOwner\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Models\ModificationRequest;

class BrandOwnerFixedAssetsModificationListResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var ModificationRequest $m */
        $m = $this->resource;

        return [
            'id' => (string) $m->id,
            'branch_name' => (string) ($m->branch?->name ?? ''),
            'asset_name' => (string) ($m->asset?->name ?? ''),
            'status' => $m->status?->value ?? 'pending',
            'created_at' => $m->created_at?->toIso8601String() ?? '',
        ];
    }
}
