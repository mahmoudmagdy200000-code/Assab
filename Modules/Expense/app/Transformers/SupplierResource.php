<?php

namespace Modules\Expense\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Supplier Resource
 */
class SupplierResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'tax_id' => $this->tax_id,
            'address' => $this->address,
            'is_active' => $this->is_active,
        ];
    }
}
