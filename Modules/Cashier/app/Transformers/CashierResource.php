<?php

namespace Modules\Cashier\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Branch\Transformers\BranchResource;
use Modules\BranchManagers\Transformers\BranchManagerResource;

class CashierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'image' => $this->image,
            'branch_id' => $this->branch_id,
            'status' => $this->status,
            'created_by' => $this->created_by,

            
            'branch' => $this->whenLoaded('branch', new BranchResource($this->branch)),
            'creator' => $this->whenLoaded('creator', new BranchManagerResource($this->creator)),
            'shifts' => $this->whenLoaded('shifts', ShiftResource::collection($this->shifts)),

            'activated_at' => $this->activated_at,
            'deactivated_at' => $this->deactivated_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
