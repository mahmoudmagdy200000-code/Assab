<?php

namespace Modules\FixedAssets\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Models\Handover;

class HandoverRequestListItemResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var Handover $h */
        $h = $this->resource;

        $managerId = (string) ($request->user()?->getKey() ?? '');
        $type = (string) $h->sender_id === $managerId ? 'sender' : 'receiver';

        return [
            'id' => (string) $h->id,
            'type' => $type,
            'status' => $h->status?->value ?? '',
            'created_at' => $h->created_at?->toIso8601String() ?? '',
            'updated_at' => $h->updated_at?->toIso8601String() ?? '',
        ];
    }
}
