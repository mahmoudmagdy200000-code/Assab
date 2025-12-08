<?php

namespace Modules\BranchManagers\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class BranchManagerResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'image' => $this->image_url,
            'branch' => $this->whenLoaded('branch', function () {
                return [
                    'id' => $this->branch->id ?? null,
                    'name' => $this->branch->name ?? null,
                    'location' => $this->branch->location ?? null,
                    'image' => $this->branch->image ? asset('storage/' . $this->branch->image) : null,
                ];
            }),
            'status' => [
                'value' => $this->status,
                // 'label' => $this->status_label,
                // 'color' => $this->status_color,
            ],
            'is_active' => $this->is_active,
            'is_first_login' => $this->is_first_login,
            'email_verified' => $this->isEmailVerified(),
            'phone_verified' => $this->isPhoneVerified(),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
