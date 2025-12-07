<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class TimelineResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type?->value,
            'event_label' => $this->event_label,
            'event_icon' => $this->event_icon,
            
            // Status change
            'old_status' => $this->old_status,
            'new_status' => $this->new_status,
            
            // Actor
            'actor' => [
                'id' => $this->actor_id,
                'name' => $this->actor_name,
                'image' => $this->actor_image_url,
                'role' => $this->actor_role,
            ],
            
            // Event details
            'title' => $this->title,
            'description' => $this->description,
            'metadata' => $this->metadata,
            
            // Timestamp
            'occurred_at' => $this->occurred_at?->format('Y-m-d H:i:s'),
            'occurred_at_human' => $this->occurred_at?->diffForHumans(),
        ];
    }
}

