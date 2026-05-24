<?php

namespace Modules\BrandOwner\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class BrandOwnerSettingNotificationsResource extends JsonResource
{
    public function toArray($request): array
    {
        $rows = $this->resource;

        return [
            'notifications' => array_values(array_map(fn ($n) => [
                'type' => (string) $n->type,
                'enabled' => (bool) $n->enabled,
            ], is_array($rows) ? $rows : (array) $rows)),
        ];
    }
}
