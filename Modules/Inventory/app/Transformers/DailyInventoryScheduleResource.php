<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class DailyInventoryScheduleResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'start_date' => $this->start_date?->format('Y-m-d'),
            'start_time' => $this->start_time, // TIME column as string "HH:MM:SS"
            'is_active' => $this->is_active,
            'created_by' => $this->created_by,
            'items' => $this->whenLoaded('scheduleItems', function () {
                return $this->scheduleItems->sortBy('sort_order')->map(function ($si) {
                    return [
                        'id' => $si->id,
                        'item_id' => $si->item_id,
                        'sort_order' => $si->sort_order,
                        'item' => $si->relationLoaded('item') ? [
                            'id' => $si->item->id,
                            'name' => $si->item->name,
                            'code' => $si->item->code,
                            'unit' => $si->item->unit,
                            'logo' => $si->item->logo,
                            'category' => $si->item->category,
                            'subcategory' => $si->item->subcategory,
                        ] : null,
                    ];
                })->values();
            }),
            'items_count' => $this->whenLoaded('scheduleItems', fn () => $this->scheduleItems->count()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
