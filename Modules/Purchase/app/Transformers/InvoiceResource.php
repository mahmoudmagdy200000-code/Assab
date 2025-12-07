<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date?->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'payment_terms' => $this->payment_terms,
            'status' => $this->status,
            
            // Financial
            'amount_before_tax' => (float) $this->amount_before_tax,
            'tax_rate' => (float) $this->tax_rate,
            'tax_amount' => (float) $this->tax_amount,
            'total_amount' => (float) $this->total_amount,
            'deduction_amount' => (float) $this->deduction_amount,
            'final_amount' => (float) $this->final_amount,
            
            // Deduction details
            'deduction_reason' => $this->deduction_reason,
            'deduction_details' => $this->deduction_details,
            
            // File
            'file_url' => $this->file_url,
            'file_name' => $this->file_name,
            
            // Flags
            'is_overdue' => $this->is_overdue,
            'days_until_due' => $this->days_until_due,
            
            // Supplier
            'supplier' => $this->whenLoaded('supplier', fn() => new SupplierResource($this->supplier)),
            
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}

