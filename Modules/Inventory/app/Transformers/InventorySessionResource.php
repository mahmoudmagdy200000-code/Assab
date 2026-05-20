<?php

namespace Modules\Inventory\Transformers;

use App\Http\Resources\UnifiedTimelineResource;
use Illuminate\Http\Resources\Json\JsonResource;

class InventorySessionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $performedBy = ($this->assigned_to_type === 'staff' && $this->assigned_to_id && $this->relationLoaded('assignedTo'))
            ? ['id' => $this->assigned_to_id, 'name' => $this->assignedTo?->name ?? null]
            : ['id' => $this->created_by, 'name' => $this->createdBy?->name ?? null];

        return [
            'id' => $this->id,
            'session_number' => $this->session_number,
            'branch' => [
                'id' => $this->branch_id,
                'name' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            ],
            'performed_by' => $performedBy,
            'created_by' => [
                'id' => $this->created_by,
                'name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            ],
            'assigned_to_type' => $this->assigned_to_type,
            'assigned_to' => $this->when($this->assigned_to_id, [
                'id' => $this->assigned_to_id,
                'name' => $this->whenLoaded('assignedTo', fn () => $this->assignedTo?->name),
                'email' => $this->whenLoaded('assignedTo', fn () => $this->assignedTo?->email),
            ]),
            'inventory_date' => $this->inventory_date?->format('Y-m-d'),
            'start_time' => $this->start_time?->format('Y-m-d H:i:s'),
            'end_time' => $this->end_time?->format('Y-m-d H:i:s'),
            'time_taken' => $this->time_taken_formatted,
            'time_taken_seconds' => $this->time_taken,
            'status' => $this->status->value,
            'status_label' => $this->status_label,
            'status_color' => $this->status_color,
            'notes' => $this->notes,
            'items_count' => $this->when(isset($this->items_count), $this->items_count),
            'items' => InventoryItemResource::collection($this->whenLoaded('items')),
            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
            'rejected_at' => $this->rejected_at?->format('Y-m-d H:i:s'),
            'rejection_comment' => $this->rejection_comment,
            'approved_at' => $this->approved_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
            'timelines' => $this->relationLoaded('timelines')
                ? UnifiedTimelineResource::collection($this->timelines)->resolve()
                : [],
        ];
    }
}
