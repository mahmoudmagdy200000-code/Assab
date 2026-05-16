<?php

namespace Modules\FixedAssets\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class AssetHistoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return is_array($this->resource) ? $this->resource : [];
    }
}
