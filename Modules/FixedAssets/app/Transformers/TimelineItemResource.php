<?php

namespace Modules\FixedAssets\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Models\Timeline;

class TimelineItemResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var Timeline $t */
        $t = $this->resource;

        return [
            'id' => (string) $t->id,
            'event_type' => $t->event_type?->value ?? '',
            'name' => (string) $t->name,
            'image' => $t->actor_image_path ? asset('storage/'.$t->actor_image_path) : '',
            'occurred_at' => $t->occurred_at?->toIso8601String() ?? '',
        ];
    }
}
