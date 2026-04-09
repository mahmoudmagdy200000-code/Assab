<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonthlyInventoryListResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $staffCount = $this->whenLoaded('staff') ? $this->staff->count() : 0;
        return [
            'id' => $this->id,
            'inventory_number' => $this->inventory_number,
            'inventory_date' => $this->inventory_date->format('Y-m-d'),
            'start_time' => $this->start_time->format('H:i A'),
            'end_time' => $this->end_time?->format('Y-m-d H:i:s'),
            'time_taken' => $this->time_taken_formatted,
            'status' => $this->status->value,
            'status_label' => $this->status_label,
            'status_color' => $this->status_color,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch_id,
                'name' => $this->branch->name ?? null,
            ]),
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->created_by,
                'name' => $this->createdBy->name ?? null,
            ]),
            'performed_by' => 'Team (You & ' . max(0, $staffCount - 1) . ' Others)',
            'products_count' => $this->when(isset($this->products_count), $this->products_count),
            'completed_count' => $this->whenLoaded('products', fn () => $this->products->whereNotNull('counted_by_id')->count(), 0),
            'staff' => $this->whenLoaded('staff', fn () => $this->staff->map(fn ($s) => [
                'id' => $s->id,
                'user_id' => $s->user_id,
                'user_type' => $s->user_type,
                'role' => $s->role,
                'name' => $s->user?->name ?? null,
            ])->values()),
            'notes' => $this->notes,
            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
            'approved_at' => $this->approved_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'details' => (new MonthlyInventoryResource($this->resource))->resolve(),
        ];
    }
}
