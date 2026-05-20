<?php

namespace App\Http\Resources;

class UserResource extends BaseResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'image' => $this->formatImageUrl($this->image),
            'status' => $this->formatStatus(),
            'is_active' => $this->formatBoolean($this->is_active),
            'email_verified_at' => $this->formatDate($this->email_verified_at),
            'phone_verified_at' => $this->formatDate($this->phone_verified_at),
            'timestamps' => $this->formatTimestamps(),
        ];
    }
}
