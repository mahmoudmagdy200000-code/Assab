<?php

namespace Modules\BranchManagers\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class BranchManagerDetailResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'image' => $this->image_url,

            'branch' => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'location' => $this->branch->location,
                'image' => $this->branch->image ? asset('storage/' . $this->branch->image) : null,
                'opening_hours' => $this->branch->opening_hours,
                'map_coordinates' => $this->branch->map_coordinates,
            ],

            'status' => [
                'value' => $this->status,
                // 'label' => $this->status_label,
                // 'color' => $this->status_color,
            ],

            'verification' => [
                'email_verified' => $this->isEmailVerified(),
                'email_verified_at' => $this->email_verified_at?->format('Y-m-d H:i:s'),
                'phone_verified' => $this->isPhoneVerified(),
                'phone_verified_at' => $this->phone_verified_at?->format('Y-m-d H:i:s'),
            ],

            'flags' => [
                'is_active' => $this->is_active,
                'is_first_login' => $this->is_first_login,
            ],

            'statistics' => [
                'total_cashiers' => $this->getTotalCashiers(),
                'active_cashiers' => $this->getActiveCashiers(),
                'today_shifts' => $this->getTodayShifts(),
                'total_expenses' => (float) $this->getTotalExpenses(),
            ],

            'timestamps' => [
                'created_at' => $this->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
            ],
        ];
    }
}
