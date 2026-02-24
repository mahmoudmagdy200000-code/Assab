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
        $isBranchManager = $this->branch_manager_id !== null;

        return [
            'id' => $this->id,
            'waste_damage_report_item_id' => $this->waste_damage_report_item_id,
            'cashier_id' => $this->cashier_id,
            'branch_manager_id' => $this->branch_manager_id,
            'is_me' => $isBranchManager,
            'quantity_accountable' => (float) $this->quantity_accountable,
            'employee_name' => $isBranchManager
                ? 'Me (Branch Manager)'
                : $this->whenLoaded('cashier', fn () => $this->cashier?->name),
            'branch_name' => $this->whenLoaded('cashier.branch', fn () => $this->cashier?->branch?->name),
        ];
    }
}
