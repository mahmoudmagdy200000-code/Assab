<?php

namespace Modules\BranchManagers\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class Cashier extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
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
            'activated_at' => $this->activated_at,
            'deactivated_at' => $this->deactivated_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
