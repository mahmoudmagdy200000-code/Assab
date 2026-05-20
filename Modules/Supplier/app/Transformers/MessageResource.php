<?php

namespace Modules\Supplier\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'order_id' => $this->order_id,
            'message' => $this->message,
            'sender_type' => $this->sender_type,
            'is_read' => $this->is_read,
            'read_at' => $this->read_at?->toDateTimeString(),
            'attachments' => $this->attachments ?? [],
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
