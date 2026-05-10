<?php

namespace Modules\FixedAssets\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Models\PendingReceipt;

class ReceiveAssetsItemResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var PendingReceipt $row */
        $row = $this->resource;

        return [
            'id' => (string) $row->id,
            'assetName' => (string) $row->asset_name,
            'assetCode' => (string) $row->asset_code,
            'assetImage' => $row->asset_image ? asset('storage/'.$row->asset_image) : '',
        ];
    }
}
