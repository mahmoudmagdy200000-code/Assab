<?php

namespace Modules\Cashier\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class CashierDetailResource extends JsonResource
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
                'location' => $this->branch->location ?? null,
                'lat' => $this->branch->lat ? (float) $this->branch->lat : null,
                'lng' => $this->branch->lng ? (float) $this->branch->lng : null,
                'image' => $this->branch->image ? asset('storage/' . $this->branch->image) : null,
            ],

            'status' => [
                'value' => $this->status,
             
            ],

            'created_by' => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ],

            'shifts' => [
                'total' => $this->getTotalShiftsCount(),
                'completed' => $this->getCompletedShiftsCount(),
                'current' => $this->when($this->getCurrentShift(), function () {
                    $shift = $this->getCurrentShift();
                    return [
                        'id' => $shift->id,
                        'shift_name' => $shift->shift->name,
                        'start_time' => $shift->actual_start_time?->format('H:i'),
                        'status' => $shift->status->label(),
                    ];
                }),
                'next' => $this->when($this->getNextShift(), function () {
                    $shift = $this->getNextShift();
                    return [
                        'id' => $shift->id,
                        'shift_name' => $shift->shift->name,
                        'shift_date' => $shift->shift_date->format('Y-m-d'),
                        'start_time' => $shift->shift->start_time,
                    ];
                }),
            ],

            'statistics' => [
                'total_sales' => (float) $this->getTotalSales(),
                'total_variance' => (float) $this->getTotalVariance(),
                'completed_shifts' => $this->getCompletedShiftsCount(),
            ],

            'timestamps' => [
                'activated_at' => $this->activated_at?->format('Y-m-d H:i:s'),
                'deactivated_at' => $this->deactivated_at?->format('Y-m-d H:i:s'),
                'created_at' => $this->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
            ],
        ];
    }
}
