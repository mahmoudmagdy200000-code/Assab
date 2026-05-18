<?php

namespace Modules\BranchManagers\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BmInventoryTimelineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $eventType = is_object($this->event_type) && method_exists($this->event_type, 'value')
            ? $this->event_type->value
            : (string) $this->event_type;

        return [
            'id' => $this->id,
            'event_type' => $eventType,
            'name' => $this->actor_name ?? $this->title,
            'image' => $this->actor_image
                ? (str_starts_with($this->actor_image, 'http') ? $this->actor_image : asset('storage/' . $this->actor_image))
                : null,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
