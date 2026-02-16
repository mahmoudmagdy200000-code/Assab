<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class InventorySessionTimelineResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type->value,
            'event_label' => $this->event_label,
            'event_icon' => $this->event_icon,
            'title' => $this->title,
            'description' => $this->description,
            'actor_name' => $this->actor_name,
            'actor_role' => $this->actor_role,
            'occurred_at' => $this->occurred_at?->format('Y-m-d H:i:s'),
            'metadata' => $this->metadata,
        ];
    }
}
