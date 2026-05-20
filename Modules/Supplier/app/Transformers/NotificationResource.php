<?php

namespace Modules\Supplier\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'data' => $this->data ?? [],
            'channel' => $this->channel,
            'is_read' => $this->is_read,
            'read_at' => $this->read_at?->toDateTimeString(),
            'related_order_id' => $this->related_order_id,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
