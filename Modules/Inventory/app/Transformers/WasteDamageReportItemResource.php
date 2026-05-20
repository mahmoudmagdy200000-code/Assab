<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WasteDamageReportItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource;
        $problemType = $item->problem_type;
        $causeOfDamage = $item->cause_of_damage;
        $reason = $item->reason;

        $productName = $this->whenLoaded('item', fn () => $this->item?->name)
            ?? $this->whenLoaded('purchaseOrderItem', fn () => $this->purchaseOrderItem?->item_name);
        $requiresPhoto = $problemType && $item->requiresPhoto();
        $hasPhoto = ! empty($this->photo_path);

        return [
            'id' => $this->id,
            'waste_damage_report_id' => $this->waste_damage_report_id,
            'branch_id' => $this->branch_id,
            'item_id' => $this->item_id,
            'purchase_order_item_id' => $this->purchase_order_item_id,
            'product_name' => $productName,
            'problem_type' => $problemType?->value,
            'problem_type_label' => $problemType?->label(),
            'cause_of_damage' => $causeOfDamage?->value,
            'cause_of_damage_label' => $causeOfDamage?->label(),
            'responsibility' => $causeOfDamage?->label() ?? null,
            'quantity' => (float) $this->quantity,
            'quantity_wasted' => (float) $this->quantity,
            'reason' => $reason?->value,
            'reason_label' => $reason?->label(),
            'unit' => $this->unit,
            'total_value' => (float) $this->total_value,
            'price_per_unit' => $this->price_per_unit ? (float) $this->price_per_unit : null,
            'justification_text' => $this->justification_text,
            'justification_note' => $this->justification_text,
            'photo_path' => $this->photo_path,
            'photo_url' => $this->photo_path ? asset('storage/'.ltrim($this->photo_path, '/')) : null,
            'explanatory_photo_url' => $this->photo_path ? asset('storage/'.ltrim($this->photo_path, '/')) : null,
            'explanatory_photo_warning' => $requiresPhoto && ! $hasPhoto ? 'Required For Damage >20 SAR' : null,
            'responsible_employees' => WasteDamageReportItemEmployeeResource::collection(
                $this->whenLoaded('responsibleEmployees')
            ),
            'item' => $this->whenLoaded('item', fn () => [
                'id' => $this->item->id,
                'name' => $this->item->name,
                'unit' => $this->item->unit ?? null,
            ]),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
