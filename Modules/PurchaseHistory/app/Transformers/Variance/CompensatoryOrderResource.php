<?php

namespace Modules\PurchaseHistory\Transformers\Variance;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompensatoryOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
