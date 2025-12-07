<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'status' => $this->status,
            'document_type' => $this->document_type?->value,
            
            // Purchase Order
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn() => new PurchaseOrderResource($this->purchaseOrder)),
            
            // Delivery details
            'delivery_details' => [
                'driver_name' => $this->driver_name,
                'driver_contact' => $this->driver_contact,
                'driver_image' => $this->driver_image,
                'vehicle_number' => $this->vehicle_number,
                'arrival_time' => $this->arrival_time?->format('Y-m-d H:i:s'),
                'delivery_address' => $this->delivery_address,
                'delivery_notes' => $this->delivery_notes,
            ],
            
            // Inspection summary
            'inspection_summary' => [
                'total_items_expected' => $this->total_items_expected,
                'total_items_received' => $this->total_items_received,
                'quantity_variances' => $this->quantity_variances,
                'quality_variances' => $this->quality_variances,
                'has_variances' => $this->has_variances,
            ],
            
            // Financial
            'financial_summary' => [
                'expected_amount' => (float) $this->expected_amount,
                'received_amount' => (float) $this->received_amount,
                'variance_amount' => (float) $this->variance_amount,
            ],
            
            // Timestamps
            'inspection_started_at' => $this->inspection_started_at?->format('Y-m-d H:i:s'),
            'inspection_completed_at' => $this->inspection_completed_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            
            // Flags
            'is_draft' => $this->is_draft,
            'is_completed' => $this->is_completed,
            
            // Related data
            'items' => GoodsReceiptItemResource::collection($this->whenLoaded('items')),
            'invoice' => $this->whenLoaded('invoice', fn() => new InvoiceResource($this->invoice)),
            'variances' => VarianceResource::collection($this->whenLoaded('variances')),
        ];
    }
}

