<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonthlyInventoryTimelineResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type?->value,
            'event_label' => $this->event_label,
            'event_icon' => $this->event_icon,
            'old_status' => $this->old_status,
            'new_status' => $this->new_status,
            'actor' => [
                'id' => $this->actor_id,
                'type' => $this->actor_type,
                'name' => $this->actor_name,
                'image_url' => $this->actor_image_url,
                'role' => $this->actor_role,
            ],
            'title' => $this->title,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
