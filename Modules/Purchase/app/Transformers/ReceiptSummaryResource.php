<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchase\Support\PurchaseFileHelper;

class ReceiptSummaryResource extends JsonResource
{
    public function toArray($request): array
    {
        $invoiceFile = null;
        if ($this->invoice?->file_path) {
            $inv = $this->invoice;
            $invoiceFile = PurchaseFileHelper::toApiShape([
                'id' => $inv->id,
                'file_name' => $inv->file_name,
                'file_type' => $inv->file_type,
                'file_size' => $inv->file_size,
                'file_path' => $inv->file_path,
                'uploaded_at' => $inv->created_at?->format('Y-m-d H:i:s'),
            ]);
        }

        return [
            'inspection_summary' => [
                'number_of_items_received' => $this->total_items_received,
                'number_of_quantity_variances' => $this->quantity_variances,
                'total_amount' => (float) $this->received_amount,
                'driver_name' => $this->driver_name,
                'contact_number' => $this->driver_contact,
                'vehicle_number' => $this->vehicle_number,
                'arrival_time' => $this->arrival_time?->format('Y-m-d H:i:s'),
            ],
            'goods_inspection' => GoodsReceiptItemResource::collection($this->whenLoaded('items')),
            'document_summary' => [
                'document_type' => $this->document_type?->value,
                'invoice_details' => $this->when($this->invoice, [
                    'invoice_number' => $this->invoice->invoice_number,
                    'invoice_date' => $this->invoice->invoice_date?->format('Y-m-d'),
                    'supplier_name' => $this->purchaseOrder->supplier?->name,
                    'file' => $invoiceFile,
                ]),
            ],
            'variance_summary' => VarianceResource::collection($this->whenLoaded('variances')),
            'financial_summary' => $this->when($this->invoice, [
                'amount_before_tax' => (float) $this->invoice->amount_before_tax,
                'vat' => (float) $this->invoice->tax_amount,
                'total_amount' => (float) $this->invoice->total_amount,
            ]),
        ];
    }
}
