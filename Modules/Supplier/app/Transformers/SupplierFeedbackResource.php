<?php

namespace Modules\Supplier\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Branch\Transformers\BranchResource;

class SupplierFeedbackResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'purchase_order_id' => $this->purchase_order_id,
            'branch_id' => $this->branch_id,
            'rating' => (float) $this->rating,
            'quality_feedback' => $this->quality_feedback,
            'delivery_satisfaction' => $this->delivery_satisfaction,
            'improvement_insights' => $this->improvement_insights,
            'branch' => $this->whenLoaded('branch', function () {
                return $this->branch ? new BranchResource($this->branch) : null;
            }),
            'purchase_order' => $this->whenLoaded('purchaseOrder', function () {
                return [
                    'id' => $this->purchaseOrder->id,
                    'order_number' => $this->purchaseOrder->order_number,
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
