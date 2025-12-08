<?php

namespace Modules\Cashier\Transformers;

use App\Http\Resources\BaseResource;

class CashierResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'image' => $this->formatImageUrl($this->image),
            'branch' => $this->formatNestedResource($this->whenLoaded('branch')),
            'status' => $this->formatStatus(),
            'created_by' => $this->formatNestedResource($this->whenLoaded('creator')),
            'shifts_count' => $this->getShiftsCount(),
            'activated_at' => $this->formatDate($this->activated_at),
            'timestamps' => $this->formatTimestamps(),
        ];
    }

    /**
     * Safely get shifts count
     */
    protected function getShiftsCount(): int
    {
        // First check if shifts_count was loaded via withCount
        if (isset($this->shifts_count) && $this->shifts_count !== null) {
            return (int) $this->shifts_count;
        }

        // Fallback to method call with error handling
        try {
            return $this->resource->getTotalShiftsCount();
        } catch (\Exception $e) {
            return 0;
        }
    }
}
