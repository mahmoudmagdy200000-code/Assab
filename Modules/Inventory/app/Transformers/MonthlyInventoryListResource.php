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
            'status' => $this->status->value,
            'status_label' => $this->status_label,
            'status_color' => $this->status_color,
            'performed_by' => 'Team (You & ' . max(0, $staffCount - 1) . ' Others)',
            'products_count' => $this->when(isset($this->products_count), $this->products_count),
        ];
    }
}
