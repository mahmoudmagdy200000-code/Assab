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
            'driver_name' => $this->driver_name,
            'driver_contact' => $this->driver_contact,
            'vehicle_number' => $this->vehicle_number,
            'arrival_time' => $this->arrival_time?->format('Y-m-d H:i:s'),
            'document_type' => $this->document_type,
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date?->format('Y-m-d'),
            'amount_before_tax' => $this->amount_before_tax,
            'vat_amount' => $this->vat_amount,
            'total_amount' => $this->total_amount,
            'payment_terms' => $this->payment_terms,
            'due_date' => $this->due_date?->format('Y-m-d'),
            'invoice_file' => $this->invoice_file,
            'total_items_received' => $this->total_items_received,
            'total_variance_items' => $this->total_variance_items,
            'status' => $this->status,
            'notes' => $this->notes,
            'purchase_order' => $this->when($this->purchaseOrder, [
                'id' => $this->purchaseOrder?->id,
                'order_number' => $this->purchaseOrder?->order_number,
            ]),
            'supplier' => $this->when($this->supplier, [
                'id' => $this->supplier?->id,
                'name' => $this->supplier?->name,
            ]),
            'items' => GoodsReceiptItemResource::collection($this->whenLoaded('items')),
            'variances' => GoodsReceiptVarianceResource::collection($this->whenLoaded('variances')),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
