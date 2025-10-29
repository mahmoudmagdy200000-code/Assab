<?php

namespace Modules\Shift\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashierShiftResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'shift_date' => $this->shift_date->format('Y-m-d'),
            'status' => $this->status,
            'cashier' => [
                'id' => $this->cashier?->id,
                'name' => $this->cashier?->name,
            ],
            'shift' => [
                'id' => $this->shift?->id,
                'name' => $this->shift?->name,
                'start_time' => optional($this->shift?->start_time)->format('H:i'),
                'end_time' => optional($this->shift?->end_time)->format('H:i'),
                'opening_balance' => $this->opening_balance,
                'closing_balance' => $this->closing_balance,
                'actual_start_time' => optional($this->actual_start_time)->format('H:i'),
                'actual_end_time' => optional($this->actual_end_time)->format('H:i'),
                'cash_collected' => $this->cash_collected,
                'variances' => $this->variances,
                'assigned_by' => $this->assigned_by,
                'reAssigned_by' => $this->reassigned_by,
                'branch_id' => $this->shift?->branch_id,
                'is_active' => $this->shift?->is_active,
            ],
        ];
    }
}
