<?php

namespace Modules\Inventory\Transformers;

use App\Http\Resources\UnifiedTimelineResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonthlyInventoryResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $products = $this->whenLoaded('products');
        $inventoriedProducts = $products instanceof \Illuminate\Database\Eloquent\Collection
            ? $products->whereNotNull('counted_by_id')
            : collect();
        $inventoriedCount = $inventoriedProducts->count();

        return [
            'id' => $this->id,
            'inventory_number' => $this->inventory_number,
            'branch' => [
                'id' => $this->branch_id,
                'name' => $this->branch->name ?? null,
            ],
            'created_by' => [
                'id' => $this->created_by,
                'name' => $this->createdBy->name ?? null,
            ],
            'inventory_date' => $this->inventory_date->format('Y-m-d'),
            'start_time' => $this->start_time->format('Y-m-d H:i:s'),
            'end_time' => $this->end_time?->format('Y-m-d H:i:s'),
            'time_taken' => $this->time_taken_formatted,
            'time_taken_seconds' => $this->time_taken,
            'number_of_products' => $this->number_of_products,
            'inventoried_count' => $inventoriedCount,
            'expected_time_minutes' => $this->expected_time_minutes,
            'status' => $this->status->value,
            'status_label' => $this->status_label,
            'status_color' => $this->status_color,
            'products_count' => $this->when(isset($this->products_count), $this->products_count),
            'staff' => $this->whenLoaded('staff', function () use ($inventoriedProducts) {
                return $this->staff->map(function ($s) use ($inventoriedProducts) {
                    $user = $s->user;

                    return [
                        'id' => $s->id,
                        'user_id' => $s->user_id,
                        'user_type' => $s->user_type,
                        'role' => $s->role,
                        'name' => $user?->name ?? null,
                        'products_counted' => $inventoriedProducts
                            ->where('counted_by_id', $s->user_id)
                            ->count(),
                    ];
                })->values();
            }),
            'products' => MonthlyInventoryProductResource::collection(
                $inventoriedProducts instanceof \Illuminate\Database\Eloquent\Collection
                    ? $inventoriedProducts->values()
                    : collect()
            ),
            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
            'approved_at' => $this->approved_at?->format('Y-m-d H:i:s'),
            'notes' => $this->notes,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
            'timelines' => $this->whenLoaded('timelines', fn () => UnifiedTimelineResource::collection($this->timelines)->resolve(), []),
        ];
    }
}
