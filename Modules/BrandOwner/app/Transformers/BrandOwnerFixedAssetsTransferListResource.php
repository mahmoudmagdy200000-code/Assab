<?php

namespace Modules\BrandOwner\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Models\TransferDisposalItem;

class BrandOwnerFixedAssetsTransferListResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var TransferDisposalItem $i */
        $i = $this->resource;

        return [
            'id' => (string) $i->id,
            'asset_name' => (string) ($i->asset?->name ?? ''),
            'from_branch_name' => (string) ($i->request?->branch?->name ?? ''),
            'to_branch_name' => (string) ($i->request?->recipientBranch?->name ?? ''),
            'status' => $i->status?->value ?? 'pending',
            'created_at' => $i->created_at?->toIso8601String() ?? '',
        ];
    }
}
