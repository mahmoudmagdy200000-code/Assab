<?php

namespace Modules\FixedAssets\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class ResponsibleEmployeeResource extends JsonResource
{
    public function toArray($request): array
    {
        $row = is_array($this->resource) ? $this->resource : $this->resource->toArray();

        return [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'image' => (string) ($row['image'] ?? ''),
        ];
    }
}
