<?php

namespace Modules\BrandOwner\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Models\ReviewAuditRequest;

class BrandOwnerFixedAssetsReviewAuditListResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var ReviewAuditRequest $r */
        $r = $this->resource;

        return [
            'id' => (string) $r->id,
            'branch_name' => (string) ($r->branch?->name ?? ''),
            'asset_name' => (string) ($r->asset_name_snapshot ?? ($r->asset?->name ?? '')),
            'status' => $r->status?->value ?? 'pending',
            'created_at' => $r->created_at?->toIso8601String() ?? '',
        ];
    }
}
