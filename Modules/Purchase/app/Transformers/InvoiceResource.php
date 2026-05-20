<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchase\Support\PurchaseFileHelper;

class InvoiceResource extends JsonResource
{
    public function toArray($request): array
    {
        $file = null;
        if ($this->file_path) {
            $file = PurchaseFileHelper::toApiShape([
                'id' => $this->id,
                'file_name' => $this->file_name,
                'file_type' => $this->file_type,
                'file_size' => $this->file_size,
                'file_path' => $this->file_path,
                'uploaded_at' => $this->created_at?->format('Y-m-d H:i:s'),
            ]);
        }

        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date?->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'payment_terms' => $this->payment_terms,
            'status' => $this->status,
            'amount_before_tax' => (float) $this->amount_before_tax,
            'tax_rate' => (float) $this->tax_rate,
            'tax_amount' => (float) $this->tax_amount,
            'total_amount' => (float) $this->total_amount,
            'deduction_amount' => (float) $this->deduction_amount,
            'final_amount' => (float) $this->final_amount,
            'deduction_reason' => $this->deduction_reason,
            'deduction_details' => $this->deduction_details,
            'file' => $file,
            'is_overdue' => $this->is_overdue,
            'days_until_due' => $this->days_until_due,
            'supplier' => $this->whenLoaded('supplier', fn () => new SupplierResource($this->supplier)),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
