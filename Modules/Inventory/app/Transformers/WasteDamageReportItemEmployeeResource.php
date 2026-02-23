<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WasteDamageReportItemEmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'waste_damage_report_item_id' => $this->waste_damage_report_item_id,
            'cashier_id' => $this->cashier_id,
            'quantity_accountable' => (float) $this->quantity_accountable,
            'employee_name' => $this->whenLoaded('cashier', fn () => $this->cashier?->name),
            'branch_name' => $this->whenLoaded('cashier.branch', fn () => $this->cashier?->branch?->name),
        ];
    }
}
